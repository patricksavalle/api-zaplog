<?php

declare(strict_types=1);

namespace Zaplog\Model {

    require_once BASE_PATH . '/Model/Links.php';

    use Exception;
    use SlimRestApi\Infra\Db;
    use ContentSyndication\Url;
    use ContentSyndication\XmlFeed;

    class FeedReader
    {
        public function refreshSingleFeed(string $channelid, string $feedurl)
        {
            $content = (new XmlFeed)($feedurl);
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

                        Links::postLinkFromUrl($channelid, $link);
                    }
                } catch (Exception $e) {
                    error_log($e->getMessage() . " @ " . __METHOD__ . "(" . __LINE__ . ") " . $link);
                }
            }
            Db::execute("UPDATE channels SET refeeddatetime=NOW() WHERE id=:id", [":id" => $channelid]);
        }

        public function refreshAllFeeds()
        {
            foreach (Db::execute("SELECT id, feedurl FROM channels WHERE NOT feedurl IS NULL ORDER BY RAND()")->fetchAll() as $channel) {
                try {
                    $this->refreshSingleFeed((string)$channel->id, $channel->feedurl);
                } catch (Exception $e) {
                    error_log($e->getMessage() . " @ " . __METHOD__ . "(" . __LINE__ . ") " . $channel->feedurl);
                }
            }
        }
    }
}
