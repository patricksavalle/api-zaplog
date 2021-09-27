<?php

/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpUndefinedMethodInspection */
/** @noinspection PhpUnusedParameterInspection */

declare(strict_types=1);

namespace Zaplog {

    define("BASE_PATH", __DIR__);

    require_once BASE_PATH . '/vendor/autoload.php';
    require_once BASE_PATH . '/Middleware/Authentication.php';
    require_once BASE_PATH . '/Model/Links.php';
    require_once BASE_PATH . '/Model/FeedReader.php';
    require_once BASE_PATH . '/Library/TwoFactorAction.php';
    require_once BASE_PATH . '/Exception/ResourceNotFoundException.php';
    require_once BASE_PATH . '/Exception/EmailException.php';

    use stdClass;
    use SlimRestApi\Middleware\CliRequest;
    use SlimRestApi\Middleware\Memcaching;
    use SlimRequestParams\BodyParameters;
    use SlimRequestParams\QueryParameters;
    use SlimRestApi\Middleware\ReadOnly;
    use SlimRestApi\SlimRestApi;
    use Psr\Http\Message\ResponseInterface as Response;
    use Psr\Http\Message\ServerRequestInterface as Request;
    use SlimRestApi\Infra\Db;
    use Zaplog\Exception\ServerException;
    use Zaplog\Exception\UserException;
    use Zaplog\Library\TwoFactorAction;
    use Zaplog\Model\Activities;
    use Zaplog\Model\Channels;
    use Zaplog\Model\FeedReader;
    use Zaplog\Middleware\Authentication;
    use Zaplog\Model\Links;

    class Api extends SlimRestApi
    {
        public function __construct()
        {
            parent::__construct();

            // -----------------------------------------
            // Add the two factor handler to the server
            // -----------------------------------------

            $this->get("/2factor/{utoken:[[:alnum:]]{32}}", new TwoFactorAction);

            // -----------------------------------------
            // Distribute payments
            // -----------------------------------------

            $this->get("/payments/inaddress", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                return $response->withJson("<moneroaddress>");
            });

            // -----------------------------------------
            // show the API homepage
            // -----------------------------------------

            $this->get("/", function ($rq, $rp, $args): Response {
                echo "<p>See: <a href='https://github.com/zaplogv2/api.zaplog'>Github repository</a></p>";
                echo "<h1>" . __CLASS__ . "</h1>";
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

            // -----------------------------------------------------
            // Authenticated methods can only be used with a session
            // token. We only support login through email 2-factor
            // -----------------------------------------------------

            $this->group('/sessions', function () {

                // -----------------------------------------------------
                // send a single-use auto-expiring log-in link to email
                // -----------------------------------------------------

                $this->post("/{emailencoded:(?:[^%]|%[0-9A-Fa-f]{2})+}/{loginurlencoded:(?:[^%]|%[0-9A-Fa-f]{2})+}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    $email = urldecode($args->emailencoded);
                    $loginurl = urldecode($args->loginurlencoded);
                    (new UserException)(filter_var($email, FILTER_VALIDATE_EMAIL));
                    (new UserException)(filter_var($loginurl, FILTER_VALIDATE_URL));
                    $return = (new TwoFactorAction)
                        ->addAction('Middleware/Authentication.php', ['\Zaplog\Middleware\Authentication', 'createSession'], [$email])
                        ->createToken()
                        ->sendToken($email, $loginurl, "Your single-use login link", "Press the button to login", "Login");
                    return $response->withJson($return);
                });

                // -----------------------------------------------------
                // change authenticated email, login again
                // -----------------------------------------------------

                $this->patch("/{emailencoded:(?:[^%]|%[0-9A-Fa-f]{2})+}/{updateurlencoded:(?:[^%]|%[0-9A-Fa-f]{2})+}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    $email = urldecode($args->emailencoded);
                    $updateurl = urldecode($args->updateurlencoded);
                    (new UserException)(filter_var($email, FILTER_VALIDATE_EMAIL));
                    (new UserException)(filter_var($updateurl, FILTER_VALIDATE_URL));
                    $return = (new TwoFactorAction)
                        ->addAction('Middleware/Authentication.php', ['\Zaplog\Middleware\Authentication', 'updateIdentity'], [$email])
                        ->createToken()
                        ->sendToken($email, $updateurl, "Your email confirmation link", "Press the button to confirm the new email address", "Update");
                    return $response->withJson($return);
                })
                    ->add(new Authentication);

                // ----------------------------------------------------------------
                // Return the active channels (sessions) 'who's online'
                // ----------------------------------------------------------------

                $this->get("", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return $response->withJson(Db::fetchAll("SELECT * FROM activeusers"));
                })
                    ->add(new Memcaching(60/*sec*/))
                    ->add(new ReadOnly);

                // -----------------------------------------------------
                // invalidate the session token in the HTTP header
                // -----------------------------------------------------

                $this->delete("", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    Authentication::deleteSession();
                    return $response->withJson(null);
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
                return $response->withJson([
                    "trendinglinks" => Db::fetchAll("SELECT * FROM frontpage"),
                    "trendingtags" => Db::fetchAll("SELECT * FROM trendingtopics"),
                    "trendingchannels" => Db::fetchAll("SELECT * FROM trendingchannels")]);
            })
                ->add(new Memcaching(60 * 60/*sec*/))
                ->add(new ReadOnly);

            // ----------------------------------------------------------------
            // Get reactions, forum style, returns the latest reactions
            // grouped with the 2 previous in the same thread / link
            // ----------------------------------------------------------------

            $this->get("/discussion", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                return $response->withJson(Db::fetchAll("SELECT ranked_reactions.*, links.title FROM
                         (SELECT reactions.*,
                            @link_rank := IF(@current = linkid, @link_rank + 1, 1) AS link_rank,
                            @current := linkid
                            FROM reactions JOIN links ON reactions.linkid=links.id
                            ORDER BY updatedatetime DESC, linkid, reactions.id) AS ranked_reactions
                         LEFT JOIN links ON links.id=ranked_reactions.linkid AND link_rank=1
                         WHERE link_rank<=3
                         LIMIT :offset, :count",
                    [":offset" => $args->offset, ":count" => $args->count]));
            })
                ->add(new Memcaching(60/*sec*/))
                ->add(new ReadOnly)
                ->add(new QueryParameters([
                    '{offset:\int},0',
                    '{count:\int},20',
                ]));

            // ------------------------------------------------
            // get the activity stream
            // ------------------------------------------------

            $this->get("/activities", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                return $response->withJson(Activities::get($args->offset, $args->count, $args->channel, null));
            })
                ->add(new Memcaching(60/*sec*/))
                ->add(new ReadOnly)
                ->add(new QueryParameters([
                    '{channel:\d{1,10}},null',
                    '{offset:\int},0',
                    '{count:\int},250',
                ]));

            // ------------------------------------------------
            // return all tags unique sorted
            // ------------------------------------------------

            $this->get("/index", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                return $response->withJson(Db::fetchAll("SELECT * FROM tagindex"));
            })
                ->add(new Memcaching(10/*sec*/))
                ->add(new ReadOnly);

            // ------------------------------------------------
            // get some basic statistics
            // ------------------------------------------------

            $this->get("/statistics", function (
                Request  $request,
                Response $response,
                stdClass $args): Response {
                return $response->withJson(Db::fetch("SELECT * FROM statistics"));
            })
                ->add(new Memcaching(60/*sec*/))
                ->add(new ReadOnly);

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
                    return $response->withJson(Db::fetchAll("SELECT * FROM channels_public_view ORDER BY name LIMIT :offset,:count",
                        [":offset" => $args->offset, ":count" => $args->count]));
                })
                    ->add(new Memcaching(60/*sec*/))
                    ->add(new ReadOnly)
                    ->add(new QueryParameters([
                        '{offset:\int},0',
                        '{count:\int},2147483647,',
                    ]));

                // ----------------------------------------------------------------
                // Return single channel plus its tags and related channels
                // ----------------------------------------------------------------

                $this->get("/id/{id:[\d]{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return $response->withJson(Channels::getSingleChannel($args->id));
                })
                    ->add(new Memcaching(60/*sec*/))
                    ->add(new ReadOnly)
                    ->add(new QueryParameters([
                        '{offset:\int},0',
                        '{count:\int},20',
                    ]));


                // ----------------------------------------------------------------
                // Change channel properties of authenticated user's channel
                // ----------------------------------------------------------------

                $this->patch("", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return $response->withJson(Db::execute("UPDATE channels SET 
                        name=:name, avatar=:avatar, bio=:bio, moneroaddress=:moneroaddress WHERE id=:channelid", [
                        ":name" => $args->name,
                        ":avatar" => $args->avatar,
                        ":bio" => $args->bio,
                        ":moneroaddress" => $args->moneroaddress,
                        ":channelid" => Authentication::getSession()->id,
                    ])->rowCount());
                })
                    ->add(new Authentication)
                    ->add(new BodyParameters([
                        '{name:[.\w-]{3,55}}',
                        '{avatar:\url},null',
                        '{bio:\xtext},null',
                        '{moneroaddress:\moneroaddress},null',
                    ]));

                // ----------------------------------------------------------------
                // Return channels top lists
                // ----------------------------------------------------------------

                $this->get("/active", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return $response->withJson([
                        "top10" => Db::fetchAll("SELECT * FROM topchannels"),
                        "new10" => Db::fetchAll("SELECT * FROM newchannels"),
                        "updated10" => Db::fetchAll("SELECT * FROM trendingchannels")]);
                })
                    ->add(new Memcaching(60/*sec*/))
                    ->add(new ReadOnly);

            });

            $this->group('/links', function () {

                // ------------------------------------------------------
                // post from link, retrieve its metadata, add to user channel
                // ------------------------------------------------------

                $this->post("/{urlencoded:(?:[^%]|%[0-9A-Fa-f]{2})+}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    $url = urldecode($args->urlencoded);
                    (new UserException)(filter_var($url, FILTER_VALIDATE_URL));
                    return $response->withJson(Links::postLinkFromUrl((string)Authentication::getSession()->id, $url));
                })
                    ->add(new Authentication);

                // ----------------------------------------------------------------
                // Return a link, including tags and related links
                // ----------------------------------------------------------------

                $this->get("/id/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return $response->withJson(Links::getSingleLink($args->id));
                })
                    ->add(new QueryParameters(['{http_referer:\url},null']));

                // ----------------------------------------------------------------
                // Change post
                // ----------------------------------------------------------------

                $this->patch("/id/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return $response->withJson(Db::execute("UPDATE links SET 
                        published=:published, title=:title, description=:description 
                        WHERE id=:linkid AND channelid=:channelid",
                        [
                            ":published" => $args->published,
                            ":title" => $args->title,
                            ":description" => $args->description,
                            ":linkid" => $args->id,
                            ":channelid" => Authentication::getSession()->id,
                        ])->rowCount());
                })
                    ->add(new Authentication)
                    ->add(new BodyParameters([
                        '{title:[\w-]{3,55}},null',
                        '{description:\xtext},null',
                        '{published:\boolean},null',
                    ]));

                // --------------------------------------------------
                // delete a link by it's id
                // --------------------------------------------------

                $this->delete("/id/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    $channelid = Authentication::getSession()->id;
                    return $response->withJson((new UserException)(Db::execute("DELETE FROM links WHERE id =:id and channelid=:channelid",
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
                    return $response->withJson(Db::fetchAll("SELECT * FROM links ORDER BY id DESC LIMIT :offset,:count",
                        [":offset" => $args->offset, ":count" => $args->count]));
                })
                    ->add(new ReadOnly)
                    ->add(new QueryParameters([
                        '{offset:\int},0',
                        '{count:\int},20',
                    ]));

                // -----------------------------------------------------
                // Returns the top scoring links for a given tag
                // -----------------------------------------------------

                $this->get("/tag/{tag:[\w-]{3,55}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return $response->withJson(Db::fetchAll("SELECT links.* FROM tags JOIN links ON tags.linkid=links.id 
                        WHERE tags.tag=:tag ORDER BY links.id DESC LIMIT :offset,:count",
                        [":tag" => $args->tag, ":offset" => $args->offset, ":count" => $args->count]));
                })
                    ->add(new Memcaching(60/*sec*/))
                    ->add(new ReadOnly)
                    ->add(new QueryParameters([
                        '{offset:\int},0',
                        '{count:\int},20',
                    ]));

                // -----------------------------------------------------
                // Returns links for a given channel
                // -----------------------------------------------------

                $this->get("/channel/{id:[\w-]{3,55}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return $response->withJson(Db::fetchAll("SELECT * FROM links WHERE channelid=:channel ORDER BY id DESC LIMIT :offset,:count",
                        [":channel" => $args->id, ":offset" => $args->offset, ":count" => $args->count]));
                })
                    ->add(new ReadOnly)
                    ->add(new QueryParameters([
                        '{offset:\int},0',
                        '{count:\int},20',
                    ]));

            });

            $this->group('/comments', function () {

                // ----------------------------------------------------------------
                // Add a reaction
                // ----------------------------------------------------------------

                $this->post("/link/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    $channelid = Authentication::getSession()->id;
                    (new ServerException)(Db::execute("INSERT INTO reactions(linkid,channelid,text) VALUES (:linkid,:channelid,:text)",
                            [":linkid" => $args->id, ":channelid" => $channelid, ":text" => $args->text])->rowCount() > 0);
                    return $response->withJson(Db::lastInsertId());
                })
                    ->add(new BodyParameters(['{text:\xtext},null']))
                    ->add(new Authentication);

                // ----------------------------------------------------------------
                // Delete a reaction
                // ----------------------------------------------------------------

                $this->delete("/link/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    $channelid = Authentication::getSession()->id;
                    return $response->withJson((new UserException)(Db::execute("DELETE FROM reactions WHERE linkid=:linkid AND channelid=:channelid",
                            [":linkid" => $args->id, ":channelid" => $channelid])->rowCount() > 0));
                })
                    ->add(new Authentication);

            });

            $this->group('/votes', function () {

                // ------------------------------------------------
                // add a reaction
                // ------------------------------------------------

                $this->post("/link/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    $channelid = Authentication::getSession()->id;
                    (new ServerException)(Db::execute("INSERT INTO votes(linkid,channelid) VALUES (:linkid,:channelid)",
                            [":linkid" => $args->id, ":channelid" => $channelid])->rowCount() > 0);
                    return $response->withJson(Db::lastInsertId());
                })
                    ->add(new Authentication);

                $this->delete("/link/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    $channelid = Authentication::getSession()->id;
                    return $response->withJson((new UserException)(Db::execute("DELETE FROM votes WHERE linkid=:linkid AND channelid=:channelid",
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
                    $channelid = Authentication::getSession()->id;
                    (new ServerException)(Db::execute("INSERT INTO tags(linkid,channelid,tag) VALUES(:id,:channelid,:tag)",
                            [":id" => $args->id, ":tag" => $args->tag, ":channelid" => $channelid,])->rowCount() > 0);
                    return $response->withJson(Db::lastInsertId());
                })
                    ->add(new Authentication);

                // ------------------------------------------------
                // get the top trending tags
                // ------------------------------------------------

                $this->get("/active", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    return $response->withJson([
                        "top" => Db::fetchAll("SELECT * FROM toptopics"),
                        "new" => Db::fetchAll("SELECT * FROM newtopics"),
                        "trending" => Db::fetchAll("SELECT * FROM trendingtopics"),
                    ]);
                })
                    ->add(new Memcaching(60/*sec*/))
                    ->add(new ReadOnly);

                // ------------------------------------------------
                // delete a tag, only delete your own tags
                // ------------------------------------------------

                $this->delete("/id/{id:\d{1,10}}", function (
                    Request  $request,
                    Response $response,
                    stdClass $args): Response {
                    $channelid = Authentication::getSession()->id;
                    return $response->withJson((new ServerException)(Db::execute("DELETE tags FROM tags WHERE id=:id and channelid=:channelid",
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
                    (new FeedReader)->refreshAllFeeds();
                    return $response;
                });

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