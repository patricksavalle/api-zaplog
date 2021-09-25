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
    use Zaplog\Exception\ServerException;

    class Links
    {
        static public function postLinkFromUrl(string $channelid, string $url): string
        {
            $metadata = (new HtmlMetadata)($url);
            (New ServerException)(Db::execute("INSERT INTO links(url, channelid, title, description, image)
                        VALUES (:url, :channelid, :title, :description, :image)",
                    [
                        ":url" => $metadata["url"],
                        ":channelid" => $channelid,
                        ":title" => $metadata["title"],
                        ":description" => $metadata["description"],
                        ":image" => $metadata["image"],
                    ])->rowCount() > 0);

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
                    assert(preg_match("/[\w-]{3,50}/", $tag) > 0);
                    assert(substr_count($tag, "-") < 5);
                    Db::execute("INSERT IGNORE INTO tags(linkid, channelid, tag) VALUES (:linkid, :channelid, :tag)",
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
            // update the view counter
            Db::execute("UPDATE links SET viewscount = viewscount + 1 WHERE id=:id", [":id" => $id]);

            return [
                "link" => (new ResourceNotFoundException)(Db::fetch("SELECT * FROM links WHERE id=:id", [":id" => $id])),

                "tags" => Db::fetchAll("SELECT * FROM tags WHERE linkid=:id GROUP BY tag", [":id" => $id]),

                "related" => (new MemcachedFunction)([__CLASS__, 'getRelatedLinks'], [$id]),

                "interactors" => Db::fetchAll("SELECT DISTINCT * FROM channels_public_view 
                    WHERE id IN (SELECT channelid FROM reactions WHERE linkid=:id1
                        UNION SELECT channelid FROM tags WHERE linkid=:id2
                        UNION SELECT channelid FROM votes WHERE linkid=:id3)",
                    [":id1" => $id, ":id2" => $id, ":id3" => $id]),
            ];
        }
    }
}