<?php

/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpUndefinedMethodInspection */
/** @noinspection PhpUnusedParameterInspection */

declare(strict_types=1);

namespace Zaplog {

    define("VERSION", "v0.96");

    define("BASE_PATH", __DIR__);

    require_once BASE_PATH . '/vendor/autoload.php';

    use ContentSyndication\Text;
    use SlimRestApi\Infra\Ini;
    use SlimRestApi\Infra\MemcachedFunction;
    use stdClass;
    use SlimRestApi\Middleware\CliRequest;
    use SlimRestApi\Middleware\Memcaching;
    use SlimRequestParams\BodyParameters;
    use SlimRequestParams\QueryParameters;
    use SlimRestApi\SlimRestApi;
    use Psr\Http\Message\ResponseInterface as Response;
    use Psr\Http\Message\ServerRequestInterface as Request;
    use SlimRestApi\Infra\Db;
    use Zaplog\Exception\UserException;
    use Zaplog\Library\Methods;
    use Zaplog\Library\TwoFactorAction;
    use Zaplog\Library\Avatar;
    use Zaplog\Middleware\Authentication;
    use Zaplog\Plugins\MetadataParser;
    use Zaplog\Plugins\ParsedownFilter;
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
            return $response->withJson($data);
        }

        public function __construct()
        {
            parent::__construct();

            $this->add(function (Request $request, Response $response, callable $next): Response {
                return $next($request, $response)->withHeader("X-Api-Version", VERSION);
            });

            // -----------------------------------------
            // show the API homepage
            // -----------------------------------------

            $this->get("/", function ($rq, $rp, $args): Response {

                //(new ZaplogImport)();

                // Initialization
                Authentication::createSession("dummy@dummy.dummy");
                Db::execute("UPDATE channels SET userid=IF(LENGTH(userid)=0,MD5(:email),userid) WHERE id=1", [":email" => Ini::get("email_admin")]);
                Db::execute("SET GLOBAL event_scheduler = ON");
                Db::execute("SET GLOBAL TRANSACTION ISOLATION LEVEL READ UNCOMMITTED");

                echo "<p>Repositories: https://gitlab.com/zaplog/api-zaplog</p>";
                echo "<h1>" . __CLASS__ . " version " . VERSION . "</h1>";
                echo "<table>";
                /** @noinspection PhpUndefinedFieldInspection */
                foreach ($this->router->getRoutes() as $route) {
                    foreach ($route->getMethods() as $method) {
                        echo "<tr><td>$method</td><td>{$route->getPattern()}</td></tr>";
                    }
                }
                echo "</table>";
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
                    (new TwoFactorAction)
                        ->addAction('Middleware/Authentication.php', ['\Zaplog\Middleware\Authentication', 'createSession'], [$args->email])
                        ->createToken()
                        ->sendToken($args->email, $args->subject, $args->template, $args);
                    return self::response($request, $response, $args, true);
                })
                    ->add(new BodyParameters([
                        '{email:\email}',
                        '{subject:.{10,100}},Hier is jouw Zaplog login!',
                        '{template:\url},null',
                        '{*}' /* all {{variables}} used in template */,
                    ]));

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
                    ->add(new Memcaching(60/*sec*/));

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
                ->add(new QueryParameters(['{count:\int},25', '{datetime:\date},null',]))
                ->add(new Memcaching(60 * 60/*sec*/));

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
                ->add(new QueryParameters([
                    '{offset:\int},0',
                    '{count:\int},8',
                ]));

            $this->get("/discussion/channel/{id:[\d]{1,10}}", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                return self::response($request, $response, $args, Methods::getDiscussion($args->id, $args->offset, $args->count));
            })
                ->add(new QueryParameters([
                    '{offset:\int},0',
                    '{count:\int},8',
                ]));


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
                    '{grouped:\boolean},true',]))
                ->add(new Memcaching(60/*sec*/));

            // ------------------------------------------------
            // return all tags unique sorted
            // ------------------------------------------------

            $this->get("/index", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                return self::response($request, $response, $args, Db::fetchAll("SELECT * FROM tagindex"));
            })
                ->add(new Memcaching(10/*sec*/));

            // ------------------------------------------------
            // get some basic statistics
            // ------------------------------------------------

            $this->get("/statistics", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                return self::response($request, $response, $args, Db::fetch("SELECT * FROM statistics"));
            })
                ->add(new Memcaching(60/*sec*/));

            // --------------------------------------------------------
            // Preview comment or post text after parsing and filtering
            // --------------------------------------------------------

            $this->post("/postpreview", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                return self::response($request, $response, $args, [
                    "links.description" => (string)(new Text($args->markdown))->parseDown(new ParsedownFilter)->blurbify(),
                    "links.xtext" => (string)(new Text($args->markdown))->parseDown(new ParsedownFilter),
                    "reactions.description" => (string)(new Text($args->markdown))->parseDown()->blurbify(),
                    "reactions.xtext" => (string)(new Text($args->markdown))->parseDown(),
                ]);
            })
                ->add(new BodyParameters(['{markdown:\raw}']))
                ->add(new Authentication);

            // ----------------------------------------------------------------
            // Return an URL's metadata and the duplicate URL's in de database
            // ----------------------------------------------------------------

            $this->get("/urlmetadata", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                $metadata = MetadataParser::getMetadata($args->urlencoded);
                $duplicates = Db::fetchAll("SELECT * FROM links WHERE urlhash=MD5(:url)", [":url" => $metadata["url"]]);
                return self::response($request, $response, $args, ["metadata" => $metadata, "duplicaties" => $duplicates]);
            })
                ->add(new QueryParameters(['{urlencoded:\urlencoded}']))
                ->add(new Authentication);

            // ----------------------------------------------------------------
            // Channels show posts and activity for a specific user / email
            // Channels are automatically created on an email 2 factor login
            // ----------------------------------------------------------------

            $this->group('/channels', function () {

                // ----------------------------------------------------------------
                // Return all channels, only public fields
                // ----------------------------------------------------------------

                $this->get("", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Db::fetchAll("SELECT * FROM channels_public_view
                        ORDER BY name LIMIT :offset,:count", [":offset" => $args->offset, ":count" => $args->count]));
                })
                    ->add(new QueryParameters([
                        '{offset:\int},0',
                        '{count:\int},2147483647,',]))
                    ->add(new Memcaching(60/*sec*/));

                // ----------------------------------------------------------------
                // Return single channel plus its tags and related channels
                // ----------------------------------------------------------------

                $this->get("/id/{id:[\d]{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Methods::getSingleChannel($args->id));
                })
                    ->add(new QueryParameters([
                        '{offset:\int},0',
                        '{count:\int},20']))
                    ->add(new Memcaching(60/*sec*/));

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
                    ->add(new Memcaching(60/*sec*/));

                // ----------------------------------------------------------------
                // Change channel properties of authenticated user's channel
                // ----------------------------------------------------------------

                $this->patch("", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Db::execute("UPDATE channels SET 
                        name=:name, avatar=IFNULL(:avatar,avatar), bio=:bio, bitcoinaddress=:bitcoinaddress WHERE id=:channelid", [
                        ":name" => (new Text($args->name))->convertToAscii()->hyphenize(),
                        ":avatar" => empty($args->avatar) ? null : (new Avatar($args->avatar))->inlineBase64(),
                        ":bio" => $args->bio,
                        ":bitcoinaddress" => $args->bitcoinaddress,
                        ":channelid" => Authentication::getSession()->id,
                    ])->rowCount());
                })
                    ->add(new BodyParameters([
                        '{name:[.\w-]{3,55}}',
                        '{avatar:\url},null',
                        '{bio:\xtext},""',
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
                    ]);
                })
                    ->add(new QueryParameters(['{count:\int},10']))
                    ->add(new Memcaching(60/*sec*/));

            });

            $this->group('/links', function () {

                // ------------------------------------------------------
                // post from link, retrieve its metadata, add to user channel
                // ------------------------------------------------------

                $this->post("/link", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Methods::postLinkFromUrl((string)Authentication::getSession()->id, $args->link));
                })
                    ->add(new BodyParameters(['{link:\url}']))
                    ->add(new Authentication);

                // ------------------------------------------------------
                // post a blog
                // ------------------------------------------------------

                $this->post("", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    $args->channelid = Authentication::getSession()->id;
                    return self::response($request, $response, $args, Methods::postLink($args));
                })
                    ->add(new BodyParameters([
                        '{link:\url},null',
                        '{mimetype:[-\w.]+/[-\w.]+},null',
                        '{title:[\w-]{3,55}}',
                        '{markdown:\raw}',
                        '{language:[a-z]{2}}, null',
                        '{copyright:(No Rights Apply|All Rights Reserved|No Rights Reserved (CC0 1.0)|Some Rights Reserved (CC BY-SA 4.0)}, null',
                        '{image:\url},null']))
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
                    ->add(new QueryParameters(['{http_referer:\url},null']));

                // ----------------------------------------------------------------
                // Change post
                // ----------------------------------------------------------------

                $this->patch("/id/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Db::execute("UPDATE links SET 
                        published=:published, title=:title, description=:description 
                        WHERE id=:linkid AND channelid=:channelid", [
                        ":published" => $args->published,
                        ":title" => $args->title,
                        ":description" => $args->description,
                        ":linkid" => $args->id,
                        ":channelid" => Authentication::getSession()->id,
                    ])->rowCount());
                })
                    ->add(new BodyParameters([
                        '{title:[\w-]{3,55}},null',
                        '{description:\raw},null',
                        '{published:\boolean},null']))
                    ->add(new Authentication);

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
                    ->add(new QueryParameters([
                        '{offset:\int},0',
                        '{count:\int},20',
                    ]));

                // -----------------------------------------------------
                // Returns links for a given channel
                // -----------------------------------------------------

                $this->get("/channel/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Db::fetchAll("SELECT * FROM links WHERE channelid=:channel AND published=TRUE 
                        ORDER BY id DESC LIMIT :offset,:count", [":channel" => $args->id, ":offset" => $args->offset, ":count" => $args->count]));
                })
                    ->add(new QueryParameters([
                        '{offset:\int},0',
                        '{count:\int},20']))
                    ->add(new Memcaching(60/*sec*/));

                // -----------------------------------------------------
                // Returns the top scoring links for a given tag
                // -----------------------------------------------------

                $this->get("/tag/{tag:[\w-]{3,55}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Db::fetchAll("SELECT links.* FROM tags JOIN links ON tags.linkid=links.id 
                        WHERE tags.tag=:tag AND published=TRUE ORDER BY links.id DESC LIMIT :offset,:count",
                        [":tag" => $args->tag, ":offset" => $args->offset, ":count" => $args->count]));
                })
                    ->add(new Memcaching(60/*sec*/))
                    ->add(new QueryParameters([
                        '{offset:\int},0',
                        '{count:\int},20',
                    ]));

            });

            $this->group('/reactions', function () {

                // ----------------------------------------------------------------
                // Add a reaction, reactions can not be updated only deleted
                // ----------------------------------------------------------------

                $this->post("/link/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    $xtext = (string)(new Text($args->markdown))->stripTags()->parseDown();
                    (new UserException("Comment invalid or empty"))(strlen($xtext) > 0);
                    Db::execute("CALL insert_reaction(:channelid,:linkid,:markdown,:xtext,:description)", [
                        ":linkid" => $args->id,
                        ":channelid" => Authentication::getSession()->id,
                        ":markdown" => $args->markdown,
                        ":xtext" => $xtext,
                        ":description" => (new Text($xtext))->blurbify()]);
                    return self::response($request, $response, $args, true);
                })
                    ->add(new BodyParameters(['{markdown:\raw}']))
                    ->add(new Authentication);

                // ------------------------------------------------+
                // get reactions
                // ------------------------------------------------

                $this->get("/link/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Methods::getReactionsForLink((int)$args->id));
                });

                // ----------------------------------------------------------------
                // Delete a reaction, only your own reactions
                // ----------------------------------------------------------------

                $this->delete("/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    $channelid = Authentication::getSession()->id;
                    return self::response($request, $response, $args, Db::execute("DELETE FROM reactions WHERE id=:id AND channelid=:channelid",
                        [":id" => $args->id, ":channelid" => $channelid]));
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
                    ->add(new Authentication);

                // ------------------------------------------------
                // delete a vote
                // ------------------------------------------------

                $this->delete("/link/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    $channelid = Authentication::getSession()->id;
                    return self::response($request, $response, $args, (new UserException)(Db::execute("DELETE FROM votes WHERE linkid=:linkid AND channelid=:channelid",
                            [":linkid" => $args->id, ":channelid" => $channelid])->rowCount() > 0));
                })
                    ->add(new Authentication);

            });

            $this->group('/tags', function () {

                // ------------------------------------------------
                // post a single tag POST /tags/{id}/{tag}
                // ------------------------------------------------

                $this->post("/link/{id:\d{1,10}}/tag/{tag:[\w-]{3,50}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return self::response($request, $response, $args, Methods::postTags(Authentication::getSession()->id, (int)$args->id, [$args->tag]));
                })
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
                    ->add(new Memcaching(60/*sec*/));

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
                    ->add(new Memcaching(60/*sec*/));

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
        }
    }

    // -------------------------------------------------
    // Execute the server
    // -------------------------------------------------

    (new Api)->run();
}