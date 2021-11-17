<?php /** @noinspection DuplicatedCode */
/** @noinspection PhpUndefinedMethodInspection */

declare(strict_types=1);

namespace Zaplog\Library {

    require_once BASE_PATH . '/Exception/ResourceNotFoundException.php';

    use ContentSyndication\ArchiveOrg;
    use ContentSyndication\Text;
    use Exception;
    use LanguageDetector\LanguageDetector;
    use SlimRestApi\Infra\Db;
    use SlimRestApi\Infra\Ini;
    use stdClass;
    use Zaplog\Exception\ResourceNotFoundException;
    use Zaplog\Exception\ServerException;
    use Zaplog\Exception\UserException;
    use Zaplog\Plugins\MetadataParser;
    use Zaplog\Plugins\ParsedownFilter;

    class Methods
    {
        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function getPaymentShares(): array
        {
            $in_address = Db::fetchAll("SELECT bitcoinaddress FROM channels WHERE id=1");
            $channels = Db::fetchAll("SELECT avatar, name, reputation AS share FROM channels WHERE bitcoinaddress <> NULL ORDER BY reputation DESC LIMIT 50");
            // normalize to ratio's of 1
            if (sizeof($channels) > 0) {
                // get smallest share (last in set)
                $minshare = $channels[sizeof($channels) - 1]->share;
                foreach ($channels as $channel) {
                    $channel->share = floor($channel->share / $minshare);
                }
                // calculate total shares combined
                $sumshare = 0;
                foreach ($channels as $channel) {
                    $sumshare += $channel->share;
                }
                // 50% will be divided among channels
                foreach ($channels as $channel) {
                    $channel->share = $channel->share / $sumshare / 0.5;
                }
            }
            return [
                "in_address" => $in_address,
                "out_shares" => $channels,
            ];
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function getChannelLinks(int $channelid, int $offset, int $count): array
        {
            $algorithm = Db::fetch("SELECT algorithm FROM channels WHERE id=:id", [":id" => $channelid])->algorithm;
            switch ($algorithm) {

                case "channel":
                    // channel displays all posts made by this channel
                    return Db::fetchAll("SELECT * FROM links WHERE published=TRUE AND channelid=:channelid ORDER BY id DESC LIMIT :offset, :count",
                        [":channelid" => $channelid, ":offset" => $offset, ":count" => $count]);

                case "voted":
                    // channel displays posts voted upon by this channel
                    return Db::fetchAll("SELECT links.* FROM links JOIN votes ON links.id=votes.linkid 
                        WHERE votes.channelid=:channelid AND links.published=TRUE ORDER BY links.id DESC LIMIT :offset, :count",
                        [":channelid" => $channelid, ":offset" => $offset, ":count" => $count]);

                case "mixed":
                    // channel displays voted AND channel
                    return Db::fetchAll("SELECT * FROM (
                                SELECT * FROM links WHERE channelid=:channelid1 AND published=TRUE  
                                UNION DISTINCT 
                                SELECT links.* FROM links JOIN votes ON links.id=votes.linkid 
                                WHERE votes.channelid=:channelid2 AND links.published=TRUE 
                            ) AS x ORDER BY id DESC LIMIT :offset, :count",
                        [":channelid1" => $channelid, ":channelid2" => $channelid, ":offset" => $offset, ":count" => $count]);

                default:
                    throw new Exception("Invalid algorithm: " . $algorithm);
            }
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function frontpageQuery(): string
        {
            // channel 1 is the master channel, determines site frontpage
            $algorithm = Db::fetch("SELECT algorithm FROM channels WHERE id=1")->algorithm;
            switch ($algorithm) {

                case "all":
                    // show all articles across all channels
                    return "SELECT * FROM links ORDER BY id DESC LIMIT :count";

                case "channel":
                    // show all articles posted by channel 1
                    return "SELECT * FROM links WHERE channelid=1 ORDER BY id DESC LIMIT :count";

                case "popular":
                    // show the most popular articles in the system
                    return "SELECT * FROM frontpage LIMIT :count";

                case "voted":
                    // show only articles voted upon by channel 1
                    return "SELECT links.* FROM links JOIN votes ON links.id=votes.linkid 
                        WHERE votes.channelid=1 AND links.published=TRUE ORDER BY links.id DESC LIMIT :count";

                case "mixed":
                    // show popular articles in the system AND articles voted upon by channel 1
                    return "SELECT * FROM (
                                SELECT links.* FROM frontpage JOIN links ON frontpage.id=links.id 
                                UNION DISTINCT 
                                SELECT links.* FROM links JOIN votes ON links.id=votes.linkid 
                                WHERE links.published=TRUE AND votes.channelid=1 
                            ) AS x ORDER BY id DESC LIMIT :count";

                default:
                    throw new Exception("Invalid algorithm: " . $algorithm);
            }
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function trendingtopicsQuery(): string
        {
            $frontpage = self::frontpageQuery();
            return "SELECT tag FROM tags
                JOIN links ON tags.linkid=links.id
                JOIN ($frontpage) AS x ON x.id=tags.linkid
                GROUP BY tags.tag
                ORDER BY SUM(links.score) DESC LIMIT 20";
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function trendingchannelsQuery(): string
        {
            $frontpage = self::frontpageQuery();
            return "SELECT channels.* FROM channels_public_view AS channels
                JOIN ($frontpage) AS x ON x.channelid=channels.id
                GROUP BY channels.id
                ORDER BY SUM(channels.score) DESC LIMIT 20";
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function getFrontpage(int $count): array
        {
            return [
                "trendingtags" => Db::fetchAll(self::trendingtopicsQuery(), [":count" => $count]),
                "trendingchannels" => Db::fetchAll(self::trendingchannelsQuery(), [":count" => $count]),
                "trendinglinks" => Db::fetchAll(self::frontpageQuery(), [":count" => $count])];
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

        static public function sanitizeTags(array $keywords): array
        {
            $tags = [];
            foreach ($keywords as $tag) {
                // sanitize tags
                $tag = (string)(new Text($tag))->convertToAscii()->hyphenize();
                // only accept reasonable tags
                if (strlen($tag) > 2 and strlen($tag) < 41 and substr_count($tag, "-") < 5) {
                    // remove duplicates this way
                    $tags[$tag] = $tag;
                }
            }
            return array_values($tags);
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function postTags(int $linkid, int $channelid, array $tags): string
        {
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

        static public function checkImage(stdClass $link)
        {
            // check image
            if (!empty($link->image)) {
                $image_mimetype = "";
                try {
                    $image_mimetype = MetadataParser::getMimetype($link->image);
                } catch (Exception $e) {
                    // nothing, ignore that field
                }
                if (strpos($image_mimetype, "image/") !== 0) {
                    $link->image = Ini::get("default_post_image");
                }
            }
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function checkLink(stdClass $link)
        {
            // sanity checks
            (new UserException("Url and mimetype must be both set or both empty"))(!(empty($link->url) xor empty($link->mimetype)));
            $link->title = strip_tags($link->title);
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function checkCopyright(stdClass $link)
        {
            if (strlen($link->markdown) < 500) {
                $link->copyright = "No Rights Apply";
            } elseif (strcmp($link->copyright, "No Rights Apply") === 0) {
                $link->copyright = "Some Rights Reserved (CC BY-SA 4.0)";
            }
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function checkLanguage(stdClass $link)
        {
            $languages = ["ar", "cs", "da", "de", "el", "en", "es", "fi", "fr", "hu", "it", "nl", "no", "pl", "pt", "ro", "ru", "sk", "sv", "tr"];
            $text = (string)(new Text($link->markdown))->parseDown()->stripTags();
            if (!empty($text)) {
                $link->language = (string)(new LanguageDetector(null, $languages))->evaluate($text);
            }
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function previewLink(stdClass $link, ?array $tags = null): stdClass
        {
            // sanitize
            self::checkLink($link);

            // check image on mimetype
            self::checkImage($link);

            // reasonable copyrights
            self::checkCopyright($link);

            // only in preview
            self::checkLanguage($link);

            // render article text
            $link->description = empty($link->markdown) ? null : (string)(new Text($link->markdown))->parseDown(new ParsedownFilter)->blurbify();
            $link->xtext = (string)(new Text($link->markdown))->parseDown(new ParsedownFilter);

            assert(mb_check_encoding($link->description, 'UTF-8'));
            assert(mb_check_encoding($link->xtext, 'UTF-8'));

            // sanitize tags
            $link->tags = self::sanitizeTags($tags ?? []);

            return $link;
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function postLink(stdClass $link, ?array $tags): stdClass
        {
            // use exact same content as preview would show
            self::previewLink($link, $tags);

            // Insert into database
            (new ServerException)(Db::execute(
                    "INSERT INTO links(url, channelid, title, markdown, description, image, mimetype, language, copyright)
                    VALUES (:url, :channelid, :title, :markdown, :description, :image, :mimetype, :language, :copyright)",
                    [
                        ":url" => $link->url,
                        ":channelid" => $link->channelid,
                        ":title" => $link->title,
                        ":markdown" => $link->markdown,
                        ":description" => $link->description,
                        ":image" => $link->image,
                        ":mimetype" => $link->mimetype,
                        ":language" => $link->language,
                        ":copyright" => $link->copyright,
                    ])->rowCount() > 0);

            $link->id = (int)Db::lastInsertId();

            // insert tags
            if (!empty($link->tags)) {
                self::postTags($link->id, $link->channelid, $link->tags);
            }

            // archive the link
            try {
                if (!empty($link->url)) ArchiveOrg::archiveAsync($link->url);
            } catch (Exception $e) {
                error_log($e->getMessage() . $link->url);
            }

            return $link;
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function patchLink(stdClass $link): stdClass
        {
            // use exact same content as preview would show
            static::previewLink($link);

            // $old_markdown = Db::fetch("SELECT markdown FROM links WHERE id=:id", [":id" => $link->id])->markdown

            // Insert into database
            (new ServerException)(Db::execute(
                    "UPDATE links(url, markdown, description, image, mimetype, language, copyright)
                    VALUES (:url, :markdown, :description, :image, :mimetype, :language, :copyright)
                    WHERE id=:id AND channelid=:channelid",
                    [
                        ":id" => $link->id,
                        ":url" => $link->url,
                        ":channelid" => $link->channelid,
                        ":markdown" => $link->markdown,
                        ":description" => $link->description,
                        ":image" => $link->image,
                        ":mimetype" => $link->mimetype,
                        ":language" => $link->language,
                        ":copyright" => $link->copyright,
                    ])->rowCount() > 0);

            // create diff
            // $diff = xdiff_string_diff();

            // Archive the link
            try {
                ArchiveOrg::archiveAsync($link->url);
            } catch (Exception $e) {
                error_log($e->getMessage() . $link->url);
            }
            return $link;
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function previewReaction(stdClass $reaction): stdClass
        {
            $reaction->xtext = (string)(new Text($reaction->markdown))->stripTags()->parseDown(new ParsedownFilter);
            $reaction->description = (string)(new Text($reaction->xtext))->blurbify();
            return $reaction;
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function postReaction(stdClass $reaction): bool
        {
            $xtext = (string)(new Text($reaction->markdown))->stripTags()->parseDown(new ParsedownFilter);
            $description = (string)(new Text($xtext))->blurbify();
            (new UserException("Comment invalid or empty"))(strlen($xtext) > 0);
            Db::execute("CALL insert_reaction(:channelid,:linkid,:markdown,:xtext,:description)", [
                ":linkid" => $reaction->id,
                ":channelid" => $reaction->channelid,
                ":markdown" => $reaction->markdown,
                ":xtext" => $xtext,
                ":description" => $description]);
            return true;
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
            $link->xtext = (string)(new Text($link->markdown ?? ""))->parseDown(new ParsedownFilter);

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

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function getTopChannelsForTag(string $tag, int $count): array
        {
            return Db::fetchAll("SELECT channels.* FROM channels_public_view AS channels
                        JOIN tags ON tags.channelid=channels.id
                        JOIN links ON tags.linkid=links.id
                        WHERE tag=:tag
                        GROUP BY channels.id
                        ORDER BY SUM(links.score)/COUNT(links.id) DESC LIMIT :count",
                [":tag" => $tag, ":count" => $count], 60 * 60 * 12);
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

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

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function getDiscussion(?string $channelid, int $offset, int $count): array
        {
            return Db::fetchAll("SELECT
                    r.threadid,
                    r.rownum,
                    reactions.id,
                    reactions.createdatetime,
                    reactions.description,
                    reactions.channelid,
                    reactions.linkid,
                    channels.name,
                    channels.avatar,
                    links.title,
                    links.image,
                    links.createdatetime AS linkdatetime
                FROM (
                     SELECT id, channelid, linkid, x.threadid, x.rownum FROM (
                        SELECT 
                            threadid, 
                            id, 
                            channelid, 
                            linkid,
                            (@num:=if(@threadid = threadid, @num +1, if(@threadid := threadid, 1, 1))) AS rownum
                        FROM reactions
                        ORDER BY threadid DESC, id DESC
                     ) AS x
                     JOIN (
                        SELECT threadid FROM reactions
                        JOIN links ON links.id=reactions.channelid
                        WHERE :channelid1 IS NULL OR :channelid2=links.channelid
                        GROUP BY threadid
                        ORDER BY threadid DESC LIMIT :offset, :count) AS t ON x.threadid=t.threadid
                     WHERE x.rownum <= 3
                ) AS r
                JOIN reactions ON reactions.id=r.id
                JOIN channels ON channels.id=r.channelid
                LEFT JOIN links ON links.id=r.linkid AND rownum=1
                ORDER by r.threadid DESC, r.id DESC",
                [":offset" => $offset, ":count" => $count, ":channelid1" => $channelid, ":channelid2" => $channelid]);
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function getReactionsForLink(int $linkid): array
        {
            return Db::fetchAll("SELECT 
                    reactions.id, 
                    reactions.xtext, 
                    reactions.createdatetime, 
                    reactions.channelid, 
                    channels.name, 
                    channels.avatar FROM reactions 
                JOIN channels ON channels.id=reactions.channelid
                WHERE linkid=:id AND reactions.published=TRUE 
                ORDER BY reactions.id DESC", [":id" => $linkid]);
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function getTopTags(int $count): array
        {
            return [
                "top" => Db::fetchAll("SELECT * FROM toptopics LIMIT :count", [":count" => $count]),
                "new" => Db::fetchAll("SELECT * FROM newtopics LIMIT :count", [":count" => $count]),
                "trending" => Db::fetchAll(self::trendingtopicsQuery(), [":count" => $count]),
            ];
        }
    }
}