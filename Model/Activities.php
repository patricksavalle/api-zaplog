<?php

declare(strict_types=1);

namespace Zaplog\Model {

    require_once BASE_PATH . '/Exception/ResourceNotFoundException.php';

    use SlimRestApi\Infra\Db;
    use stdClass;

    class Activities
    {
        static public function get($offset, $count, $channelid, $linkid): array
        {
            $stream = Db::execute("SELECT * FROM activitystream 
                    WHERE (channelid=:channelid1 IS NULL OR channelid=:channelid2)
                    AND (linkid=:linkid1 IS NULL OR linkid=:linkid2)
                    ORDER BY id DESC
                    LIMIT :offset, :count",
                [
                    ":channelid1" => $channelid,
                    ":channelid2" => $channelid,
                    ":linkid1" => $linkid,
                    ":linkid2" => $linkid,
                    ":count" => $count,
                    ":offset" => $offset,
                ])->fetchAll();

            // postprocessing, group consequtive rows by same channel, same type, same link
            // o(n) inefficiency

            $compare = function (stdClass $item1, stdClass $item2): bool {
                return $item1->channelid === $item2->channelid
                    and $item1->linkid === $item2->linkid
                    and $item1->type === $item2->type;
            };

            $find = function(stdClass $item, iterable $iterable, callable $compare): bool {
                foreach ($iterable as $check) {
                    if ($compare($check, $item)) {
                        return true;
                    }
                }
                return false;
            };

            $stream2 = [];
            foreach ($stream as $value) {
                if (!$find($value,$stream2, $compare)) {
                    $stream2[] = $value;
                }
            }
            return $stream2;
        }
    }
}