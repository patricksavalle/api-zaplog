<?php

/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpUndefinedMethodInspection */
/** @noinspection PhpUnusedParameterInspection */

declare(strict_types=1);

namespace Zaplog {

    define("BASE_PATH", dirname(__FILE__));

    require_once BASE_PATH . '/vendor/autoload.php';
    require_once BASE_PATH . '/Middleware/Authentication.php';
    require_once BASE_PATH . '/Model/Links.php';
    require_once BASE_PATH . '/Model/FeedReader.php';
    require_once BASE_PATH . '/Library/TwoFactorAction.php';
    require_once BASE_PATH . '/Exception/ResourceNotFoundException.php';
    require_once BASE_PATH . '/Exception/EmailException.php';

    use SlimRestApi\Middleware\CliRequest;
    use SlimRestApi\Middleware\Memcaching;
    use stdClass;
    use Exception;
    use SlimRequestParams\BodyParameters;
    use SlimRequestParams\QueryParameters;
    use SlimRestApi\Middleware\ReadOnly;
    use SlimRestApi\SlimRestApi;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use SlimRestApi\Infra\Db;
    use Zaplog\Exception\EmailException;
    use Zaplog\Exception\ResourceNotFoundException;
    use Zaplog\Model\FeedReader;
    use Zaplog\Library\HtmlMetadata;
    use Zaplog\Library\TwoFactorAction;
    use Zaplog\Middleware\Authentication;
    use Zaplog\Model\Links;

    class Api extends SlimRestApi
    {
        /** @noinspection PhpUndefinedFieldInspection */
        public function __construct()
        {
            parent::__construct();

            // -----------------------------------------
            // show the API homepage
            // -----------------------------------------

            $this->get("/", function ($rq, $rp, $args): ResponseInterface {
                echo "<p>See: <a href='https://github.com/zaplogv2/api.zaplog'>Github repository</a></p>";
                echo "<h1>" . __CLASS__ . "</h1>";
                echo "<table>";
                foreach ($this->router->getRoutes() as $route) {
                    foreach ($route->getMethods() as $method) {
                        echo "<tr><td>$method</td><td>{$route->getPattern()}</td></tr>";
                    }
                }
                echo "</table>";
                return $rp;
            });

            // -----------------------------------------------------
            // The 2-factor hook, if you have a 2F token, you can
            // execute the action stored with the token
            // -----------------------------------------------------

            $this->get("/2factor/{utoken:[[:alnum:]]{32}}", new TwoFactorAction);

            // -----------------------------------------------------
            // Authenticated methods can only be used with a session
            // token. We only support login through email 2-factor
            // -----------------------------------------------------

            $this->group('/sessions', function () {

                // -----------------------------------------------------
                // send a single-use auto-expiring log-in link to email
                // uses an email template /login.html
                // -----------------------------------------------------

                $this->post("/{emailencoded:(?:[^%]|%[0-9A-Fa-f]{2})+}", function (
                    ServerRequestInterface $request,
                    ResponseInterface      $response,
                    stdClass               $args): ResponseInterface {
                    $email = urldecode($args->emailencoded);
                    $args->receiver = $email;
                    $action = new TwoFactorAction;
                    try {
                        $action
                            ->addAction('Middleware/Authentication.php', ['\Zaplog\Middleware\Authentication', 'createSession'], [$email])
                            ->createToken()
                            ->sendToken($args);
                        return $response->withJson(null);
                    } catch (EmailException $e) {
                        // TODO remove in production
                        return $response->withJson($action->utoken);
                    }
                })
                    ->add(new BodyParameters([
                        '{subject:.{1,128}},Your single-use login link',
                        '{button:.{1,30}},Login',
                        '{button_url:\url},null',
                        '{template_url:\url},login.html',
                        '{*}',
                    ]));

                // ----------------------------------------------------------------
                // Return the active channels (sessions) 'who's online'
                // ----------------------------------------------------------------

                $this->get("", function (
                    ServerRequestInterface $request,
                    ResponseInterface      $response,
                    stdClass               $args): ResponseInterface {
                    return $response->withJson(Db::execute("SELECT * FROM whosonline")->fetchAll());
                })
                    ->add(new ReadOnly)
                    ->add(new Authentication);

                // -----------------------------------------------------
                // invalidate the session token in the HTTP header
                // -----------------------------------------------------

                $this->delete("", function (
                    ServerRequestInterface $request,
                    ResponseInterface      $response,
                    stdClass               $args): ResponseInterface {
                    Db::execute("DELETE FROM sessions WHERE token=:token", [":token" => Authentication::token()]);
                    return $response->withJson(null);
                })
                    ->add(new Authentication);

            });

            // ----------------------------------------------------------------
            // Channels show posts and activity for a specific user / email
            // Channels are automatically created on an email 2 factor login
            // ----------------------------------------------------------------

            $this->group('/channels', function () {

                // ----------------------------------------------------------------
                // Return all channels, only public fields
                // ----------------------------------------------------------------

                $this->get("", function (
                    ServerRequestInterface $request,
                    ResponseInterface      $response,
                    stdClass               $args): ResponseInterface {
                    return $response->withJson(Db::execute("SELECT * FROM channels_public_view ORDER BY name LIMIT :offset,:count",
                        [
                            ":offset" => $args->offset,
                            ":count" => $args->count,
                        ]
                    )->fetchAll());
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
                    ServerRequestInterface $request,
                    ResponseInterface      $response,
                    stdClass               $args): ResponseInterface {
                    $channel = Db::execute("SELECT * FROM channels_public_view WHERE id=:id", [":id" => $args->id])->fetch();
                    $populartags = Db::execute("SELECT tag, COUNT(tag) AS tagscount 
                        FROM tags JOIN links ON tags.linkid=links.id AND tags.channelid=links.channelid 
                        WHERE links.channelid=:channelid 
                        GROUP BY tag ORDER BY COUNT(tag) DESC LIMIT 10",
                        [":channelid" => $args->id])->fetchAll();
                    // TODO https://github.com/zaplogv2/api.zaplog/issues/12
                    $relatedlinks = Db::execute("SELECT 1", [])->fetchAll();
                    return $response->withJson(
                        [
                            "channel" => $channel,
                            "tags" => $populartags,
                            "related" => $relatedlinks,
                        ]
                    );
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
                    ServerRequestInterface $request,
                    ResponseInterface      $response,
                    stdClass               $args): ResponseInterface {
                    Db::execute("UPDATE channels SET 
                        name=IFNULL(:name,name), 
                        avatar=IFNULL(:avatar,avatar), 
                        bio=IFNULL(:bio,bio), 
                        feedurl=IFNULL(:feedurl,feedurl) WHERE id=:channelid",
                        [
                            ":name" => $args->name,
                            ":avatar" => $args->avatar,
                            ":bio" => $args->bio,
                            ":feedurl" => $args->feedurl,
                            ":channelid" => Authentication::token()->channelid,
                        ]);
                    return $response->withJson(null);
                })
                    ->add(new Authentication)
                    ->add(new BodyParameters([
                        '{name:[\w+]{3,55}},null',
                        '{feedurl:\url},null',
                        '{avatar:\url},null',
                        '{bio:.{0,255},null',
                    ]));

                // ----------------------------------------------------------------
                // Return channels top lists
                // ----------------------------------------------------------------

                $this->get("/active", function (
                    ServerRequestInterface $request,
                    ResponseInterface      $response,
                    stdClass               $args): ResponseInterface {
                    $top = Db::execute("SELECT * FROM channels_public_view ORDER BY reputation DESC LIMIT 10")->fetchAll();
                    $new = Db::execute("SELECT * FROM channels_public_view ORDER BY id DESC LIMIT 10")->fetchAll();
                    $updated = Db::execute("SELECT channels.* FROM channels_public_view AS channels
                        JOIN links ON links.channelid=channels.id
                        ORDER BY links.createdatetime DESC LIMIT 10")->fetchAll();
                    return $response->withJson(
                        [
                            "top10" => $top,
                            "new10" => $new,
                            "updated10" => $updated,
                        ]
                    );
                })
                    ->add(new Memcaching(60/*sec*/))
                    ->add(new ReadOnly);

            });

            // -----------------------------------------------------
            // Returns the currently selected frontpage links
            // -----------------------------------------------------

            $this->get("/frontpage", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                return $response->withJson(
                    [
                        "trendinglinks" => Db::execute("SELECT * FROM trendinglinks")->fetchAll(),
                        "trendingtags" => Db::execute("SELECT * FROM trendingtopics")->fetchAll(),
                        "trendingchannels" => Db::execute("SELECT * FROM trendingchannels")->fetchAll(),
                    ]
                );
            })
                ->add(new Memcaching(60/*sec*/))
                ->add(new ReadOnly);

            $this->group('/links', function () {

                // ------------------------------------------------------
                // add a link, retrieve its metadata, add to user channel
                // ------------------------------------------------------

                $this->post("/{urlencoded:(?:[^%]|%[0-9A-Fa-f]{2})+}", function (
                    ServerRequestInterface $request,
                    ResponseInterface      $response,
                    stdClass               $args): ResponseInterface {
                    return $response->withJson(Links::postLinkFromUrl((string)Authentication::token()->channelid, urldecode($args->urlencoded)));
                })
                    ->add(new Authentication);

                // ----------------------------------------------------------------
                // Return a link, including tags and related links
                // ----------------------------------------------------------------

                $this->get("/id/{id:\d{1,10}}", function (
                    ServerRequestInterface $request,
                    ResponseInterface      $response,
                    stdClass               $args): ResponseInterface {
                    $params = [":id" => $args->id];
                    $link = Db::execute("SELECT * FROM links WHERE id=:id", $params)->fetch();
                    if (!$link) throw new ResourceNotFoundException;
                    $tags = Db::execute("SELECT * FROM tags WHERE linkid=:id", $params)->fetchAll();
                    $rels = Db::execute("SELECT links.*, GROUP_CONCAT(tags.tag SEPARATOR ',') AS tags 
                        FROM links JOIN tags 
                        ON tags.linkid=links.id 
                        AND links.id<>:id1
                        AND tag IN 
                            (
                                SELECT tags.tag FROM links LEFT JOIN tags on tags.linkid=links.id WHERE links.id=:id2 
                            )
                        GROUP BY links.id
                        ORDER BY COUNT(links.id) DESC
                        LIMIT 5",
                        [
                            ":id1" => $args->id,
                            ":id2" => $args->id,
                        ])->fetchAll();
                    Db::execute("UPDATE links SET viewscount = viewscount + 1 WHERE id=:id", $params);
                    return $response->withJson(
                        [
                            "link" => $link,
                            "tags" => $tags,
                            "related" => $rels,
                        ]
                    );
                });

                // --------------------------------------------------
                // delete a link by it's id
                // --------------------------------------------------

                $this->delete("/{id:\d{1,10}}", function (
                    ServerRequestInterface $request,
                    ResponseInterface      $response,
                    stdClass               $args): ResponseInterface {
                    if (Db::execute("DELETE FROM links WHERE id =:id and channelid=:channelid",
                            [
                                ":id" => $args->id,
                                ":channelid" => Authentication::token()->channelid,
                            ])->rowCount() == 0)
                        throw new ResourceNotFoundException;
                    return $response->withJson(null);
                })
                    ->add(new Authentication);

                // -----------------------------------------------------
                // Returns links for all channel
                // -----------------------------------------------------

                $this->get("", function (
                    ServerRequestInterface $request,
                    ResponseInterface      $response,
                    stdClass               $args): ResponseInterface {
                    $links = Db::execute("SELECT * FROM links 
                        ORDER BY id DESC LIMIT :offset,:count",
                        [
                            ":offset" => $args->offset,
                            ":count" => $args->count,
                        ])->fetchAll();
                    return $response->withJson($links);
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
                    ServerRequestInterface $request,
                    ResponseInterface      $response,
                    stdClass               $args): ResponseInterface {
                    $links = Db::execute("SELECT links.* FROM tags
                        JOIN links ON tags.linkid=links.id 
                        WHERE tags.tag=:tag
                        ORDER BY links.id DESC 
                        LIMIT :offset,:count",
                        [
                            ":tag" => $args->tag,
                            ":offset" => $args->offset,
                            ":count" => $args->count,
                        ])->fetchAll();
                    return $response->withJson($links);
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
                    ServerRequestInterface $request,
                    ResponseInterface      $response,
                    stdClass               $args): ResponseInterface {
                    $links = Db::execute("SELECT * FROM links WHERE channelid = :channel
                        ORDER BY id DESC LIMIT :offset,:count",
                        [
                            ":channel" => $args->id,
                            ":offset" => $args->offset,
                            ":count" => $args->count,
                        ])->fetchAll();
                    return $response->withJson($links);
                })
                    ->add(new ReadOnly)
                    ->add(new QueryParameters([
                        '{offset:\int},0',
                        '{count:\int},20',
                    ]));

                // ----------------------------------------------------
                // return the metadata of a HTML page
                // ----------------------------------------------------

                $this->get("/metadata/{urlencoded:(?:[^%]|%[0-9A-Fa-f]{2})+}", function (
                    ServerRequestInterface $request,
                    ResponseInterface      $response,
                    stdClass               $args): ResponseInterface {
                    return $response->withJson((new HtmlMetadata)(urldecode($args->urlencoded)));
                })
                    ->add(new Authentication);

            });

            $this->group('/votes', function () {

                // ------------------------------------------------
                // upvote a link
                // ------------------------------------------------

                $this->post("/link/{id:\d{1,10}}", function (
                    ServerRequestInterface $request,
                    ResponseInterface      $response,
                    stdClass               $args): ResponseInterface {
                    if (Db::execute("INSERT INTO votes(linkid, channelid) VALUES(:id, :channelid)",
                            [
                                ":id" => $args->id,
                                ":channelid" => Authentication::token()->channelid,
                            ])->rowCount() == 0
                    ) {
                        throw new Exception;
                    }
                    return $response->withJson(Db::lastInsertId());
                })
                    ->add(new Authentication);

            });

            $this->group('/tags', function () {

                // ------------------------------------------------
                // post a single tag POST /tags/{id}/{tag}
                // ------------------------------------------------

                $this->post("/link/{id:\d{1,10}}/tag/{tag:[\w-]{3,50}}", function (
                    ServerRequestInterface $request,
                    ResponseInterface      $response,
                    stdClass               $args): ResponseInterface {
                    if (Db::execute("INSERT INTO tags(linkid, channelid, tag) VALUES(:id, :channelid, :tag)",
                            [
                                ":id" => $args->id,
                                ":tag" => $args->tag,
                                ":channelid" => Authentication::token()->channelid,
                            ])->rowCount() == 0
                    ) {
                        throw new Exception;
                    }
                    return $response->withJson(Db::lastInsertId());
                })
                    ->add(new Authentication);

                // ------------------------------------------------
                // return all tags unique sorted
                // ------------------------------------------------

                $this->get("/index", function (
                    ServerRequestInterface $request,
                    ResponseInterface      $response,
                    stdClass               $args): ResponseInterface {
                    return $response->withJson(
                        Db::execute("SELECT tag, COUNT(tag) as linkscount FROM tags GROUP BY tag ORDER BY tag", [])->fetchAll()
                    );
                })
                    ->add(new Memcaching(10/*sec*/))
                    ->add(new ReadOnly);

                // ------------------------------------------------
                // get the top trending tags
                // ------------------------------------------------

                $this->get("/active", function (
                    ServerRequestInterface $request,
                    ResponseInterface      $response,
                    stdClass               $args): ResponseInterface {
                    return $response->withJson(
                        [
                            "top" => Db::execute("SELECT * FROM trendingtopics")->fetchAll(),
                            "new" => Db::execute("SELECT * FROM trendingtopics")->fetchAll(),
                            "trending" => Db::execute("SELECT * FROM trendingtopics")->fetchAll(),
                        ]
                    );
                })
                    ->add(new Memcaching(10/*sec*/))
                    ->add(new ReadOnly);

                // ------------------------------------------------
                // delete a tag, only delete your own tags
                // ------------------------------------------------

                $this->delete("/{id:\d{1,10}}", function (
                    ServerRequestInterface $request,
                    ResponseInterface      $response,
                    stdClass               $args): ResponseInterface {
                    if (Db::execute("DELETE tags FROM tags WHERE id=:id and channelid=:channelid",
                            [
                                ":id" => $args->id,
                                ":channelid" => Authentication::token()->channelid,
                            ])->rowCount() == 0
                    ) {
                        throw new Exception;
                    }
                    return $response->withJson(null);
                })
                    ->add(new Authentication);

            });

            // ------------------------------------------------
            // get the activity stream
            // ------------------------------------------------

            $this->get("/activities", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                $activities = Db::execute("SELECT * FROM activitystream ORDER BY id DESC LIMIT :offset,:count",
                    [
                        ":offset" => $args->offset,
                        ":count" => $args->count,
                    ])->fetchAll();
                return $response->withJson($activities);
            })
                ->add(new Memcaching(10/*sec*/))
                ->add(new ReadOnly)
                ->add(new QueryParameters([
                    '{channel:[\w-]{3,54}},null',
                    '{link:,\d{1,10}},null',
                    '{offset:\int},0',
                    '{count:\int},20',
                ]));

            // ------------------------------------------------
            // get some basic statistics
            // ------------------------------------------------

            $this->get("/statistics", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                return $response->withJson(Db::execute("SELECT * FROM statistics")->fetch());
            })
                ->add(new Memcaching(10/*sec*/))
                ->add(new ReadOnly);

            // ------------------------------------------------
            // generic cronjob interfaces, not public
            // ------------------------------------------------

            $this->group('/cronjobs', function () {

                $this->get("/hour", function (
                    ServerRequestInterface $request,
                    ResponseInterface      $response,
                    stdClass               $args): ResponseInterface {
                    set_time_limit(300/*5min*/);
                    (new FeedReader)->refreshAllFeeds();
                    return $response;
                });
                // TODO enable in production
//                ->add(new CliRequest(300));

                $this->get("/day", function (
                    ServerRequestInterface $request,
                    ResponseInterface      $response,
                    stdClass               $args): ResponseInterface {
                    set_time_limit(300/*5min*/);
                    return $response;
                });
                // TODO enable in production
//                ->add(new CliRequest(300));

                $this->get("/month", function (
                    ServerRequestInterface $request,
                    ResponseInterface      $response,
                    stdClass               $args): ResponseInterface {
                    set_time_limit(300/*5min*/);
                    return $response;
                });
                // TODO enable in production
//                ->add(new CliRequest(300));

            });
        }
    }

    // -------------------------------------------------
    // Execute the server
    // -------------------------------------------------

    (new Api)->run();
}