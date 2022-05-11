<?php

/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpUndefinedMethodInspection */
/** @noinspection PhpUnusedParameterInspection */

declare(strict_types=1);

namespace Zaplog;

define("VERSION", "v1.8");

define("BASE_PATH", __DIR__);

require_once BASE_PATH . '/vendor/autoload.php';

use ContentSyndication\HtmlMetadata;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SlimRequestParams\BodyParameters;
use SlimRequestParams\QueryParameters;
use SlimRequestParams\RequestHeaders;
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
use Zaplog\Exception\ResourceNotFoundException;
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

            $this->get("/convert", function ($rq, $rp, $args): Response {

                $links = Db::fetchAll("SELECT title,id FROM links");
                foreach ($links as $link) {
                    $new_title = html_entity_decode($link->title, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
                    if ($link->title !== $new_title) {
                        Db::execute("UPDATE links SET title=:title WHERE id=:id", [":title" => $new_title, ":id" => $link->id]);
                    }
                }

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

            // -----------------------------------------------------
            // Channel 1 serves as the configration / master channel
            // -----------------------------------------------------

            $this->get("/config", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                $email = ini::get("email_admin");
                return self::response($request, $response, $args,
                    Db::fetch("SELECT name, language, algorithm, avatar AS logo, bio AS description, '$email' AS email, header, theme FROM channels WHERE id=1"));
            })
                ->add(new QueryParameters([]));

            // -----------------------------------------
            // Add the two factor handler to the server
            // -----------------------------------------

            $this->get("/2factor/{utoken:[[:alnum:]]{32}}", new TwoFactorAction)->add(new QueryParameters([]));

            // -----------------------------------------
            // Distribute payments
            // -----------------------------------------

            $this->get("/payments/address", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                return self::response($request, $response, $args, Methods::getPaymentShares());
            })
                ->add(new QueryParameters([]));

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
                        ->addAction('Library/Methods.php', ['\Zaplog\Library\Methods', 'createSession'], [$args->email, $args->article_markdown])
                        ->createToken()
                        ->sendToken($args->email, $args->subject, $args->template, $args);
                    return self::response($request, $response, $args, true);
                })
                    ->add(new QueryParameters([]))
                    ->add(new BodyParameters([
                        '{email:\email}',
                        '{subject:.{10,100}},Hier is jouw Zaplog login!',
                        '{template:\url},Content/nl.login.html',
                        '{article_markdown:\raw},null',
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
                    ->add(new QueryParameters([]))
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
                    ->add(new QueryParameters([]))
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
                    ->add(new QueryParameters([]))
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

            // -----------------------------------------------------
            // Returns the currently selected frontpage links
            // -----------------------------------------------------

            $this->get("/archivepage", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                assert($args->count < 100);
                return self::response($request, $response, $args, Methods::getArchivePage($args->offset, $args->count, $args->search));
            })
                ->add(new Cacheable(60))
                ->add(new QueryParameters([
                    '{offset:\int},0',
                    '{count:\int},20',
                    '{search:.+},null',
                ]));

            // ------------------------------------------------
            // get the activity stream
            // ------------------------------------------------

            $this->get("/activities", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                assert($args->count <= 250);
                return self::response($request, $response, $args, Methods::getActivityStream($args->offset, $args->count, $args->channel));
            })
                ->add(new QueryParameters([
                    '{channel:[\w-]{3,50}},null',
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
                ->add(new QueryParameters([]))
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
                ->add(new QueryParameters([]))
                ->add(new Cacheable(60 * 10/*sec*/));

            // ----------------------------------------------------------------
            // Return an URL's metadata and the duplicate URL's in de database
            // ----------------------------------------------------------------

            $this->get("/urlmetadata", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                return self::response($request, $response, $args, (new HtmlMetadata)($args->urlencoded));
            })
                // authenticated AND cached (authentication is just to prevent abuse)
                ->add(new Cacheable(60 * 60/*sec*/))
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

                $this->get("/id/{id:[\w-]{3,55}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Methods::getSingleChannel($args->id));
                })
                    ->add(new QueryParameters([]))
                    ->add(new NoCache);

                // ----------------------------------------------------------------
                // Return top channels for given tag
                // ----------------------------------------------------------------

                $this->get("/tag/{tag:[\w-]{3,55}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    assert($args->count < 100);
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
                    ->add(new QueryParameters([]))
                    ->add(new BodyParameters([
                        '{name:[\w-]{3,55}}',
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

                // ----------------------------------------------------------------
                // Return channel members
                // ----------------------------------------------------------------

                $this->get("/members", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    assert($args->count < 100);
                    return self::response($request, $response, $args,
                        Db::fetchAll("SELECT name,avatar,channelmembers.createdatetime,reputation FROM channelmembers JOIN channels ON channelmembers.memberid=channels.id WHERE channelid=:id",
                            [":id" => Authentication::getSession()->id]));
                })
                    ->add(new NoStore)
                    ->add(new QueryParameters(['{offset:\int},0', '{count:\int},20',]))
                    ->add(new Authentication);

                // ----------------------------------------------------------------
                // Attach a member to a channel, through email 2FA
                // ----------------------------------------------------------------

                $this->post("/members", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    (new DoublePostProtection)($args, 20);
                    (new TwoFactorAction)
                        ->addAction('Library/Methods.php', ['\Zaplog\Library\Methods', 'createMemberSession'], [$args->email, Authentication::getSession()->id])
                        ->createToken()
                        ->sendToken($args->email, $args->subject, $args->template, $args);
                    return self::response($request, $response, $args, true);
                })
                    ->add(new NoStore)
                    ->add(new QueryParameters([]))
                    ->add(new BodyParameters([
                        '{email:\email}',
                        '{subject:.{10,100}},Bevestig jouw channel lidmaatschap!',
                        '{template:\url},Content/nl.login.html',
                        '{*}' /* all {{variables}} used in template */]))
                    ->add(new Authentication);

                // ----------------------------------------------------------------
                // Revokes authenticated-channels' membership from a channel
                // ----------------------------------------------------------------

                $this->delete("/memberships/{channelid:[\w-]{3,55}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, (new ResourceNotFoundException("Membership not found: " . $args->channelid))(
                            Db::execute("DELETE FROM channelmembers WHERE channelid=(SELECT id FROM channels WHERE name=:channelid) AND memberid=:memberid",
                                [":memberid" => Authentication::getSession()->id, ":channelid" => $args->channelid]))->rowCount() > 0);
                })
                    ->add(new NoStore)
                    ->add(new QueryParameters([]))
                    ->add(new Authentication);

                // ----------------------------------------------------------------
                // Revokes authenticated-channels' membership from a channel
                // ----------------------------------------------------------------

                $this->delete("/members/{memberid:[\w-]{3,55}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, (new ResourceNotFoundException("Membership not found: " . $args->memberid))(
                            Db::execute("DELETE FROM channelmembers WHERE channelid=:channelid AND memberid=(SELECT id FROM channels WHERE name=:memberid1 OR userid=MD5(:memberid2))",
                                [":channelid" => Authentication::getSession()->id, ":memberid1" => $args->memberid, ":memberid2" => $args->memberid]))->rowCount() > 0);
                })
                    ->add(new NoStore)
                    ->add(new QueryParameters([]))
                    ->add(new Authentication);
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
                    (new DoublePostProtection)($link);
                    return self::response($request, $response, $link, Methods::postLink($link, Authentication::getSession()));
                })
                    ->add(new NoStore)
                    ->add(new QueryParameters([]))
                    ->add(new BodyParameters([
                        '{id:\d+},null',    // empty will create new post (id is returned)
                        '{markdown:\raw}',
                        '{copyright:(No Rights Apply|All Rights Reserved|No Rights Reserved \(CC0 1\.0\)|Some Rights Reserved \(CC BY-SA 4\.0\))},Some Rights Reserved (CC BY-SA 4.0)',
                        '{membersonly:\int},0',
                        '{tags[]:.{0,40}},null']))
                    ->add(new Authentication);

                // --------------------------------------------------
                // publish a blog by it's id
                // --------------------------------------------------

                $this->post("/id/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $link): Response {
                    $channelid = Authentication::getSession()->id;
                    return self::response($request, $response, $link, Methods::publishLink((int)$link->id, $channelid, (bool)$link->reactionsallowed));
                })
                    ->add(new NoStore)
                    ->add(new QueryParameters(['{reactionsallowed:\boolean},1']))
                    ->add(new Authentication);

                // ----------------------------------------------------------------
                // Return a link, including tags and related links
                // ----------------------------------------------------------------

                $this->get("/id/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Methods::getSingleLink((int)$args->id, $args->XChannelName));
                })
                    ->add(new NoCache)
                    ->add(new RequestHeaders(['{XChannelName:[\w-]{3,55}},null'])) // TODO: hack, replace by JWT
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
                    ->add(new QueryParameters([]))
                    ->add(new Authentication);

                // -----------------------------------------------------
                // Returns links for all channel
                // -----------------------------------------------------

                $this->get("", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    assert($args->count < 250);
                    return self::response($request, $response, $args, Methods::getArchive($args->offset, $args->count));
                })
                    ->add(new NoCache)
                    ->add(new QueryParameters(['{offset:\int},0', '{count:\int},20',]));

                // -----------------------------------------------------
                // Returns links for a given channel
                // -----------------------------------------------------

                $this->get("/channel/{id:[\w-]{3,55}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    assert($args->count < 250);
                    return self::response($request, $response, $args, Methods::getChannelLinks($args->id, $args->offset, $args->count));
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
                    ->add(new QueryParameters([]))
                    ->add(new CacheablePrivate)
                    ->add(new Authentication);

                // -----------------------------------------------------
                // Returns the top scoring links for a given tag
                // -----------------------------------------------------

                $this->get("/tag/{tag:[\w-]{3,55}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    assert($args->count < 100);
                    return self::response($request, $response, $args, Db::fetchAll("SELECT 
                            links.id, links.channelid, links.createdatetime, links.updatedatetime, links.language,
                            links.title, links.copyright, links.description, links.image
                        FROM tags JOIN links ON tags.linkid=links.id 
                        WHERE tags.tag=:tag AND published=TRUE ORDER BY createdatetime DESC LIMIT :offset,:count",
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
                    if (!$reaction->preview) (new DoublePostProtection)($reaction);
                    return self::response($request, $response, $reaction, $reaction->preview ? Methods::previewReaction($reaction) : Methods::postReaction($reaction)->id);
                })
                    ->add(new NoStore)
                    ->add(new QueryParameters(['{preview:\boolean},0']))
                    ->add(new BodyParameters(['{markdown:\raw}']))
                    ->add(new Authentication);

                // ------------------------------------------------+
                // get reactions
                // ------------------------------------------------

                $this->get("", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    assert($args->count < 250);
                    return self::response($request, $response, $args, Methods::getReactions($args->offset, $args->count));
                })
                    ->add(new QueryParameters(['{offset:\int},0', '{count:\int},20']));

                $this->get("/link/{linkid:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Methods::getReactionsForLink((int)$args->linkid));
                })
                    ->add(new QueryParameters([]))
                    ->add(new NoCache);

                $this->get("/channel/{channel:[\w-]{3,55}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    assert($args->count < 250);
                    return self::response($request, $response, $args, Methods::getReactions($args->offset, $args->count, $args->channel));
                })
                    ->add(new QueryParameters(['{offset:\int},0', '{count:\int},20']));

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
                    ->add(new QueryParameters([]))
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
                    ->add(new Authentication)
                    ->add(new QueryParameters([]));

            });

            $this->group('/reactionvotes', function () {

                // ------------------------------------------------
                // insert a reaction vote
                // ------------------------------------------------

                $this->post("/reaction/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    $channelid = Authentication::getSession()->id;
                    Db::execute("CALL toggle_reactionvote(:channelid,:reactionid)", [":reactionid" => $args->id, ":channelid" => $channelid]);
                    return self::response($request, $response, $args, Db::lastInsertId());
                })
                    ->add(new NoStore)
                    ->add(new Authentication)
                    ->add(new QueryParameters([]));

            });

            $this->group('/tags', function () {

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
