<?php

/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpUndefinedMethodInspection */
/** @noinspection PhpUnusedParameterInspection */

declare(strict_types=1);

namespace Zaplog;

define("VERSION", "v1.2");

define("BASE_PATH", __DIR__);

require_once BASE_PATH . '/vendor/autoload.php';

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SlimRequestParams\BodyParameters;
use SlimRequestParams\QueryParameters;
use SlimRestApi\Infra\Db;
use SlimRestApi\Infra\Ini;
use SlimRestApi\Infra\MemcachedFunction;
use SlimRestApi\Middleware\Cacheable;
use SlimRestApi\Middleware\CacheablePrivate;
use SlimRestApi\Middleware\CliRequest;
use SlimRestApi\Middleware\NoCache;
use SlimRestApi\Middleware\NoStore;
use SlimRestApi\SlimRestApi;
use stdClass;
use Zaplog\Exception\UserException;
use Zaplog\Library\DoublePostProtection;
use Zaplog\Library\Methods;
use Zaplog\Library\TwoFactorAction;
use Zaplog\Middleware\Authentication;
use Zaplog\Plugins\ResponseFilter;

class Api extends SlimRestApi
{
    // --------------------------------------------------------------------
    // Allow plugins to filter responses before they're returned.
    // Plugins/ResponseFilters/<method>_<uri>__<pluginname>.php
    // --------------------------------------------------------------------

    static protected function response(Request $request, Response $response, stdClass $args, $data): Response
    {
        $filter = new ResponseFilter($request->getMethod(), $request->getUri()->getPath());
        $filter($request->getUri()->getPath(), $args, $data);
        return $response->withJson($data)
            ->withHeader("X-Content-Type-Options", "nosniff")
            ->withHeader("X-Frame-Options", "deny");
    }

    public function __construct()
    {
        parent::__construct();

        $this->group('/v1', function () {

            // -----------------------------------------
            // show the API homepage
            // -----------------------------------------

            $this->get("", function ($rq, $rp, $args): Response {

                // Initialization of session table
                Authentication::createSession("dummy@dummy.dummy");
                // Reset admin email from ini
                Db::execute("UPDATE channels SET userid=IF(LENGTH(userid)=0,MD5(:email),userid) WHERE id=1", [":email" => Ini::get("email_admin")]);
                // Make sure scheduler is running
                Db::execute("SET GLOBAL event_scheduler = ON");
                // No locking
                Db::execute("SET GLOBAL TRANSACTION ISOLATION LEVEL READ UNCOMMITTED");

                echo "<p>Repository: https://gitlab.com/zaplog/api-zaplog</p>";
                echo "<p>Manual: https://gitlab.com/zaplog/api-zaplog/-/wikis/Zaplog-manual</p>";
                echo "<h1>" . __CLASS__ . " version " . VERSION . "</h1>";
                echo "<table>";
                /** @noinspection PhpUndefinedFieldInspection */
                foreach ($this->router->getRoutes() as $route) {
                    foreach ($route->getMethods() as $method) {
                        echo "<tr><td>$method</td><td>{$route->getPattern()}</td></tr>";
                    }
                }
                echo "</table>";

                echo "<h2>APCu cache status</h2><pre>";
                print_r(apcu_cache_info(true));
                echo "</pre>";

                return $rp;
            });

            // ------------------------------------------
            // redirect to original or else archived page
            // ------------------------------------------

            $this->get("/goto", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                return $response->withStatus(307)->withHeader("Location",
                    (new MemcachedFunction)(["\ContentSyndication\ArchiveOrg", "originalOrClosest"], [$args->urlencoded], 24 * 60 * 60));
            })
                ->add(new QueryParameters(['{urlencoded:\urlencoded}']));

            // -----------------------------------------
            // Add the two factor handler to the server
            // -----------------------------------------

            $this->get("/2factor/{utoken:[[:alnum:]]{32}}", new TwoFactorAction);

            // -----------------------------------------
            // Distribute payments
            // -----------------------------------------

            $this->get("/payments/address", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                return self::response($request, $response, $args, Methods::getPaymentShares());
            });

            // -----------------------------------------------------
            // Authenticated methods can only be used with a session
            // token. We only support login through email 2-factor
            // -----------------------------------------------------

            $this->group('/sessions', function () {

                // -----------------------------------------------------
                // send a single-use auto-expiring log-in link to email
                // -----------------------------------------------------

                $this->post("", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    (new DoublePostProtection)($args, 20);
                    (new TwoFactorAction)
                        ->addAction('Middleware/Authentication.php', ['\Zaplog\Middleware\Authentication', 'createSession'], [$args->email])
                        ->createToken()
                        ->sendToken($args->email, $args->subject, $args->template, $args);
                    return self::response($request, $response, $args, true);
                })
                    ->add(new BodyParameters([
                        '{email:\email}',
                        '{subject:.{10,100}},Hier is jouw Zaplog login!',
                        '{template:\url},Content/nl.login.html',
                        '{*}' /* all {{variables}} used in template */,
                    ]))->add(new NoStore);

                // -----------------------------------------------------
                // change authenticated email, login again
                // -----------------------------------------------------

                $this->patch("", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    (new TwoFactorAction)
                        ->addAction('Middleware/Authentication.php', ['\Zaplog\Middleware\Authentication', 'updateIdentity'], [$args->email])
                        ->createToken()
                        ->sendToken($args->email, $args->subject, $args->template, $args);
                    return self::response($request, $response, $args, true);
                })
                    ->add(new NoStore)
                    ->add(new BodyParameters([
                        '{email:\email}',
                        '{subject:.{10,100}},Bevestig dit nieuwe email adres!',
                        '{template:\url},null',
                        '{*}' /* all {{variables}} used in template */,]))
                    ->add(new Authentication);

                // ----------------------------------------------------------------
                // Return the active channels (sessions) 'who's online'
                // ----------------------------------------------------------------

                $this->get("", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Db::fetchAll("SELECT * FROM activeusers"));
                })
                    ->add(new Cacheable(60/*sec*/));

                // -----------------------------------------------------
                // invalidate the session token in the HTTP header
                // -----------------------------------------------------

                $this->delete("", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    Authentication::deleteSession();
                    return self::response($request, $response, $args, null);
                })
                    ->add(new Authentication);

            });

            // -----------------------------------------------------
            // Returns the currently selected frontpage links
            // -----------------------------------------------------

            $this->get("/frontpage", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                return self::response($request, $response, $args, Methods::getFrontpage($args->count));
            })
                ->add(new QueryParameters(['{count:\int},18', '{datetime:\date},null',]))
                ->add(new Cacheable(60 * 10/*sec*/));

            // ----------------------------------------------------------------
            // Get reactions, forum style, returns the latest reactions
            // grouped with the 2 previous in the same thread / link
            // ----------------------------------------------------------------

            $this->get("/discussion", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                return self::response($request, $response, $args, Methods::getDiscussion(null, $args->offset, $args->count));
            })
                ->add(new NoCache)
                ->add(new QueryParameters(['{offset:\int},0', '{count:\int},8',]));

            $this->get("/discussion/channel/{id:[\d]{1,10}}", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                return self::response($request, $response, $args, Methods::getDiscussion($args->id, $args->offset, $args->count));
            })
                ->add(new NoCache)
                ->add(new QueryParameters(['{offset:\int},0', '{count:\int},8',]));


            // ------------------------------------------------
            // get the activity stream
            // ------------------------------------------------

            $this->get("/activities", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                return self::response($request, $response, $args, Methods::getActivityStream($args->offset, $args->count, $args->channel, $args->grouped));
            })
                ->add(new QueryParameters([
                    '{channel:\d{1,10}},null',
                    '{offset:\int},0',
                    '{count:\int},250',
                    '{grouped:\boolean},1',]))
                ->add(new Cacheable(60/*sec*/));

            // ------------------------------------------------
            // return all tags unique sorted
            // ------------------------------------------------

            $this->get("/index", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                return self::response($request, $response, $args, Db::fetchAll("SELECT * FROM tagindex"));
            })
                ->add(new Cacheable(60 * 10/*sec*/));

            // ------------------------------------------------
            // get some basic statistics
            // ------------------------------------------------

            $this->get("/statistics", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                return self::response($request, $response, $args, Db::fetch("SELECT * FROM statistics"));
            })
                ->add(new Cacheable(60 * 10/*sec*/));

            // ----------------------------------------------------------------
            // Return an URL's metadata and the duplicate URL's in de database
            // ----------------------------------------------------------------

            $this->get("/urlmetadata", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                return self::response($request, $response, $args, Methods::getMetadata($args->urlencoded));
            })
                // authenticated AND cached (authentication is just to prevent abuse)
                ->add(new Cacheable(60 * 10/*sec*/))
                ->add(new QueryParameters(['{urlencoded:\urlencoded}']))
                ->add(new Authentication);

            // ----------------------------------------------------------------
            // Channels show posts and activity for a specific user / email
            // Channels are automatically created on an email 2 factor login
            // ----------------------------------------------------------------

            $this->group('/channels', function () {

                // ----------------------------------------------------------------
                // Return all channels
                // ----------------------------------------------------------------

                $this->get("", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Db::fetchAll("SELECT * FROM channels
                        ORDER BY name LIMIT :offset,:count", [":offset" => $args->offset, ":count" => $args->count]));
                })
                    ->add(new QueryParameters(['{offset:\int},0', '{count:\int},2147483647,',]))
                    ->add(new Cacheable(60 * 10/*sec*/));

                // ----------------------------------------------------------------
                // Return single channel plus its tags and related channels
                // ----------------------------------------------------------------

                $this->get("/id/{id:[\d\w-]{1,55}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Methods::getSingleChannel($args->id));
                })
                    ->add(new NoCache)
                    ->add(new QueryParameters(['{offset:\int},0', '{count:\int},20']));

                // ----------------------------------------------------------------
                // Return top channels for given tag
                // ----------------------------------------------------------------

                $this->get("/tag/{tag:[\w-]{3,55}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Methods::getTopChannelsForTag($args->tag, $args->count));
                })
                    ->add(new QueryParameters(['{count:\int},10',]))
                    ->add(new Cacheable(60 * 10/*sec*/));

                // ----------------------------------------------------------------
                // Change channel properties of authenticated user's channel
                // ----------------------------------------------------------------

                $this->patch("", function (
                    Request  $request,
                    Response $response,
                    stdClass $channel): Response {
                    $channel->channelid = Authentication::getSession()->id;
                    (new DoublePostProtection)($channel);
                    return self::response($request, $response, $channel, Methods::patchChannel($channel));
                })
                    ->add(new NoStore)
                    ->add(new BodyParameters([
                        '{name:[.\w-]{3,55}}',
                        '{avatar:\url},null',
                        '{header:\url},null',
                        '{bio:\xtext},null',
                        '{language:[a-z]{2}},nl',
                        '{algorithm:(channel|voted|mixed|popular|all)},channel',
                        '{bitcoinaddress:\bitcoinaddress},null']))
                    ->add(new Authentication);

                // ----------------------------------------------------------------
                // Return channels top lists
                // ----------------------------------------------------------------

                $this->get("/active", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, [
                        "top10" => Db::fetchAll("SELECT * FROM topchannels LIMIT :count", [":count" => $args->count]),
                        "updated10" => Db::fetchAll("SELECT * FROM updatedchannels LIMIT :count", [":count" => $args->count]),
                        "discussing" => Db::fetchAll("SELECT channels.* FROM channels JOIN (
                                SELECT DISTINCT channelid FROM reactions ORDER BY id DESC LIMIT :count
                            ) AS reactions ON channels.id=reactions.channelid
                            GROUP BY channels.id DESC", [":count" => $args->count]),
                    ]);
                })
                    ->add(new QueryParameters(['{count:\int},10']))
                    ->add(new Cacheable(60/*sec*/));

            });

            $this->group('/links', function () {

                // ------------------------------------------------------------------------------
                // Post a blog concept, returns the blog with all automatic adjustments + id.
                // Subsequent posts with that id are considered updates of the concept
                // ------------------------------------------------------------------------------

                $this->post("", function (
                    Request  $request,
                    Response $response,
                    stdClass $link): Response {
                    (new UserException("Empty markdown"))(!empty($link->markdown));
                    (new UserException("Markdown exceeds 50k chars"))(strlen($link->markdown) < 50000);
                    (new DoublePostProtection)($link);
                    return self::response($request, $response, $link, Methods::postLink($link, Authentication::getSession()));
                })
                    ->add(new NoStore)
                    ->add(new BodyParameters([
                        '{id:\d+},null',    // empty will create new post (id is returned)
                        '{url:\url},null',
                        '{title:.{3,256}}',
                        '{markdown:\raw}',
                        '{copyright:(No Rights Apply|All Rights Reserved|No Rights Reserved \(CC0 1\.0\)|Some Rights Reserved \(CC BY-SA 4\.0\))},Some Rights Reserved \(CC BY-SA 4\.0\)',
                        '{tags[]:.{0,40}},null']))
                    ->add(new Authentication);

                // --------------------------------------------------
                // publish a blog by it's id
                // --------------------------------------------------

                $this->post("/id/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    $channelid = Authentication::getSession()->id;
                    return self::response($request, $response, $args, Methods::publishLink((int)$args->id, $channelid));
                })
                    ->add(new NoStore)
                    ->add(new Authentication);

                // ----------------------------------------------------------------
                // Return a link, including tags and related links
                // ----------------------------------------------------------------

                $this->get("/id/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Methods::getSingleLink($args->id));
                })
                    ->add(new NoCache)
                    ->add(new QueryParameters(['{http_referer:\url},null']));

                // --------------------------------------------------
                // delete a link by it's id
                // --------------------------------------------------

                $this->delete("/id/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    $channelid = Authentication::getSession()->id;
                    return self::response($request, $response, $args, (new UserException)(Db::execute("DELETE FROM links WHERE id =:id and channelid=:channelid",
                            [":id" => $args->id, ":channelid" => $channelid])->rowCount() > 0));
                })
                    ->add(new Authentication);

                // -----------------------------------------------------
                // Returns links for all channel
                // -----------------------------------------------------

                $this->get("", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Db::fetchAll("SELECT * FROM links WHERE published=TRUE ORDER BY id DESC LIMIT :offset,:count",
                        [":offset" => $args->offset, ":count" => $args->count]));
                })
                    ->add(new NoCache)
                    ->add(new QueryParameters(['{offset:\int},0', '{count:\int},20']));

                // -----------------------------------------------------
                // Returns links for a given channel
                // -----------------------------------------------------

                $this->get("/channel/{id:[\d\w-]{1,55}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Methods::getChannelLinks($args->id, (int)$args->offset, (int)$args->count));
                })
                    ->add(new QueryParameters(['{offset:\int},0', '{count:\int},20']))
                    ->add(new Cacheable(60/*sec*/));

                // ----------------------------------------------------------------
                // Return unpublished links for authenticated channel / user
                // ----------------------------------------------------------------

                $this->get("/unpublished", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    $channelid = Authentication::getSession()->id;
                    return self::response($request, $response, $args, Db::fetchAll("SELECT * FROM links
                        WHERE published=FALSE AND channelid=:channelid ORDER BY id DESC", [":channelid" => $channelid]));
                })
                    ->add(new CacheablePrivate)
                    ->add(new Authentication);

                // -----------------------------------------------------
                // Returns the top scoring links for a given tag
                // -----------------------------------------------------

                $this->get("/tag/{tag:[\w-]{3,55}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Db::fetchAll("SELECT 
                        links.id, links.channelid, links.createdatetime, links.updatedatetime, links.url, links.language,
                        links.title, links.copyright, links.description, links.image
                      FROM tags JOIN links ON tags.linkid=links.id 
                        WHERE tags.tag=:tag AND published=TRUE ORDER BY links.id DESC LIMIT :offset,:count",
                        [":tag" => $args->tag, ":offset" => $args->offset, ":count" => $args->count]));
                })
                    ->add(new Cacheable(60 * 10/*sec*/))
                    ->add(new QueryParameters(['{offset:\int},0', '{count:\int},20',]));
            });

            $this->group('/reactions', function () {

                // ----------------------------------------------------------------
                // Add a reaction, reactions can not be updated only deleted
                // ----------------------------------------------------------------

                $this->post("/link/{linkid:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $reaction): Response {
                    (new UserException("Empty markdown"))(!empty($reaction->markdown));
                    (new UserException("Markdown exceeds 50k chars"))(strlen($reaction->markdown) < 50000);
                    $reaction->channelid = Authentication::getSession()->id;
                    (new DoublePostProtection)($reaction);
                    return self::response($request, $response, $reaction, $reaction->preview ? Methods::previewReaction($reaction) : Methods::postReaction($reaction)->id);
                })
                    ->add(new NoStore)
                    ->add(new QueryParameters(['{preview:\boolean},0']))
                    ->add(new BodyParameters(['{markdown:\raw}']))
                    ->add(new Authentication);

                // ------------------------------------------------+
                // get reactions
                // ------------------------------------------------

                $this->get("/link/{linkid:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Methods::getReactionsForLink((int)$args->linkid));
                })->add(new NoCache);

                // ------------------------------------------------+
                // get reactions
                // ------------------------------------------------

                $this->get("", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Methods::getReactions($args->offset, $args->count));
                })
                    ->add(new QueryParameters(['{offset:\int},0', '{count:\int},20']))
                    ->add(new Cacheable(60/*sec*/));

                // ----------------------------------------------------------------
                // Delete a reaction, only your own reactions
                // ----------------------------------------------------------------

                $this->delete("/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    $channelid = Authentication::getSession()->id;
                    return self::response($request, $response, $args, (new UserException)(Db::execute("DELETE FROM reactions WHERE id=:id AND channelid=:channelid",
                            [":id" => $args->id, ":channelid" => $channelid]))->rowCount() > 0);
                })
                    ->add(new Authentication);

            });

            $this->group('/votes', function () {

                // ------------------------------------------------
                // toggle a vote
                // ------------------------------------------------

                $this->post("/link/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    $channelid = Authentication::getSession()->id;
                    Db::execute("CALL toggle_vote(:channelid,:linkid)", [":linkid" => $args->id, ":channelid" => $channelid]);
                    return self::response($request, $response, $args, Db::lastInsertId());
                })
                    ->add(new NoStore)
                    ->add(new Authentication);

            });

            $this->group('/reactionvotes', function () {

                // ------------------------------------------------
                // toggle a vote
                // ------------------------------------------------

                $this->post("/reaction/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    $channelid = Authentication::getSession()->id;
                    Db::execute("INSERT IGNORE INTO reactionvotes(reactionid,channelid)VALUES(:reactionid,:channelid)", [":reactionid" => $args->id, ":channelid" => $channelid]);
                    return self::response($request, $response, $args, Db::lastInsertId());
                })
                    ->add(new NoStore)
                    ->add(new Authentication);

            });

            $this->group('/tags', function () {

                // ------------------------------------------------
                // post space separated tags POST /tags/{id}/{tag}
                // ------------------------------------------------

                $this->post("/link/{linkid:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    $tags = Methods::sanitizeTags($args->tags);
                    $channelid = Authentication::getSession()->id;
                    return self::response($request, $response, $args, Methods::postTags((int)$args->linkid, $channelid, $tags));
                })
                    ->add(new NoStore)
                    ->add(new BodyParameters(['{tags[]:.{0,40}},null',]))
                    ->add(new Authentication);

                // ------------------------------------------------
                // get related tags
                // ------------------------------------------------

                $this->get("/related/{tag:[\w-]{3,50}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Methods::getRelatedTags($args->tag, $args->count));
                })
                    ->add(new QueryParameters(['{count:\int},20',]))
                    ->add(new Cacheable(60 * 10/*sec*/));

                // ------------------------------------------------
                // get the top trending tags
                // ------------------------------------------------

                $this->get("/active", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Methods::getTopTags($args->count));
                })
                    ->add(new QueryParameters(['{count:\int},20',]))
                    ->add(new Cacheable(60 * 10/*sec*/));

                // ------------------------------------------------
                // delete a tag, only delete your own tags
                // ------------------------------------------------

                $this->delete("/id/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    $channelid = Authentication::getSession()->id;
                    return self::response($request, $response, $args, (new UserException)(Db::execute("DELETE tags FROM tags WHERE id=:id and channelid=:channelid",
                            [":id" => $args->id, ":channelid" => $channelid])->rowCount() > 0));
                })
                    ->add(new Authentication);

            });

            // ------------------------------------------------
            // generic cronjob interfaces, not public
            // call: php Api.php /cronjobs/hour GET
            // ------------------------------------------------

            $this->group('/cronjobs', function () {

                $this->get("/minute", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return $response;
                })
                    ->add(new CliRequest(300));

                $this->get("/hour", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return $response;
                })
                    ->add(new CliRequest(300));

                $this->get("/day", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return $response;
                })
                    ->add(new CliRequest(300));

                $this->get("/month", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return $response;
                })
                    ->add(new CliRequest(300));

            });

        });
    }
}

// -------------------------------------------------
// Execute the server
// -------------------------------------------------

(new Api)->run();
