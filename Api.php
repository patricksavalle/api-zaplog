<?php

/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpUndefinedMethodInspection */
/** @noinspection PhpUnusedParameterInspection */

declare(strict_types=1);

namespace Zaplog {

    define("BASE_PATH", dirname(__FILE__));

    require BASE_PATH . '/vendor/autoload.php';
    require BASE_PATH . '/Middleware/Authentication.php';
    require BASE_PATH . '/Library/HtmlMetadata.php';
    require BASE_PATH . '/Library/Feed.php';
    require BASE_PATH . '/Exception/ResourceNotFoundException.php';

    use stdClass;
    use Exception;
    use SlimRequestParams\BodyParameters;
    use SlimRequestParams\QueryParameters;
    use SlimRestApi\Middleware\ReadOnly;
    use SlimRestApi\SlimRestApi;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use SlimRestApi\Infra\Db;
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

            // show the API homepage
            $this->get("/", function ($rq, $rsp, $args) {
                echo "<h1>SOCIAL BOOKMARKING AND BLOGGING REST-API V01</h1>";
                echo "<strong>Object oriented coded on PHP 7.3.9 / Relational datamodelling in MARIADB 10.4.6 by patrick@patricksavalle.com</strong>";
                echo "<ul>";
                echo "<li>Installing memcache will improve performance</li>";
                echo "<li>Needs MariaDB event scheduler </li>";
                echo "<li>Needs SMTP server for </li>";
                echo "<li>Consider using an API gateway like WSO2</li>";
                echo "</ul>";
                echo "<h2>Generated quick reference: endpoints</h2>";
                echo "<table>";
                foreach ($this->router->getRoutes() as $route) {
                    echo "<tr>";
                    foreach ($route->getMethods() as $method) {
                        echo "<td>$method</td><td>{$route->getPattern()}</td>";
                    }
                    echo "</tr>";
                }
                echo "</table>";
                return $rsp;
            });

            // -----------------------------------------------------
            // send a single-use auto-expiring log-in link to email
            // uses an email template /login.html
            // -----------------------------------------------------

            $this->post("/sessions/{emailencoded:(?:[^%]|%[0-9A-Fa-f]{2})+}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                (new TwoFactorAuth)
                    ->addParams($args)
                    ->addTrigger('API.php', ['\Zaplog\Middleware\Authentication', 'createSession'], [$args->email])
                    ->send();
                return $response;
            })
                ->add(new BodyParameters([
                    '{receiver:\email}',
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
                return $response;
            })
                ->add(new Authentication);

            // ----------------------------------------------------------------
            // Return the active channels (sessions) 'who's online'
            // ----------------------------------------------------------------

            $this->get("/sessions", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                return $response->withJson(
                    Db::execute("SELECT name, avatar, score, lastactivity FROM sessions 
                        LEFT JOIN channels ON sessions.channelid=channels.id")->fetchAll()
                );
            })
                ->add(new ReadOnly);

            // ----------------------------------------------------------------
            // Return a link, including tags and related links
            // ----------------------------------------------------------------

            $this->get("/links/id/{id:\d{1,10}}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                $link = Db::execute("SELECT * FROM links WHERE id=:id", [":id" => $args->id])->fetch();
                if (!$link) throw new ResourceNotFoundException;
                $tags = Db::execute("SELECT * FROM tags WHERE linkid=:id", [":id" => $args->id])->fetchAll();
                //TODO $related = Db::execute("", ["" => null])->fetchAll();
                return $response->withJson(
                    [
                        "link" => $link,
                        "tags" => $tags,
                        // TODO "related" => $related,
                    ]
                );
            })
                ->add(new ReadOnly);

            // -----------------------------------------------------
            // Returns the currently select frontpage links
            // -----------------------------------------------------

            $this->get("/links/frontpage", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                return $response->withJson(
                    Db::execute("SELECT links.* FROM frontpagelinks 
                        JOIN links ON frontpagelinks.linkid=links.id 
                        ORDER BY frontpagelinks.score DESC")->fetchAll()
                );
            })
                ->add(new ReadOnly);

            // -----------------------------------------------------
            // Returns the top scoring links for a given tag
            // -----------------------------------------------------

            $this->get("/links/tag/{tag:\w{3,55}}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                $links = Db::execute("SELECT link.* FROM tags
                    LEFT JOIN links ON tags.linkid=links.id
                    WHERE tags.tag=:tag
                    GROUP BY links.id
                    ORDER BY links.score DESC 
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
                    '{tag:\w+}',
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
                    '{search:\w+},null',
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
                // TODO could add the tags from the metadata
                return $response;
            })
                ->add(new Authentication);

            // --------------------------------------------------
            // delete a link by it's id
            // --------------------------------------------------

            $this->delete("/links/{id:\d{1,10}}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                if (Db::execute("DELETE FROM links WHERE id=:id", [":id" => $args->id])->rowCount() == 0)
                    throw new ResourceNotFoundException;
                return $response;
            })
                ->add(new Authentication);

            // vote a link
            $this->post("/votes/{id:\d{1,10}}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                if (Db::execute("INSERT INTO votes(linkid,channelid) VALUES (:id, :channelid)",
                        [
                            ":id" => $args->id,
                            ":channelid" => Authentication::token()->channelid,
                        ])->rowCount() == 0
                ) {
                    throw new Exception;
                }
                return $response;
            })
                ->add(new Authentication);

            $this->post("/tags/{id:\d{1,10}}/{tag:\w{3,50}}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                if (Db::execute("INSERT INTO tags(linkid,channelid,tag) VALUES (:id, :channelid, :tag)",
                        [
                            ":id" => $args->id,
                            ":tag" => $args->tag,
                            ":channelid" => Authentication::token()->channelid,
                        ])->rowCount() == 0
                ) {
                    throw new Exception;
                }
                return $response;
            })
                ->add(new Authentication);

            $this->delete("/tags/{id:\d{1,10}}/{tag:\w{3,50}}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                if (Db::execute("DELETE tags FROM tags 
                    WHERE tag=:tag AND linkid=:id AND channelid=:channelid",
                        [
                            ":id" => $args->id,
                            ":tag" => $args->tag,
                            ":channelid" => Authentication::token()->channelid,
                        ])->rowCount() == 0
                ) {
                    throw new Exception;
                }
                return $response;
            })
                ->add(new Authentication);

            $this->get("/tags/index", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                return $response;
            })
                ->add(new ReadOnly);

            $this->get("/tags/trending", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                return $response;
            })
                ->add(new ReadOnly);

            $this->get("/activities", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                $activities = Db::execute("SELECT * FROM activities ORDER BY datetime DESC LIMIT :offset,:count",
                    [
                        ":offset" => $args->offset,
                        ":count" => $args->count,
                    ])->fetchAll();
                return $response->withJson($activities);
            })
                ->add(new ReadOnly)
                ->add(new QueryParameters([
                    '{channel:\w{3,54}},null',
                    '{link:,\d{1,10}},null',
                    '{offset:\int},0',
                    '{count:\int},100',
                ]));

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

    (new api)->run();
}