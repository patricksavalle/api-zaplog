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

    class Channels
    {
        static public function getChannelTags(string $id): array
        {
            return Db::fetchAll("SELECT tag, COUNT(tag) AS tagscount 
                FROM tags JOIN links ON tags.linkid=links.id  
                WHERE links.channelid=:channelid 
                GROUP BY tag ORDER BY SUM(score) DESC LIMIT 10",
                [":channelid" => $id]);
        }

        static public function getRelatedChannels(string $id): array
        {
            return Db::fetchAll("SELECT GROUP_CONCAT(DISTINCT tags.tag SEPARATOR ',' LIMIT 10) AS tags, channels.*
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
                ["channelid1" => $id, "channelid2" => $id]);
        }

        static public function getSingleChannel(string $id): array
        {
            return [
                "channel" => Db::fetch("SELECT * FROM channels_public_view WHERE id=:id", [":id" => $id]),

                "tags" => (new MemcachedFunction)([__CLASS__, 'getChannelTags'], [$id], 60 * 60),

                "related" => (new MemcachedFunction)([__CLASS__, 'getRelatedChannels'], [$id], 60 * 60),

                "activity" => Activities::get(0, 25, $id, null),
            ];
        }
    }
}