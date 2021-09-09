<?php

declare(strict_types=1);

namespace Zaplog\Model {

    require_once BASE_PATH . '/Exception/ResourceNotFoundException.php';

    use SlimRestApi\Infra\Db;

    class Activities
    {
        static public function get($offset, $count, $channelid, $linkid): array
        {
            return Db::execute("SELECT * FROM activitystream 
                    WHERE (channelid=:channelid1 IS NULL OR channelid=:channelid2)
                    AND (linkid=:linkid1 IS NULL OR linkid=:linkid2)
                    LIMIT :offset, :count",
                [
                    ":channelid1" => $channelid,
                    ":channelid2" => $channelid,
                    ":linkid1" => $linkid,
                    ":linkid2" => $linkid,
                    ":count" => $count,
                    ":offset" => $offset,
                ])->fetchAll();
        }
    }
}