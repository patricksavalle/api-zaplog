<?php

declare(strict_types=1);

namespace Zaplog\Model {

    require_once BASE_PATH . '/Exception/ResourceNotFoundException.php';

    use Exception;
    use SlimRestApi\Infra\Db;
    use ContentSyndication\HtmlMetadata;
    use ContentSyndication\NormalizedText;
    use SlimRestApi\Infra\MemcachedFunction;
    use Zaplog\Exception\ResourceNotFoundException;

    class Links
    {
        static public function postLinkFromUrl(string $channelid, string $url): string
        {
            $metadata = (new HtmlMetadata)($url);
            if (Db::execute("INSERT INTO links(url, channelid, title, description, image)
                        VALUES (:url, :channelid, :title, :description, :image)",
                    [
                        ":url" => $metadata["url"],
                        ":channelid" => $channelid,
                        ":title" => $metadata["title"],
                        ":description" => $metadata["description"],
                        ":image" => $metadata["image"],
                    ])->rowCount() === 0
            ) {
                throw new Exception;
            }

            /** @noinspection PhpUndefinedMethodInspection */
            $linkid = Db::lastInsertId();

            // remove duplicate keywords after normalisation
            $keywords = [];
            foreach ($metadata['keywords'] as $tag) {
                $keywords[] = (new NormalizedText($tag))->convertToAscii()->hyphenizeForPath()->get();
            }
            $metadata['keywords'] = array_unique($keywords);

            // insert keywords into database
            foreach ($metadata['keywords'] as $tag) {
                try {
                    // only accept reasonable tags
                    $tag = (new NormalizedText($tag))->convertToAscii()->hyphenizeForPath()->get();
                    assert(preg_match("/[\w-]{3,50}/", $tag) !== false);
                    assert(substr_count($tag, "-") < 5);
                    Db::execute("INSERT INTO tags(linkid, channelid, tag) VALUES (:linkid, :channelid, :tag)",
                        [
                            ":linkid" => $linkid,
                            ":channelid" => $channelid,
                            ":tag" => $tag,
                        ]);
                } catch (Exception $e) {
                    error_log($e->getMessage() . " @ " . __METHOD__ . "(" . __LINE__ . ") " . $tag);
                }
            }
            return $linkid;
        }

        static public function getRelatedLinks($id): array
        {
            return Db::fetchAll("SELECT GROUP_CONCAT(tags.tag SEPARATOR ',') AS tags, links.*
                FROM links JOIN tags ON tags.linkid=links.id AND links.id<>:id1
                WHERE tag IN (SELECT tags.tag FROM links JOIN tags on tags.linkid=links.id WHERE links.id=:id2) 
                GROUP BY links.id ORDER BY COUNT(tag) DESC, SUM(links.score) DESC LIMIT 5",
                [":id1" => $id, ":id2" => $id]);
        }

        static public function getSingleLink($id): array
        {
            // get the link itself
            $link = Db::fetch("SELECT * FROM links WHERE id=:id", [":id" => $id]);
            if (!$link) throw new ResourceNotFoundException;

            // get its tags
            $tags = Db::fetchAll("SELECT * FROM tags WHERE linkid=:id GROUP BY tag", [":id" => $id]);

            // get and cache the links related by tags
            $rels = (new MemcachedFunction)([__CLASS__, 'getRelatedLinks'], [$id]);

            // get all interactions on the link, like voting, tagging, bookmarking, reacting
            $interaction = Activities::get(0, 25, NULL, $id);

            // update the view counter
            Db::execute("UPDATE links SET viewscount = viewscount + 1 WHERE id=:id", [":id" => $id]);

            return [
                "link" => $link,
                "tags" => $tags,
                "related" => $rels,
                "interaction" => $interaction,
            ];
        }
    }
}