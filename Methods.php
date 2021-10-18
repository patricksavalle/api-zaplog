<?php /** @noinspection PhpUndefinedMethodInspection */

declare(strict_types=1);

namespace Zaplog {

    require_once BASE_PATH . '/Exception/ResourceNotFoundException.php';

    use ContentSyndication\HtmlMetadata;
    use ContentSyndication\HttpRequest;
    use ContentSyndication\Text;
    use ContentSyndication\Url;
    use ContentSyndication\XmlFeed;
    use Exception;
    use SlimRestApi\Infra\Db;
    use SlimRestApi\Infra\Ini;
    use stdClass;
    use Zaplog\Exception\ResourceNotFoundException;
    use Zaplog\Exception\ServerException;
    use Zaplog\Exception\UserException;
    use Zaplog\Library\TwoFactorAction;

    class Methods
    {
        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function getActivityStream(int $offset, int $count, $channelid, bool $grouped = true): array
        {
            $stream = Db::fetchAll("SELECT * FROM activitystream 
                    WHERE (channelid=:channelid1 IS NULL OR channelid=:channelid2)
                    ORDER BY id DESC
                    LIMIT :offset, :count",
                [
                    ":channelid1" => $channelid,
                    ":channelid2" => $channelid,
                    ":count" => $count,
                    ":offset" => $offset,
                ]);

            if (!$grouped) {
                return $stream;
            }

            // postprocessing: group same channel + type except when new link

            $compare = function (stdClass $item1, stdClass $item2): bool {
                // this function controls how interactions are grouped
                return $item1->type !== 'on_insert_link'
                    and $item1->channelid === $item2->channelid
                    and $item1->type === $item2->type;
            };

            $find = function (stdClass $item, array $array, callable $compare): bool {
                foreach ($array as $check) {
                    if ($compare($check, $item)) {
                        return true;
                    }
                }
                return false;
            };

            $stream2 = [];
            foreach ($stream as $value) {
                if (!$find($value, $stream2, $compare)) {
                    $stream2[] = $value;
                }
            }
            return $stream2;
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function getSingleChannel(string $id): array
        {
            return [
                "channel" => Db::fetch("SELECT * FROM channels_public_view WHERE id=:id", [":id" => $id]),

                "tags" => Db::fetchAll("SELECT tag, COUNT(tag) AS tagscount 
                    FROM tags JOIN links ON tags.linkid=links.id  
                    WHERE links.channelid=:channelid 
                    GROUP BY tag ORDER BY SUM(score) DESC LIMIT 10",
                    [":channelid" => $id], 60 * 60),

                "related" => Db::fetchAll("SELECT GROUP_CONCAT(DISTINCT tags.tag SEPARATOR ',' LIMIT 10) AS tags, channels.*
                    FROM links 
                    JOIN tags ON tags.linkid=links.id
                    JOIN channels_public_view AS channels ON links.channelid=channels.id 
                    WHERE tag IN (
                        SELECT tag FROM tags 
                        JOIN links on tags.linkid=links.id 
                        JOIN channels ON links.channelid=channels.id
                        WHERE channels.id=:channelid1
                    ) AND channels.id<>:channelid2
                    GROUP BY channels.id ORDER BY COUNT(tag) DESC, SUM(links.score) DESC LIMIT 5",
                    ["channelid1" => $id, "channelid2" => $id], 60 * 60),

                "activity" => self::getActivityStream(0, 25, $id),
            ];
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function postLinkFromUrl(string $channelid, string $url): string
        {
            $metadata = (new HtmlMetadata)($url);

            (new UserException("Empty title"))(!empty($metadata["title"]) and strlen($metadata["title"]) > 2);
            (new UserException("Empty text"))(!empty($metadata["description"]) and strlen($metadata["description"]) > 2);

            return self::postLink(
                $channelid,
                $metadata["url"],
                $metadata["title"],
                $metadata["description"],
                $metadata["image"] ?? Ini::get("default_post_image"),
                $metadata["keywords"] ?? []);
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function archiveLink(string $linkid, string $url)
        {
            // store url in wayback-machine, use asynchronous self-call
            try {
                (new TwoFactorAction)
                    ->createToken()
                    ->addAction('/Methods.php', ['\Zaplog\Methods', 'storeWebArchive'], [$linkid, $url])
                    ->handleAsync();
            } catch (Exception $e) {
                // async call will only work with reverese proxy in front of PHP interpreter
                error_log(__METHOD__ . " " . $e->getMessage());
                error_log("Restarting as synchronous call");
                self::storeWebArchive($linkid, $url);
            }
        }

        // ----------------------------------------------------------
        // This method is called asynchronously
        // ----------------------------------------------------------

        static public function storeWebArchive(string $linkid, string $url)
        {
            // store in webarchive.com and get archived url
            $waybackurl = Ini::get("webarchive_save_link") . $url;
            (new HttpRequest)($waybackurl);

            // store with link
            if (filter_var($waybackurl, FILTER_VALIDATE_URL)) {
                Db::execute("UPDATE links SET waybackurl=:url WHERE id=:id", [":id" => $linkid, ":url" => $waybackurl]);
            }
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function postTags(string $channelid, string $linkid, array $keywords)
        {
            $tags = [];
            foreach ($keywords as $tag) {
                // sanitize tags
                $tag = (new Text($tag))->convertToAscii()->hyphenizeForPath()->get();
                // only accept reasonable tags
                if (preg_match("/^[\w][\w-]{0,48}[\w]$/", $tag) > 0 and substr_count($tag, "-") < 5) {
                    $tags[] = $tag;
                }
            }

            // remove duplicate keywords after normalisation
            $tags = array_unique($tags);

            // insert keywords into database;
            foreach ($tags as $tag) {
                try {
                    Db::execute("INSERT IGNORE INTO tags(linkid, channelid, tag) VALUES (:linkid, :channelid, :tag)",
                        [":linkid" => $linkid, ":channelid" => $channelid, ":tag" => $tag]);
                } catch (Exception $e) {
                    // ignore on error
                    error_log($e->getMessage() . " @ " . __METHOD__ . "(" . __LINE__ . ") " . $tag);
                }
            }
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function postLink(string $channelid, $url, $title, $markdown, $image, array $keywords = []): string
        {
            // Insert into database
            (new ServerException)(Db::execute("INSERT INTO links(url, channelid, title, markdown, description, image)
                    VALUES (:url, :channelid, :title, :markdown, :description, :image)",
                    [
                        ":url" => $url,
                        ":channelid" => $channelid,
                        ":title" => $title,
                        ":markdown" => $markdown,
                        ":description" => (new Text($markdown))->parseDown()->blurbify()->get(),
                        ":image" => $image,
                    ])->rowCount() > 0);

            $linkid = Db::lastInsertId();

            self::postTags($channelid, $linkid, $keywords);

            // self::archiveLink($linkid, $url);

            return $linkid;
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function getSingleLink(string $id): array
        {
            Db::execute("UPDATE links SET viewscount = viewscount + 1 WHERE id=:id", [":id" => $id]);

            $link = (new ResourceNotFoundException)(Db::fetch("SELECT * FROM links WHERE id=:id", [":id" => $id]));

            // parse and filter the original markdown into safe xhtml
            $link->xtext = (new Text($link->markdown))->parseDownLine()->get();

            return [
                "link" => $link,

                "tags" => Db::fetchAll("SELECT * FROM tags WHERE linkid=:id GROUP BY tag", [":id" => $id]),

                "related" => Db::fetchAll("SELECT GROUP_CONCAT(DISTINCT tags.tag SEPARATOR ',' LIMIT 10) AS tags, links.*
                    FROM links JOIN tags ON tags.linkid=links.id AND links.id<>:id1
                    WHERE tag IN (SELECT tags.tag FROM links JOIN tags on tags.linkid=links.id WHERE links.id=:id2)
                    GROUP BY links.id ORDER BY COUNT(tag) DESC, SUM(links.score) DESC LIMIT 5",
                    [":id1" => $id, ":id2" => $id], 60),

                "interactors" => Db::fetchAll("SELECT DISTINCT * FROM channels_public_view 
                    WHERE id IN (SELECT channelid FROM reactions WHERE linkid=:id1)
                        OR id IN (SELECT channelid FROM tags WHERE linkid=:id2)
                        OR id IN (SELECT channelid FROM votes WHERE linkid=:id3)
                        OR id=(SELECT channelid FROM links WHERE id=:id4)",
                    [":id1" => $id, ":id2" => $id, ":id3" => $id, ":id4" => $id]),
            ];
        }

        static public function refreshSingleFeed(string $channelid, string $feedurl)
        {
            $content = (new XmlFeed)($feedurl);
            $link = null;
            foreach ($content["item"] as $item) {

                try {
                    $link = (new Url($item["link"]))->normalized()->get();

                    // check if unique for channel before we crawl the url
                    if (DB::execute("SELECT id FROM links WHERE urlhash=MD5(:url) AND channelid=:channelid LIMIT 1",
                            [
                                ":url" => $link,
                                ":channelid" => $channelid,
                            ])->rowCount() === 0) {

                        // --------------------------------------------------------------------------
                        // we do not use the content of the feeds because many feeds have no images
                        // instead we harvest the metadata from the articles themselves. Also
                        // bypasses Feedburner links (we need the original links)
                        // --------------------------------------------------------------------------

                        Methods::postLinkFromUrl($channelid, $link);
                    }
                } catch (Exception $e) {
                    error_log($e->getMessage() . " @ " . __METHOD__ . "(" . __LINE__ . ") " . $link);
                }
            }
            Db::execute("UPDATE channels SET refeeddatetime=NOW() WHERE id=:id", [":id" => $channelid]);
        }

        static public function refreshAllFeeds()
        {
            foreach (Db::execute("SELECT id, feedurl FROM channels WHERE NOT feedurl IS NULL ORDER BY RAND()")->fetchAll() as $channel) {
                try {
                    self::refreshSingleFeed((string)$channel->id, $channel->feedurl);
                } catch (Exception $e) {
                    error_log($e->getMessage() . " @ " . __METHOD__ . "(" . __LINE__ . ") " . $channel->feedurl);
                }
            }
            Db::execute("CALL calculate_frontpage()");
        }

        static public function getRelatedTags(string $tag): array
        {
            return Db::fetchAll("SELECT tag, COUNT(tag) AS link_count FROM tags
                JOIN links ON tags.linkid=links.id
                WHERE links.id IN (SELECT links.id 
                    FROM links JOIN tags ON tags.linkid=links.id 
                    WHERE tag=:tag1)
                AND tag<>:tag2
                GROUP BY tag ORDER BY COUNT(tag) DESC, SUM(links.score) DESC LIMIT 25",
                ["tag1" => $tag, "tag2" => $tag]);
        }

    }
}