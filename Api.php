<?php

/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpUndefinedMethodInspection */
/** @noinspection PhpUnusedParameterInspection */

declare(strict_types=1);

namespace Zaplog {

    define("BASE_PATH", dirname(__FILE__));

    require_once BASE_PATH . '/vendor/autoload.php';
    require_once BASE_PATH . '/Middleware/Authentication.php';
    require_once BASE_PATH . '/Library/HtmlMetadata.php';
    require_once BASE_PATH . '/Library/Feed.php';
    require_once BASE_PATH . '/Library/TwoFactorAuth.php';
    require_once BASE_PATH . '/Exception/ResourceNotFoundException.php';
    require_once BASE_PATH . '/Exception/EmailException.php';

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
    use Zaplog\Library\HtmlMetadata;
    use Zaplog\Library\TwoFactorAuth;
    use Zaplog\Middleware\Authentication;
    use Zaplog\Library\Feed;

    class Api extends SlimRestApi
    {
        /** @noinspection PhpUndefinedFieldInspection */
        public function __construct()
        {
            parent::__construct();

            // -----------------------------------------
            // show the API homepage
            // -----------------------------------------

            $this->get("/", function ($rq, $rsp, $args) : ResponseInterface {
                echo "<h1>ZAPLOG REST-API</h1>";
                echo "<p>See: <a href='https://github.com/zaplogv2/api.zaplog'>Github repository</a></p>";
                echo "<table>";
                foreach ($this->router->getRoutes() as $route) {
                    foreach ($route->getMethods() as $method) {
                        echo "<tr><td>$method</td><td>{$route->getPattern()}</td></tr>";
                    }
                }
                echo "</table>";
                return $rsp;
            });

            // -----------------------------------------------------
            // The 2FA hook, if you have a token, you can execute
            // the triggers associated with the token
            // -----------------------------------------------------

            $this->get("/2factor/{utoken:[[:alnum:]]{32}}", new TwoFactorAuth);

            // -----------------------------------------------------
            // send a single-use auto-expiring log-in link to email
            // uses an email template /login.html
            // -----------------------------------------------------

            $this->post("/sessions/{emailencoded:(?:[^%]|%[0-9A-Fa-f]{2})+}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                $email = urldecode($args->emailencoded);
                $args->receiver = $email;
                $Auth = new TwoFactorAuth;
                try {
                    $Auth
                        ->addTrigger('Middleware/Authentication.php', ['\Zaplog\Middleware\Authentication', 'createSession'], [$email])
                        ->createToken()
                        ->sendToken($args);
                    return $response->withJson(null);
                } catch (EmailException $e) {
                    // TODO remove in production
                    return $response->withJson("/Api.php/2factor/" . $Auth->utoken);
                }
            })
                ->add(new BodyParameters([
                    '{subject:.{1,128}},Your single-use login link',
                    '{button:.{1,30}},Login',
                    '{button_url:\url},null',
                    '{template_url:\url},login.html',
                    '{*}',
                ]));

            // -----------------------------------------------------
            // invalidate the session token
            // -----------------------------------------------------

            $this->delete("/sessions", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                if (Db::execute("DELETE FROM sessions WHERE token=:token",
                        [":token" => Authentication::token()])
                        ->rowCount() == 0
                ) {
                    throw new Exception;
                }
                return $response->withJson(null);
            })
                ->add(new Authentication);

            // ----------------------------------------------------------------
            // Return the active channels (sessions) 'who's online'
            // ----------------------------------------------------------------

            $this->get("/sessions", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                return $response->withJson(Db::execute("SELECT * FROM whosonline")->fetchAll());
            })
                ->add(new ReadOnly);

            // ----------------------------------------------------------------
            // Return a link, including tags and related links
            // ----------------------------------------------------------------

            $this->get("/links/id/{id:\d{1,10}}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                Db::execute("UPDATE links SET viewscount = viewscount + 1");
                $link = Db::execute("SELECT * FROM links WHERE id=:id", [":id" => $args->id])->fetch();
                if (!$link) throw new ResourceNotFoundException;
                $tags = Db::execute("SELECT * FROM tags WHERE linkid=:id", [":id" => $args->id])->fetchAll();
                // TODO below is just a dummy response
                $related = Db::execute("SELECT * FROM links LIMIT 5", [])->fetchAll();
                return $response->withJson(
                    [
                        "link" => $link,
                        "tags" => $tags,
                        "related" => $related,
                    ]
                );
            });

            // -----------------------------------------------------
            // Returns the currently select frontpage links
            // Always exactly 20 items
            // -----------------------------------------------------

            $this->get("/links/frontpage", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                return $response->withJson(Db::execute("SELECT * FROM frontpage")->fetchAll());
            })
                ->add(new ReadOnly);

            // -----------------------------------------------------
            // Returns the top scoring links for a given tag
            // -----------------------------------------------------

            $this->get("/links/tag/{tag:[\w-]{3,55}}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                $links = Db::execute("SELECT link.* FROM tags
                    LEFT JOIN links ON tags.linkid=links.id WHERE tags.tag=:tag
                    GROUP BY links.id ORDER BY links.score DESC 
                    LIMIT :offset,:count",
                    [
                        ":tag" => $args->tag,
                        ":offset" => $args->offset,
                        ":count" => $args->count,
                    ])->fetchAll();
                return $response->withJson($links);
            })
                ->add(new ReadOnly)
                ->add(new QueryParameters([
                    '{tag:[\w-]+}',
                    '{offset:\int},0',
                    '{count:\int},100',
                ]));

            // -----------------------------------------------------
            // Returns links
            // -----------------------------------------------------

            $this->get("/links", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                $links = Db::execute("SELECT * FROM links 
                    WHERE (:channel1 IS NULL OR channelid = :channel2)
                    ORDER BY :order DESC LIMIT :offset,:count",
                    [
                        ":channel1" => $args->channel,
                        ":channel2" => $args->channel,
                        ":offset" => $args->offset,
                        ":count" => $args->count,
                        ":order" => $args->order,
                    ])->fetchAll();
                return $response->withJson($links);
            })
                ->add(new ReadOnly)
                ->add(new QueryParameters([
                    '{search:.+},null',
                    '{channel:\int},null',
                    '{offset:\int},0',
                    '{count:\int},100',
                    '{order:(id|score)},id',
                ]));

            // ----------------------------------------------------
            // add a link, retrieve its metadata
            // ----------------------------------------------------

            $this->post("/links/{urlencoded:(?:[^%]|%[0-9A-Fa-f]{2})+}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                $metadata = (new HtmlMetadata)(urldecode($args->urlencoded));
                if (Db::execute("INSERT INTO links(url,channelid,title,description,image,domain,site)
                VALUES (:url, :channelid, :title, :description, :image, :domain, :site)",
                        [
                            ":url" => $metadata["link_url"],
                            ":channelid" => Authentication::token()->channelid,
                            ":title" => $metadata["link_title"],
                            ":description" => $metadata["link_description"],
                            ":image" => $metadata["link_image"],
                            ":domain" => $metadata["link_domain"],
                            ":site" => $metadata["link_site_name"],
                        ])->rowCount() == 0
                ) {
                    throw new Exception;
                }
                $linkid = Db::lastInsertId();
                if (isset($metadata['link_keywords']))
                    foreach (explode(",", $metadata['link_keywords']) as $tag) {
                        // these metadata tags are not assigned to a channel (so they can be filtered)
                        Db::execute("INSERT INTO tags(linkid, channelid, tag) VALUES (:linkid, NULL, :tag)",
                            [
                                ":linkid" => $linkid,
                                ":tag" => trim($tag),
                            ]);
                    }
                return $response->withJson($metadata);
            })
                ->add(new Authentication);

            // --------------------------------------------------
            // delete a link by it's id
            // --------------------------------------------------

            $this->delete("/links/{id:\d{1,10}}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                if (Db::execute("DELETE FROM links WHERE id =:id", [":id" => $args->id])->rowCount() == 0)
                    throw new ResourceNotFoundException;
                return $response->withJson(null);
            })
                ->add(new Authentication);

            // ------------------------------------------------
            // up vote a link
            // ------------------------------------------------

            $this->post("/votes/{id:\d{1,10}}", function (
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

            // ------------------------------------------------
            // post a single tag POST /tags/{id}/{tag}
            // ------------------------------------------------

            $this->post("/tags/{id:\d{1,10}}/{tag:[\w-]{3,50}}", function (
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
            // delete a tag, only delete your own tags
            // ------------------------------------------------

            $this->delete("/tags/{id:\d{1,10}}", function (
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

            // ------------------------------------------------
            // delete a tag, only delete your own tags
            // ------------------------------------------------

            $this->get("/tags/index", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                $tags = Db::execute("SELECT tag, COUNT(tag) as linkscount FROM tags 
                    WHERE (:channel1 IS NULL OR channelid=:channel2)
                    GROUP BY tag ORDER BY tag",
                    [
                        ":channel1" => $args->channel,
                        ":channel2" => $args->channel,
                    ])->fetchAll();
                return $response->withJson($tags);
            })
                ->add(new ReadOnly)
                ->add(new QueryParameters([
                    '{channel:\int},null',
                ]));

            $this->get("/tags/trending", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                return $response->withJson(Db::execute("SELECT * FROM trendingtopics")->fetchAll());
            })
                ->add(new ReadOnly);

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
                ->add(new Memcaching(10 /*sec*/))
                ->add(new ReadOnly)
                ->add(new QueryParameters([
                    '{channel:[\w-]{3,54}},null',
                    '{link:,\d{1,10}},null',
                    '{offset:\int},0',
                    '{count:\int},100',
                ]));

            $this->get("/statistics", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                return $response->withJson(Db::execute("SELECT * FROM statistics")->fetch());
            })
                ->add(new Memcaching(10 /*sec*/))
                ->add(new ReadOnly);

            $this->get("/metadata/{urlencoded:(?:[^%]|%[0-9A-Fa-f]{2})+}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                return $response->withJson((new HtmlMetadata)(urldecode($args->urlencoded)));
            })
                ->add(new Authentication);

            $this->get("/feed/{urlencoded:(?:[^%]|%[0-9A-Fa-f]{2})+}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                $feed = (new Feed)(urldecode($args->urlencoded));
                $array = $feed->toArray();
                return $response->withJson(json_encode($array, JSON_UNESCAPED_SLASHES));
            })
                ->add(new Authentication);

        }
    }

    // -------------------------------------------------
    // Execute the server
    // -------------------------------------------------

    (new Api)->run();
}