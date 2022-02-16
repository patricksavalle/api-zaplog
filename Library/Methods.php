<?php
/** @noinspection DuplicatedCode */
/** @noinspection PhpUndefinedMethodInspection */

declare(strict_types=1);

namespace Zaplog\Library {

    require_once BASE_PATH . '/Exception/ResourceNotFoundException.php';

    use ContentSyndication\ArchiveOrg;
    use ContentSyndication\HtmlMetadata;
    use ContentSyndication\Mimetype;
    use ContentSyndication\Text;
    use Exception;
    use Gumlet\ImageResize;
    use Jfcherng\Diff\DiffHelper;
    use LanguageDetector\LanguageDetector;
    use SlimRestApi\Infra\Db;
    use SlimRestApi\Infra\Ini;
    use stdClass;
    use Zaplog\Exception\ResourceNotFoundException;
    use Zaplog\Exception\ServerException;
    use Zaplog\Exception\UserException;
    use Zaplog\Plugins\ParsedownFilter;
    use Zaplog\Plugins\ParsedownFilters\TagHarvester;

    class Methods
    {
        // fields to select when returning a list of links
        static $blurbfields = "id,channelid,createdatetime,updatedatetime,published,url,language,title,copyright,description,image";

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function getPaymentShares(): array
        {
            $in_address = Db::fetchAll("SELECT bitcoinaddress FROM channels WHERE id=1");
            $channels = Db::fetchAll("SELECT avatar, name, reputation AS share FROM channels WHERE NOT bitcoinaddress IS NULL ORDER BY reputation DESC LIMIT 50");
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

        /** @noinspection PhpUnusedLocalVariableInspection */
        static public function getChannelLinks(string $channelid, int $offset, int $count): array
        {
            // - this endpoint is cached
            // - these closures are called after the values in ENUM channels.algorithm

            // channel displays all posts made by this channel
            $channel = $all = function (int $channelid, int $offset, int $count): array {
                return Db::fetchAll("SELECT " . self::$blurbfields . " FROM links WHERE published=TRUE 
                    AND channelid=:channelid ORDER BY createdatetime DESC LIMIT :offset, :count",
                    [":channelid" => $channelid, ":offset" => $offset, ":count" => $count]);
            };

            // channel displays posts voted upon by this channel
            $popular = $voted = function (int $channelid, int $offset, int $count): array {
                return Db::fetchAll("SELECT " . self::$blurbfields . " FROM links  
                    WHERE id IN (SELECT linkid FROM votes WHERE channelid=:channelid) 
                    AND links.published=TRUE ORDER BY links.id DESC LIMIT :offset, :count",
                    [":channelid" => $channelid, ":offset" => $offset, ":count" => $count]);
            };

            // channel displays voted AND channel
            $mixed = function (int $channelid, int $offset, int $count): array {
                return Db::fetchAll("SELECT " . self::$blurbfields . " FROM (
                        SELECT * FROM links WHERE channelid=:channelid1 AND published=TRUE  
                        UNION DISTINCT 
                        SELECT links.* FROM links JOIN votes ON links.id=votes.linkid 
                        WHERE votes.channelid=:channelid2 AND links.published=TRUE 
                    ) AS x ORDER BY createdatetime DESC LIMIT :offset, :count",
                    [":channelid1" => $channelid, ":channelid2" => $channelid, ":offset" => $offset, ":count" => $count]);
            };

            $properties = Db::fetch("SELECT algorithm, id FROM channels WHERE name=:id", [":id" => $channelid]);
            if ($properties === false) {
                throw new UserException("Channel $channelid not found");
            }
            return ${$properties->algorithm}($properties->id, $offset, $count);
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        /** @noinspection PhpUnusedLocalVariableInspection */
        static public function getFrontpage(int $count): array
        {
            // - this endpoint is cached
            // - these closures are called after the values in ENUM channels.algorithm

            // displays all articles in the system om frontpage
            $all = function (int $count): array {
                return Db::fetchAll("SELECT " . self::$blurbfields . " FROM links WHERE published=TRUE 
                    ORDER BY createdatetime DESC LIMIT :count", [":count" => $count]);
            };

            // displays channel 1 articles on frontpage
            $channel = function (int $count): array {
                return Db::fetchAll("SELECT " . self::$blurbfields . " FROM links WHERE channelid=1 AND published=TRUE 
                    ORDER BY createdatetime DESC LIMIT :count", [":count" => $count]);
            };

            // displays currently popular articles on channel
            $popular = function (int $count): array {
                return Db::fetchAll("SELECT " . self::$blurbfields . " FROM frontpage LIMIT :count", [":count" => $count]);
            };

            // display articles voted upon by channel 1 on frontpage (redacted)
            $voted = function (int $count): array {
                return Db::fetchAll("SELECT " . self::$blurbfields . " FROM links  
                    WHERE id IN (SELECT linkid FROM votes WHERE channelid=1)
                    AND published=TRUE ORDER BY createdatetime DESC LIMIT :count", [":count" => $count]);
            };

            // displays popular + voted on frontpage
            $mixed = function (int $count): array {
                return Db::fetchAll("SELECT " . self::$blurbfields . " FROM (
                    SELECT links.* FROM frontpage JOIN links ON frontpage.id=links.id 
                    UNION DISTINCT 
                    SELECT links.* FROM links JOIN votes ON links.id=votes.linkid 
                    WHERE links.published=TRUE AND votes.channelid=1 
                ) AS links ORDER BY createdatetime DESC LIMIT :count", [":count" => $count]);
            };

            $trendingTopics = function (array $linkids): array {
                return Db::fetchAll("SELECT tag FROM tags WHERE linkid IN ('" . implode("','", $linkids) . "') 
                    GROUP BY tag ORDER BY COUNT(tag) DESC LIMIT 25");
            };

            $trendingChannels = function (array $channelids): array {
                return Db::fetchAll("SELECT channels.* FROM channels WHERE id IN ('" . implode("','", $channelids) . "') 
                    GROUP BY id ORDER BY SUM(score) DESC LIMIT 25");
            };

            $trendingReactions = function (): array {
                return Db::fetchAll("SELECT * FROM topreactions");
            };

            // channel 1 is the master channel, determines site frontpage
            $frontpage = ${Db::fetch("SELECT algorithm FROM channels WHERE id=1")->algorithm}($count);

            return [
                "trendinglinks" => $frontpage,
                "trendingtags" => $trendingTopics(array_column($frontpage, "id")),
                "trendingchannels" => $trendingChannels(array_column($frontpage, "channelid")),
                "trendingreactions" => $trendingReactions(),
            ];
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function getArchive(int $offset, int $count): array
        {
            return Db::fetchAll("SELECT " . self::$blurbfields . " FROM links ORDER BY createdatetime DESC LIMIT :offset,:count",
                [":offset" => $offset, ":count" => $count]);
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function getArchivePage(int $offset, int $count, ?string $search): array
        {
            // - this endpoint is cached

            $associatedTags = function (array $linkids): array {
                return Db::fetchAll("SELECT tag FROM tags 
                    WHERE linkid IN ('" . implode("','", $linkids) . "') 
                    GROUP BY tag ORDER BY COUNT(tag) DESC LIMIT 25");
            };

            $associatedChannels = function (array $channelids): array {
                return Db::fetchAll("SELECT id, name, avatar FROM channels 
                    WHERE id IN ('" . implode("','", $channelids) . "') 
                    GROUP BY name ORDER BY COUNT(name) DESC, reputation DESC LIMIT 25");
            };

            // build a query
            $use_order_by = true;
            $sql = "SELECT " . self::$blurbfields . " FROM links WHERE published=TRUE";

            // we added #tag and @channel operators to the MATCHES AGAINST syntax of MySQL
            if ($search !== null) {

                // extract channels
                preg_match_all('/@([\w-]+)/', $search, $matches);
                $search = preg_replace('/@[\w-]+/', "", $search);
                // create the SQL for optional channel matching  (note beware of SQL injection in this case)
                if (!empty($matches[1])) {
                    $names = "('" . implode("','", $matches[1]) . "')";
                    $sql .= " AND id IN (SELECT links.id FROM links JOIN channels ON links.channelid=channels.id WHERE name IN $names)";
                }

                // extract tags
                preg_match_all('/#([\w-]+)/', $search, $matches);
                $search = preg_replace('/#[\w-]+/', "", $search);
                // create the SQL for optional tag matching (note beware of SQL injection in this case)
                if (!empty($matches[1])) {
                    $tags = "('" . implode("','", $matches[1]) . "')";
                    $sql .= " AND id IN (SELECT linkid FROM tags WHERE tag IN $tags)";
                }

                if (!empty(trim($search))) {
                    if (preg_match("/[()\"~+\-<>]/", $search) === 1) {
                        $sql .= " AND (MATCH(markdown) AGAINST(:search IN BOOLEAN MODE))";
                    } else {
                        $sql .= " AND (MATCH(markdown) AGAINST(:search IN NATURAL LANGUAGE MODE))";
                        $use_order_by = false;
                    }
                    $args[":search"] = $search;
                }
            }

            $sql .= ($use_order_by ? " ORDER BY createdatetime DESC" : "");
            $sql .= " LIMIT :offset,:count";
            $args[":count"] = $count;
            $args[":offset"] = $offset;

            // run the query
            $links = Db::fetchAll($sql, $args);

            return [
                "links" => $links,
                "tags" => $associatedTags(array_column($links, 'id')),
                "channels" => $associatedChannels(array_column($links, 'channelid')),
                "lastmodified" => $use_order_by ? $links[0]->createdatetime ?? null : null,
            ];
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function getActivityStream(int $offset, int $count, ?string $channelid): array
        {
            return Db::fetchAll("SELECT
                        interactions.*,
                        channels.name AS channelname,
                        channels.avatar AS channelavatar,
                        links.title AS linktitle,
                        links.published AS linkpublished,
                        links.image AS linkimage,
                        links.description AS linktext,
                        reactions.description AS reactiontext
                    FROM interactions 
                    LEFT JOIN channels ON channels.id=interactions.channelid
                    LEFT JOIN links ON links.id=interactions.linkid  
                    LEFT JOIN reactions ON reactions.id=interactions.reactionid
					WHERE (:channelid1 IS NULL OR links.channelid=(SELECT id FROM channels WHERE name=:channelid2))
                    ORDER BY interactions.id DESC
                    LIMIT :offset, :count",
                [
                    ":channelid1" => $channelid,
                    ":channelid2" => $channelid,
                    ":count" => $count,
                    ":offset" => $offset,
                ]
            );
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function getSingleChannel(string $name): array
        {
            $related = function (int $id, array $tags): array {
                return Db::fetchAll("SELECT channels.* FROM channels
                    JOIN links ON channels.id=links.channelid
                    JOIN tags ON links.id=tags.linkid
                    WHERE tag IN ('" . implode("','", $tags) . "') AND channels.id<>:channelid 
                    GROUP BY channels.id ORDER BY COUNT(tag) DESC LIMIT 5",
                    ["channelid" => $id], 60 * 60);
            };

            $tags = function (int $id): array {
                return Db::fetchAll("SELECT tag FROM tags WHERE tags.linkid IN (
                        SELECT id FROM links WHERE channelid=:channelid AND published=TRUE AND createdatetime > SUBDATE(CURRENT_TIMESTAMP, INTERVAL 1 YEAR)
                    ) GROUP BY tag ORDER BY COUNT(tag) DESC LIMIT 20",
                    [":channelid" => $id], 60 * 60);
            };

            $channel = (new ResourceNotFoundException("Channel $name not found"))(Db::fetch("SELECT * FROM channels WHERE name=:id", [":id" => $name]));
            $tags = $tags($channel->id);
            return [
                "channel" => $channel,
                "tags" => $tags,
                "related" => $related($channel->id, array_column($tags, "tag")),
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

        static public function postTags(int $linkid, array $tags)
        {
            // insert keywords into database;
            foreach ($tags as $tag)
                Db::execute("INSERT IGNORE INTO tags(linkid, tag) VALUES (:linkid, :tag)",
                    [":linkid" => $linkid, ":tag" => $tag]);
            return Db::lastInsertid();
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function checkImage(stdClass $link)
        {
            if (empty($link->image)) {
                $link->image = TagHarvester::getFirstImage();
            }
            if (!empty($link->image)) {
                if (strlen($link->image) > 256) {
                    $link->image = null;
                } else {
                    $image_mimetype = "";
                    try {
                        $image_mimetype = (new Mimetype)($link->image);
                    } catch (Exception $e) {
                        // nothing
                    }
                    if (strpos($image_mimetype, "image/") !== 0) {
                        $link->image = null;
                    }
                }
            } else {
                // explicitely null
                $link->image = null;
            }
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function checkMarkdown(stdClass $link)
        {
            (new UserException("Empty markdown"))(!empty($link->markdown));
            (new UserException("Markdown exceeds 100k chars"))(strlen($link->markdown) < 100000);
            $link->markdown = (string)(new Text($link->markdown))->reEncode();
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function checkTitle(stdClass $link)
        {
            if (empty($link->title)) {
                $link->title = TagHarvester::getTitle();
            }
            $link->title = (string)(new Text(str_replace('"', "'", substr($link->title, 0, 256))))->stripTags()->reEncode();
            (new UserException("Title too short"))(strlen($link->title) > 3);
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function checkLink(stdClass $link)
        {
            if (empty($link->url)) {
                $link->url = null;
                $link->mimetype = null;
                $link->image = null;
            } else {
                try {
                    $metadata = (new HtmlMetadata)($link->url);
                    $link->mimetype = (new Mimetype)($link->url);
                    // get original URL if an archive,org URL was submitted (conflicting with our GOTO mechanism)
                    $link->url = ArchiveOrg::originalUrl($metadata['url']);
                    $link->image = $metadata['image'];
                } catch (Exception $e) {
                    throw new UserException($e->getMessage() . ": " . $link->url);
                }
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

        static public function translateMarkdown(stdClass $link, stdClass $channel)
        {
            // if this is an anonymous post
            if (empty($link->channelid)) {
                return;
            }

            $link->orig_language = null;
            $link->language = (string)(new LanguageDetector)->evaluate($link->markdown);
            if (strlen($link->language ?? "") !== 2) {
                throw new ServerException("Error detecting language");
            }

            // do we need to translate anything?
            if (!Ini::get("auto_translate")) {
                return;
            }

            // do we need to translate anything?
            $system_language = Db::fetch("SELECT language FROM channels WHERE id=1")->language;
            if (empty($system_language) or $link->language === $system_language) {
                return;
            }

            // check quotum
            // TODO move to ini
            $quotum = 10000;
            $super_reputation = 500;
            $size = strlen($link->markdown);
            $remaining = $quotum - $channel->deeplusage;
            if ($channel->reputation < $super_reputation and ($remaining < $size)) {
                throw new UserException("This text exceeds your remaining translation quotum of $remaining chars for this month");
            }

            // translate
            [$translation, $source_language] = (new Translation)($link->markdown, $system_language);
            $link->markdown = $translation;
            $link->orig_language = $source_language;
            [$translation, $source_language] = (new Translation)($link->title, $system_language, $source_language);
            $link->title = $translation;
            $link->language = $system_language;

            // clear tags )
            if ($source_language !== $system_language) {
                $link->tags = [];
            }

            // update quotum
            Db::execute("UPDATE channels SET deeplusage = deeplusage + :size WHERE id=:id",
                [":id" => $link->channelid, ":size" => $size]);
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function parseMarkdown(stdClass $link)
        {
            // render article text
            $link->xtext = (string)(new Text($link->markdown))->parseDown(new ParsedownFilter);
            assert(mb_check_encoding($link->xtext, 'UTF-8'));
            $link->description = (string)(new Text($link->xtext))->blurbify();
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function generateDiff(stdClass $old, stdClass $new)
        {
            // see: https://github.com/jfcherng/php-diff/blob/v6/example/demo_web.php

            // options for Diff class
            $diffOptions = [
                'context' => 0,
                'ignoreCase' => true,
                'ignoreWhitespace' => true,
            ];

            // options for renderer class
            $rendererOptions = [
                'detailLevel' => 'word',
                'showHeader' => false,
                'spacesToNbsp' => false,
                'mergeThreshold' => 0.8,
                'wordGlues' => [' ', '-'],
                'resultForIdenticals' => null,
            ];

            $diff = function (string $old, string $new) use ($diffOptions, $rendererOptions) {
                return html_entity_decode(DiffHelper::calculate($old, $new, 'Combined', $diffOptions, $rendererOptions));
            };

            $xtext = "";
            if (strcmp($old->copyright, $new->copyright) !== 0) {
                $xtext .= "<p><em>copyright changed: </em><del>$old->copyright</del><ins>$new->copyright</ins></p>";
            }
            if (($changes = $diff($old->title, $new->title)) !== "") {
                if (strlen(strip_tags($changes)) > 0) {
                    $xtext .= "<p><em>title changed: </em>" . $changes . "</p>";
                }
            }
            if (($changes = $diff(strip_tags($old->xtext), strip_tags($new->xtext))) !== "") {
                if (strlen(strip_tags($changes)) > 0) {
                    $xtext .= "<p><em>text changed: </em>" . $changes . "</p>";
                }
            }
            if (strcmp($old->url ?? "", $new->url ?? "") !== 0) {
                $xtext .= "<p><em>link changed: </em><del>$old->url</del><ins>$new->url</ins></p>";
            }
            if (!empty($xtext)) {

                $xtext = "<p><em>-- article was edited by user, this diff generated by system --</em></p>" . $xtext;
                $description = (string)(new Text($xtext))->blurbify();

                // insert diff as comment
                Db::execute("CALL insert_reaction(1, :linkid, null, :xtext, :description)",
                    [":linkid" => $new->id, ":xtext" => $xtext, ":description" => $description]);
            }
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function checkTags(stdClass $link)
        {
            $link->tags = self::sanitizeTags(array_merge(TagHarvester::getTags(), $link->tags ?? []));
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function postLink(stdClass $link, stdClass $channel): stdClass
        {
            $link->channelid = $channel->id;
            self::checkMarkdown($link);
            self::translateMarkdown($link, $channel);
            self::parseMarkdown($link);
            self::checkTitle($link);
            self::checkLink($link);
            self::checkImage($link);
            self::checkCopyright($link);
            self::checkTags($link);

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
                ":orig_language" => $link->orig_language,
                ":copyright" => $link->copyright,
            ];

            if (empty($link->id)) {

                (new ServerException)(Db::execute(
                        "INSERT INTO links(url, channelid, title, markdown, xtext, description, image, mimetype, language, orig_language, copyright, published)
                        VALUES (:url, :channelid, :title, :markdown, :xtext, :description, :image, :mimetype, :language, :orig_language, :copyright, FALSE)",
                        $sqlparams)->rowCount() > 0);
                $link->id = (int)Db::lastInsertId();

            } else {

                $sqlparams[":id"] = $link->id;

                // get old version for diff
                $old_link = Db::fetch("SELECT * FROM links WHERE id=:id", [":id" => $link->id]);

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
                            orig_language=IFNULL(orig_language,:orig_language), 
                            copyright=:copyright
                        WHERE id=:id AND channelid=:channelid", $sqlparams)->rowCount() >= 0);

                // remove the tags that this user / channel added
                Db::execute("DELETE FROM tags WHERE linkid=:id", [":id" => $link->id]);

                // create diff as reaction
                self::generateDiff($old_link, $link);
            }

            // insert tags
            if (!empty($link->tags)) {
                /** @noinspection PhpCastIsUnnecessaryInspection */
                self::postTags((int)$link->id, $link->tags);
            }

            // archive the link
            try {
                // if (!empty($link->url)) ArchiveOrg::archiveAsync($link->url);
            } catch (Exception $e) {
                error_log($e->getMessage() . $link->url);
            }

            return $link;
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function publishLink(int $id, int $channelid): bool
        {
            // check quota
            $check = Db::fetch("SELECT 
            	(SELECT COUNT(*) FROM links WHERE channelid=:channelid1 AND createdatetime > SUBDATE(CURRENT_TIMESTAMP, INTERVAL 6 HOUR)) AS article_count, 
	            (SELECT reputation FROM channels WHERE id=:channelid2) AS channel_reputation",
                [":channelid1" => $channelid, ":channelid2" => $channelid]);
            if ($check->channel_reputation < 500.0 and $check->article_count > 4) {
                throw new UserException("Max 4 articles can be published per 6h");
            }
            // publish
            if (Db::execute("UPDATE links SET published=TRUE WHERE id=:id and channelid=:channelid",
                    [":id" => $id, ":channelid" => $channelid])->rowCount() !== 1) {
                throw new ServerException("Can't publish this article");
            }
            // remove reactions on concept
            Db::execute("DELETE FROM reactions WHERE linkid=:id", [":id" => $id]);
            return true;
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function patchChannel(stdClass $channel): stdClass
        {
            $channel->name = (string)(new Text($channel->name))->convertToAscii()->hyphenize();
            try {
                if (!is_null($channel->avatar)) {
                    $resized_avatar = (string)(ImageResize::createFromString(file_get_contents($channel->avatar)))->crop(64, 64);
                    $channel->avatar = 'data://application/octet-stream;base64,' . base64_encode($resized_avatar);
                }
            } catch (Exception $e) {
                throw new UserException("Invalid file or filetype for avatar (use PNG, GIF, JPG)");
            }
            (new UserException("Name already in use"))(Db::fetch("SELECT * FROM channels WHERE name=:name AND id<>:id",
                    [":name" => $channel->name, ":id" => $channel->channelid]) === false);
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
            $reaction->xtext = (string)(new Text($reaction->markdown))->stripTags()->parseDown(new ParsedownFilter)->reEncode();
            $reaction->description = (string)(new Text($reaction->xtext))->blurbify()->reEncode();
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
            $channel = function (int $id): stdClass {
                return Db::fetchAll("SELECT * FROM channels WHERE id=:id", [":id" => $id], 60)[0];
            };

            $tags = [];

            $related = function (string $id, int $cachettl): array {
                return Db::fetchAll("SELECT links.id, links.description, links.createdatetime, links.channelid, links.title, links.image
                    FROM links JOIN tags ON tags.linkid=links.id AND links.id<>:id1
                    WHERE tag IN (SELECT tag FROM tags WHERE linkid=:id2) AND published=TRUE
                    GROUP BY links.id ORDER BY COUNT(tag) DESC, SUM(links.score) DESC LIMIT 5",
                    [":id1" => $id, ":id2" => $id], $cachettl);
            };

            $interactors = function (string $id): array {
                // never cache this query!
                return Db::fetchAll("SELECT c.id, c.name, c.avatar, GROUP_CONCAT(action) AS interactions FROM channels AS c JOIN
                    (
                        SELECT channelid, 'links' AS action FROM links WHERE id=:id1
                        UNION SELECT channelid, 'reactions' AS action FROM reactions WHERE linkid=:id2
                        UNION SELECT channelid, 'votes' AS action FROM votes WHERE linkid=:id3
                    ) AS i ON i.channelid=c.id GROUP BY c.id",
                    [":id1" => $id, ":id2" => $id, ":id3" => $id]);
            };

            // update view counter and get complete article in a single Db call
            $link = (new ResourceNotFoundException("Invalid id"))(Db::fetch("CALL select_link(:id)", [":id" => $id]));
            foreach (explode(",", $link->tags ?? "") as $tag) $tags[] = ["tag" => $tag];
            unset($link->tags);

            return [
                "link" => $link,
                "tags" => $tags,
                "channel" => $channel($link->channelid),
                "related" => $related($id, $link->published ? 60 * 20 : 0),
                "interactors" => $interactors($id),
            ];
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function getTopChannelsForTag(string $tag, int $count): array
        {
            return Db::fetchAll("SELECT channels.* FROM channels
                        JOIN links ON channels.id=links.channelid
                        JOIN tags ON tags.linkid=links.id
                        WHERE tag=:tag AND links.published=TRUE 
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
                WHERE links.id IN (SELECT links.id FROM links JOIN tags ON tags.linkid=links.id 
                WHERE tag=:tag1) AND links.published=TRUE AND tag<>:tag2
                GROUP BY tag ORDER BY COUNT(tag) DESC, SUM(links.score) DESC LIMIT :count",
                [":tag1" => $tag, ":tag2" => $tag, ":count" => $count], 60 * 60);
        }

        // ----------------------------------------------------------
        // Show latest X reactions of selected Y threads
        // ----------------------------------------------------------

        /** @noinspection PhpUnusedParameterInspection */
        static public function getDiscussion(?string $channelid, int $offset, int $count, int $numreactions = 3): array
        {
            // first fetch all threadid's
            $sql = "SELECT DISTINCT threadid FROM reactions ORDER BY threadid DESC LIMIT :offset, :count";
            $threadids = "('" . implode("','", array_column(Db::fetchAll($sql, [":offset" => $offset, ":count" => $count]), "threadid")) . "')";

            // fetch first 3 reactions for selected threads
            return Db::fetchAll("SELECT
                    reactions.*,
                    channels.name,
                    channels.avatar,
                    IF(rownum=1,links.title,NULL) as title,
                    IF(rownum=1,links.image,NULL) as image,
                    IF(rownum=1,links.published,NULL) AS linkpublished,
                    IF(rownum=1,links.createdatetime,NULL) AS linkdatetime
                FROM (
                    SELECT 
                        threadid, 
                        id, 
                        channelid, 
                        linkid,
                        published,
                        (@num:=if(@threadid = threadid, @num +1, if(@threadid := threadid, 1, 1))) AS rownum,
                        createdatetime,
						description
                    FROM reactions WHERE threadid IN $threadids AND published=TRUE
                    ORDER BY threadid DESC, id DESC
                ) AS reactions
                JOIN channels ON channels.id=reactions.channelid
                JOIN links ON links.id=reactions.linkid  
                WHERE reactions.rownum <= :reactions AND reactions.published=TRUE
                ORDER by reactions.threadid DESC, rownum", [":reactions" => $numreactions]);
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
                    reactions.votescount,
                    channels.name, 
                    channels.avatar
                FROM reactions 
                JOIN links ON reactions.linkid=links.id 
                JOIN channels ON channels.id=reactions.channelid
                WHERE linkid=:id AND reactions.published=TRUE 
                ORDER BY reactions.id", [":id" => $linkid]);
        }

        // ----------------------------------------------------------
        //
        // ----------------------------------------------------------

        static public function getReactions(int $offset, int $count, ?string $id = null): array
        {
            return Db::fetchAll("SELECT 
                    reactions.id, 
                    reactions.linkid, 
                    reactions.channelid, 
                    reactions.description, 
                    reactions.createdatetime,
                    reactions.votescount,
                    channels.name, 
                    channels.avatar,
                    links.title,
                    links.image,
                    links.createdatetime AS linkdatetime
				FROM (
					SELECT * FROM reactions 
                    WHERE published=TRUE 
                    AND channelid<>1 
                    AND (
                        :id1 IS NULL OR linkid IN (
                            SELECT links.id FROM links JOIN channels ON links.channelid=channels.id 
                            WHERE name=:id2 AND published=TRUE
                        )
                    ) 
					ORDER BY reactions.createdatetime DESC LIMIT :offset, :count
				) AS reactions
                JOIN channels ON channels.id=reactions.channelid
                JOIN links ON links.id=reactions.linkid",
                [
                    ":offset" => $offset,
                    ":count" => $count,
                    ":id1" => $id,
                    ":id2" => $id,
                ]);
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