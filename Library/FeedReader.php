<?php

declare(strict_types=1);

namespace Zaplog\Library {

    require_once 'Feed.php';

    use Exception;
    use SlimRestApi\Infra\Db;

    class FeedReader
    {
        public function __invoke()
        {
            $channels = Db::execute("SELECT * FROM channels WHERE NOT feedurl IS NULL")->fetchAll();

            foreach ($channels as $channel) {

                try {

                    $content = (new Feed)($channel->feedurl);
                    foreach ($content["item"] as $item) {

                        $link = (new Url($item["link"]))->normalized();
                        if (DB::execute("SELECT * FROM links WHERE urlhash=MD5(:url)", [":url" => $link])->rowCount() === 0) {

                            // --------------------------------------------------------------------------
                            // we do not use the content of the feeds because many feeds have no images
                            // instead we harvest the metadata from the articles themselves. Also
                            // bypasses Feedburner links (we need the original links)
                            // --------------------------------------------------------------------------

                            try {
                                $metadata = (new HtmlMetadata)($link);
                                if (Db::execute("INSERT IGNORE INTO links(url,channelid,title,description,image)
                                        VALUES (:url, :channel, :title, :description, :image)",
                                        [
                                            ":url" => $metadata["url"],
                                            ":channel" => $channel->id,
                                            ":title" => $metadata["title"],
                                            ":description" => $metadata["description"],
                                            ":image" => $metadata["image"] ?? $content["ímage"]["url"] ?? null,
                                        ])->rowCount() == 0
                                ) {
                                    continue;
                                }
                                /** @noinspection PhpUndefinedMethodInspection */
                                $linkid = Db::lastInsertId();
                                foreach ($metadata['keywords'] as $tag) {
                                    Db::execute("INSERT INTO tags(linkid, channelid, tag) VALUES (:linkid, :channelid, :tag)",
                                        [
                                            ":linkid" => $linkid,
                                            ":channelid" => $channel->id,
                                            ":tag" => $tag,
                                        ]);
                                }
                            } catch (Exception $e) {
                                error_log($e->getMessage() . "@ " . __FILE__ . "(" . __LINE__ . ") " . $link);
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log($e->getMessage() . "@ " . __FILE__ . "(" . __LINE__ . ") " . $channel["feedurl"]);
                }
            }
        }
    }
}
