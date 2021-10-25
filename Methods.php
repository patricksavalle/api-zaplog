<?php /** @noinspection PhpUndefinedMethodInspection */

declare(strict_types=1);

namespace Zaplog {

    require_once BASE_PATH . '/Exception/ResourceNotFoundException.php';

    use ContentSyndication\ArchiveOrg;
    use ContentSyndication\HtmlMetadata;
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
    use Zaplog\Library\ParsedownProcessor;
    use Zaplog\Library\TwoFactorAction;

    class Methods
    {
        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function getPaymentShares(): array
        {
            $channels = Db::fetchAll("SELECT avatar, name, reputation AS share FROM topchannels WHERE moneroaddress <> NULL");
            if (sizeof($channels) > 0) {
                // normalize to ratio's of 1
                $minshare = $channels[sizeof($channels) - 1]->share;
                foreach ($channels as $channel) {
                    $channel->share = floor($channel->share / $minshare);
                }
                $sumshare = 0;
                foreach ($channels as $channel) {
                    $sumshare += $channel->share;
                }
                foreach ($channels as $channel) {
                    $channel->share = $channel->share / $sumshare / 0.5;
                }
            }
            return array_merge([["avatar" => "", "name" => "Zaplog", "share" => 0.5]], $channels);
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function getActivityStream(int $offset, int $count, $channelid, bool $grouped = true): array
        {
            $stream = Db::fetchAll("SELECT
                        interactions.*,
                        channels.name AS channelname,
                        channels.avatar AS channelavatar,
                        links.title AS linktitle,
                        links.image AS linkimage,
                        links.description AS linktext
                    FROM (SELECT * FROM interactions WHERE (:channelid1 IS NULL OR interactions.channelid=:channelid2)
                            ORDER BY id DESC
                            LIMIT :offset, :count) AS interactions
                    JOIN channels_public_view AS channels ON channels.id=interactions.channelid
                    LEFT JOIN links ON links.id=interactions.linkid AND interactions.type = 'on_insert_link' 
                    ORDER BY interactions.id DESC",
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
                "channel" => (new ResourceNotFoundException)(Db::fetch("SELECT * FROM channels_public_view WHERE id=:id", [":id" => $id])),

                "tags" => Db::fetchAll("SELECT tag, COUNT(tag) AS tagscount 
                    FROM tags JOIN links ON tags.linkid=links.id  
                    WHERE links.channelid=:channelid 
                    GROUP BY tag ORDER BY SUM(score) DESC LIMIT 10",
                    [":channelid" => $id], 60 * 60),

                "related" => Db::fetchAll("SELECT channels.*
                    FROM (
                        SELECT tag FROM tags
                        WHERE channelid=:channelid1
                        GROUP BY tag
                        ORDER BY COUNT(tag) DESC
                        LIMIT 10
                    ) AS ttags
                    JOIN tags ON ttags.tag=tags.tag 
                    JOIN channels_public_view AS channels ON tags.channelid=channels.id
                    AND channels.id<>:channelid2
                    GROUP BY channels.id ORDER BY COUNT(tags.tag) DESC LIMIT 5",
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

            // external input must be validated
            if (!empty($metadata["title"]) and strlen($metadata["title"]) < 2) $metadata["title"] = null;
            if (!empty($metadata["description"]) and strlen($metadata["description"]) < 2) $metadata["description"] = null;
            (new UserException("Invalid link"))(filter_var($metadata["url"], FILTER_VALIDATE_URL) !== false);
            (new UserException("Invalid title or description"))(!empty($metadata["title"]) or !empty($metadata["description"]));
            (new UserException("Invalid image"))(empty($metadata["image"]) or filter_var($metadata["image"], FILTER_VALIDATE_URL) !== false);

            return self::postLink(
                $channelid,
                $metadata["url"],
                $metadata["title"] ?? "",
                $metadata["description"] ?? "",
                $metadata["image"] ?? Ini::get("default_post_image"),
                $metadata["keywords"] ?? []);
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function storeWebArchiveBackground(string $linkid, string $url)
        {
            // store url in wayback-machine, use asynchronous self-call
            try {
                (new TwoFactorAction)
                    ->createToken()
                    ->addAction('/Methods.php', ['\Zaplog\Methods', 'storeWebArchive'], [$linkid, $url])
                    ->handleAsync();
            } catch (Exception $e) {
                error_log(__METHOD__ . " " . $e->getMessage());
            }
        }

        // ----------------------------------------------------------
        // This method is called asynchronously
        // ----------------------------------------------------------

        static public function storeWebArchive(string $linkid, string $url)
        {
            $archived_url = ArchiveOrg::archive($url);
            assert(filter_var($archived_url, FILTER_VALIDATE_URL) !== false);
            Db::execute("UPDATE links SET waybackurl=:url WHERE id=:id", [":id" => $linkid, ":url" => $archived_url]);
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function postTags(/*int*/ $channelid, /*int*/ $linkid, array $keywords): string
        {
            $tags = [];
            foreach ($keywords as $tag) {
                // sanitize tags
                $tag = (string)(new Text($tag))->convertToAscii()->hyphenize();
                // only accept reasonable tags
                if (preg_match("/^[\w][\w-]{0,48}[\w]$/", $tag) > 0 and substr_count($tag, "-") < 5) {
                    $tags[] = $tag;
                }
            }

            // remove duplicate keywords after normalisation
            $tags = array_unique($tags);

            // insert keywords into database;
            $count = 0;
            foreach ($tags as $tag) {
                try {
                    Db::execute("INSERT IGNORE INTO tags(linkid, channelid, tag) VALUES (:linkid, :channelid, :tag)",
                        [":linkid" => $linkid, ":channelid" => $channelid, ":tag" => $tag]);
                    $count++;
                } catch (Exception $e) {
                    // ignore on error
                    error_log($e->getMessage() . " @ " . __METHOD__ . "(" . __LINE__ . ") " . $tag);
                }
            }
            return Db::lastInsertid();
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function postLink(string $channelid, string $url, string $title, string $markdown, string $image, array $keywords = []): string
        {
            // Insert into database
            (new ServerException)(Db::execute("INSERT INTO links(url, channelid, title, markdown, description, image)
                    VALUES (:url, :channelid, :title, :markdown, :description, :image)",
                    [
                        ":url" => $url,
                        ":channelid" => $channelid,
                        ":title" => $title,
                        ":markdown" => $markdown,
                        ":description" => (new Text($markdown))->parseDown(new ParsedownProcessor)->blurbify(),
                        ":image" => $image,
                    ])->rowCount() > 0);

            $linkid = Db::lastInsertId();

            self::postTags($channelid, $linkid, $keywords);

            ArchiveOrg::archiveAsync($url);

            return $linkid;
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function getSingleLink(string $id): array
        {
            // update view counter
            Db::execute("UPDATE links SET viewscount = viewscount + 1 WHERE id=:id", [":id" => $id]);

            // get post
            $link = (new ResourceNotFoundException)(Db::fetch("SELECT * FROM links 
                WHERE id=:id AND published=TRUE", [":id" => $id]));

            // parse and filter the original markdown into safe xhtml
            $link->xtext = (string)(new Text($link->markdown))->parseDown(new ParsedownProcessor);

            return [
                "link" => $link,

                "channel" => Db::fetch("SELECT * FROM channels_public_view WHERE id=:id", [":id" => $link->channelid]),

                "tags" => Db::fetchAll("SELECT * FROM tags WHERE linkid=:id GROUP BY tag", [":id" => $id]),

                "related" => Db::fetchAll("SELECT links.id, links.description, links.createdatetime, links.channelid, links.title, links.image
                    FROM links JOIN tags ON tags.linkid=links.id AND links.id<>:id1
                    WHERE tag IN (SELECT tags.tag FROM links JOIN tags on tags.linkid=links.id WHERE links.id=:id2) AND published=TRUE
                    GROUP BY links.id ORDER BY COUNT(tag) DESC, SUM(links.score) DESC LIMIT 5",
                    [":id1" => $id, ":id2" => $id], 60 * 20),

                "interactors" => Db::fetchAll("SELECT c.id, c.name, c.avatar, GROUP_CONCAT(action) AS interactions FROM channels_public_view AS c JOIN
                    (SELECT channelid, 'links' AS action FROM links WHERE id=:id1
                    UNION SELECT channelid, 'reactions' AS action FROM reactions WHERE linkid=:id2
                    UNION SELECT channelid, 'tags' AS action  FROM tags WHERE linkid=:id3
                    UNION SELECT channelid, 'votes' AS action FROM votes WHERE linkid=:id4) AS i ON i.channelid=c.id
                    GROUP BY c.id",
                    [":id1" => $id, ":id2" => $id, ":id3" => $id, ":id4" => $id]),
            ];
        }

        static public function refreshSingleFeed(string $channelid, string $feedurl)
        {
            $content = (new XmlFeed)($feedurl);
            $link = null;
            foreach ($content["item"] as $item) {

                try {
                    $link = (string)(new Url($item["link"]))->normalized();

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

        static public function getRelatedTags(string $tag, int $count): array
        {
            return Db::fetchAll("SELECT tag FROM tags
                JOIN links ON tags.linkid=links.id
                WHERE links.id IN (SELECT links.id 
                    FROM links JOIN tags ON tags.linkid=links.id 
                    WHERE tag=:tag1)
                AND tag<>:tag2
                GROUP BY tag ORDER BY COUNT(tag) DESC, SUM(links.score) DESC LIMIT :count",
                [":tag1" => $tag, ":tag2" => $tag, ":count" => $count], 60 * 60);
        }

    }
}