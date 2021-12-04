<?php
/** @noinspection DuplicatedCode */
/** @noinspection PhpUndefinedMethodInspection */

declare(strict_types=1);

namespace Zaplog\Library {

    require_once BASE_PATH . '/Exception/ResourceNotFoundException.php';

    use ContentSyndication\ArchiveOrg;
    use ContentSyndication\Text;
    use Exception;
    use Gumlet\ImageResize;
    use LanguageDetector\LanguageDetector;
    use SlimRestApi\Infra\Db;
    use SlimRestApi\Infra\Ini;
    use SlimRestApi\Infra\Memcache;
    use stdClass;
    use Zaplog\Exception\ResourceNotFoundException;
    use Zaplog\Exception\ServerException;
    use Zaplog\Exception\UserException;
    use Zaplog\Plugins\MetadataParser;
    use Zaplog\Plugins\ParsedownFilter;
    use Zaplog\Plugins\ParsedownFilters\TagHarvester;

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

        static public function postTags(int $linkid, int $channelid, array $tags)
        {
            // insert keywords into database;
            foreach ($tags as $tag)
                Db::execute("INSERT IGNORE INTO tags(linkid, channelid, tag) VALUES (:linkid, :channelid, :tag)",
                    [":linkid" => $linkid, ":channelid" => $channelid, ":tag" => $tag]);
            return Db::lastInsertid();
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function checkImage(stdClass $link)
        {
            if (!empty($link->image)) {
                $image_mimetype = "";
                try {
                    $image_mimetype = MetadataParser::getMimetype($link->image);
                } catch (Exception $e) {
                    // nothing
                }
                if (strpos($image_mimetype, "image/") !== 0) {
                    $link->image = Ini::get("default_post_image");
                }
            }
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function checkTitle(stdClass $link)
        {
            $link->title = substr(strip_tags($link->title), 0, 257);
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function checkUrl(stdClass $link)
        {
            if (empty($link->url)) {
                $link->url = null;
                $link->mimetype = null;
                $link->image = null;
            } else {
                $metadata = MetadataParser::getMetadata($link->url);
                $link->url = $metadata['url'];
                $link->mimetype = $metadata['mimetype'];
                $link->image = $metadata['image'];
            }
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

        static public function checkTranslation(stdClass $link)
        {
            $link->language = (string)(new LanguageDetector)->evaluate($link->markdown ?? "");
            if (empty($link->language) or strlen($link->language) !== 2) {
                $link->language = null;
            } else {
                $system_language = Db::fetch("SELECT language FROM channels WHERE id=1")->language;
                if ($link->language !== $system_language and Ini::get("auto_translate")) {
                    $link->tags = [];
                    self::getTranslation($link, $system_language);
                    $link->language = $system_language;
                }
            }
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function checkMarkdown(stdClass $link)
        {
            (new UserException("Empty markdown"))(!empty($link->markdown));
            // render article text
            $link->xtext = (string)(new Text($link->markdown))->parseDown(new ParsedownFilter);
            assert(mb_check_encoding($link->xtext, 'UTF-8'));
            $link->description = (string)(new Text($link->xtext))->blurbify();
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function suggestTags(?string $title, ?string $description): array
        {
            $filterTags = function (?string $string): array {
                $selected = [];
                foreach (explode(" ", preg_replace("[\.\,\"\']", " ", $string ?? "")) as $tag) if (strlen($tag) > 7) $selected[] = $tag;
                return $selected;
            };
            // use larger words from title
            $title_tags = $filterTags($title);
            // use larger words from description
            $description_tags = $filterTags($description);
            // limit to words already in tgs database
            $tags = self::sanitizeTags(array_merge($title_tags, $description_tags));
            $tags = "'" . implode("','", $tags) . "'";
            $tags = Db::fetch("SELECT GROUP_CONCAT(DISTINCT tag) AS tags FROM tags WHERE tag IN ($tags) ORDER BY COUNT(linkid) DESC LIMIT 8")->tags;
            return empty($tags) ? [] : explode(",", $tags);
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function getMetadata(string $url): array
        {
            $metadata = MetadataParser::getMetadata($url);
            if (sizeof($metadata["keywords"] ?? []) === 0) {
                $metadata['keywords'] = array_merge(
                    $metadata["keywords"] ?? [],
                    self::suggestTags($metadata["title"], $metadata["description"] ?? ""));
            }
            return $metadata;
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function getTranslationPrim(string $text, string $target_lang = "nl"): string
        {
            $postdata = http_build_query(
                ['auth_key' => Ini::get("deepl_auth_key"),
                    'target_lang' => $target_lang,
                    'text' => $text,]
            );
            $opts = ['http' =>
                ['method' => 'POST',
                    'header' => 'Content-Type: application/x-www-form-urlencoded',
                    'content' => $postdata,],
            ];
            $translation = file_get_contents(Ini::get("deepl_api_url"), false, stream_context_create($opts));
            return json_decode($translation, true)["translations"][0]["text"] ?? $text;
        }

        static public function getTranslation(stdClass $link, $language)
        {
            $link->markdown = Memcache::call_user_func_array([__CLASS__, "getTranslationPrim"], [$link->markdown, $language], 1000);
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function generateDiff(stdClass $old, stdClass $new)
        {
            $xtext = "";
            if (strcmp($old->copyright, $new->copyright) !== 0) {
                $xtext .= "<p><em>copyright changed: </em><del>$old->copyright</del><ins>$new->copyright</ins></p>";
            }
            if (($changes = (new Diff)($old->title, $new->title)) !== "") {
                $xtext .= "<p><em>title changed: </em>" . $changes . "</p>";
            }
            if (($changes = (new Diff)($old->xtext, $new->xtext)) !== "") {
                $xtext .= "<p><em>text changed: </em>" . $changes . "</p>";
            }
            if (strcmp($old->url ?? "", $new->url ?? "") !== 0) {
                $xtext .= "<p><em>link changed: </em><del>$old->url</del><ins>$new->url</ins></p>";
            }
            if (!empty($xtext)) {

                $xtext = "<p><em>-- article was edited by user, this diff generated by system --</em></p>" . $xtext;
                $description = (string)(new Text($xtext))->blurbify();

                // insert diff as comment
                Db::execute("INSERT INTO reactions(linkid, channelid, xtext, description) VALUES(:linkid, 1, :xtext, :description)",
                    [":linkid" => $new->id, ":xtext" => $xtext, ":description" => $description]);
            }
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function postLink(stdClass $link): stdClass
        {
            self::checkTranslation($link);
            self::checkMarkdown($link);
            self::checkTitle($link);
            self::checkUrl($link);
            self::checkImage($link);
            self::checkCopyright($link);
            if (sizeof($link->tags ?? []) === 0) {
                // one of the ParseDonw-filters collected tag candidates based on typograhpy
                $harvested_tags = TagHarvester::getTags();
                $link->tags = array_merge($link->tags, $harvested_tags, self::suggestTags($link->title, $link->description));
            }
            $link->tags = self::sanitizeTags($link->tags);

            $sqlparams = [
                ":channelid" => $link->channelid,
                ":url" => $link->url,
                ":title" => $link->title,
                ":markdown" => $link->markdown,
                ":xtext" => $link->xtext,
                ":description" => $link->description,
                ":image" => $link->image,
                ":mimetype" => $link->mimetype,
                ":language" => $link->language,
                ":copyright" => $link->copyright,
                ":published" => $link->published ? 1 : 0,
            ];

            if (empty($link->id)) {

                (new ServerException)(Db::execute(
                        "INSERT INTO links(url, channelid, title, markdown, xtext, description, image, mimetype, language, copyright, published)
                        VALUES (:url, :channelid, :title, :markdown, :xtext, :description, :image, :mimetype, :language, :copyright, :published)",
                        $sqlparams)->rowCount() > 0);
                $link->id = (int)Db::lastInsertId();

            } else {

                $sqlparams[":id"] = $link->id;

                // get old version for diff
                $old_link = Db::fetch("SELECT * FROM links WHERE id=:id", [":id" => $link->id]);

                if ($old_link->published and !$link->published) {
                    throw new UserException("Cannot unpublish only delete");
                }

                (new UserException("Unchanged"))(Db::execute(
                        "UPDATE links SET
                            url=:url, 
                            title=:title,
                            markdown=:markdown, 
                            xtext=:xtext,
                            description=:description,
                            image=:image, 
                            mimetype=:mimetype, 
                            language=:language, 
                            copyright=:copyright,
                            published=:published
                        WHERE id=:id AND channelid=:channelid", $sqlparams)->rowCount() >= 0);

                // remove the tags that this user / channel added
                Db::execute("DELETE FROM tags WHERE linkid=:id AND channelid=:channelid", [":id" => $link->id, ":channelid" => $link->channelid]);

                // create diff as reaction
                if ($link->published === true) {
                    // self::generateDiff($old_link, $link);
                }
            }

            // insert tags
            if (!empty($link->tags)) {
                /** @noinspection PhpCastIsUnnecessaryInspection */
                self::postTags((int)$link->id, (int)$link->channelid, $link->tags);
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

        static public function patchChannel(stdClass $channel): stdClass
        {
            $channel->name = (string)(new Text($channel->name))->convertToAscii()->hyphenize();
            if ($channel->channelid !== 1) {
                if (!in_array($channel->algorithm, ["channel", "voted", "mixed"])) {
                    throw new UserException("This channel only supports ('channel', 'voted', 'mixed')");
                }
            }
            try {
                if (!is_null($channel->avatar)) {
                    $resized_avatar = (string)(ImageResize::createFromString(file_get_contents($channel->avatar)))->crop(64, 64);
                    $channel->avatar = 'data://application/octet-stream;base64,' . base64_encode($resized_avatar);
                }
            } catch (Exception $e) {
                throw new UserException("Invalid file or filetype for avatar (use PNG, GIF, JPG)");
            }
            (new UserException("Unchanged"))(Db::execute("UPDATE channels SET 
                    name=:name, 
                    avatar=IFNULL(:avatar,avatar), 
                    header=IFNULL(:header,header), 
                    algorithm=IFNULL(:algorithm,algorithm),                
                    language=IFNULL(:language,language),                
                    bio=:bio,
                    bitcoinaddress=:bitcoinaddress 
                WHERE id=:channelid", [
                    ":name" => $channel->name,
                    ":avatar" => $channel->avatar,
                    ":header" => $channel->header,
                    ":algorithm" => $channel->algorithm,
                    ":language" => $channel->language,
                    ":bio" => $channel->bio,
                    ":bitcoinaddress" => $channel->bitcoinaddress,
                    ":channelid" => $channel->channelid,
                ])->rowCount() > 0);
            return $channel;
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

        static public function postReaction(stdClass $reaction): stdClass
        {
            // post exact same content as previewed
            self::previewReaction($reaction);

            (new UserException("Comment invalid or empty"))(strlen($reaction->xtext) > 0);
            Db::execute("CALL insert_reaction(:channelid,:linkid,:markdown,:xtext,:description)", [
                ":linkid" => $reaction->linkid,
                ":channelid" => $reaction->channelid,
                ":markdown" => $reaction->markdown,
                ":xtext" => $reaction->xtext,
                ":description" => $reaction->description]);

            $reaction->id = (int)Db::lastInsertId();

            return $reaction;
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function getSingleLink(string $id): array
        {
            // update view counter
            Db::execute("UPDATE links SET viewscount=viewscount+1 WHERE id=:id", [":id" => $id]);

            // get post
            // TODO must be authenticated for published=FALSE
            $link = (new ResourceNotFoundException)(Db::fetch("SELECT * FROM links 
                WHERE id=:id", [":id" => $id]));

            // some updates in the plugin system clear the xtext -> parse and store again
            if (empty($link->xtext)) {
                // parse and filter the original markdown into safe xhtml, if not done on INSERT
                $link->xtext = (string)(new Text($link->markdown ?? ""))->parseDown(new ParsedownFilter);
                Db::execute("UPDATE links SET xtext=:xtext WHERE id=:id", [":id" => $id, ":xtext" => $link->xtext]);
            }

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