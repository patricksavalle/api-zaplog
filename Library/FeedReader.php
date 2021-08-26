<?php

declare(strict_types=1);

namespace Zaplog\Library {

    require_once 'Feed.php';

    use Exception;
    use SlimRestApi\Infra\Db;

    class FeedReader
    {
        const SYSTEMCHANNEL = 1;

        private $feeds = [
            "https://www.rt.com/rss/",
            "https://off-guardian.org/feed/",
            "https://feeds.feedburner.com/zerohedge/feed",
            "https://www.infowars.com/rss.xml",
            "https://www.xandernieuws.net/feed/",
            "https://www.cnet.com/rss/all/",
            "https://gizmodo.com/rss",
        ];

        public function __invoke()
        {
            foreach ($this->feeds as $feed) {

                try {

                    $content = (new Feed)($feed);
                    foreach ($content["item"] as $item) {

                        $link = (new Url($item["link"]))->normalized();
                        if (DB::execute("SELECT * FROM links WHERE urlhash=MD5(:url)", [":url" => $link])->rowCount() === 0) {

                            // --------------------------------------------------------------------------
                            // we do not use the content of the feeds because many feeds have no images
                            // instead we harvest the metadata from the articles themselves. Also
                            // bypasses Feedburner links (we need the original links)
                            // --------------------------------------------------------------------------

                            $metadata = (new HtmlMetadata)($link);
                            if (Db::execute("INSERT IGNORE INTO links(url,channelid,title,description,image)
                            VALUES (:url, :channel, :title, :description, :image)",
                                    [
                                        ":url" => $metadata["url"],
                                        ":channel" => self::SYSTEMCHANNEL,
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
                                // these metadata tags are not assigned to a channel (so they can be filtered)
                                Db::execute("INSERT INTO tags(linkid, channelid, tag) VALUES (:linkid, :channelid, :tag)",
                                    [
                                        ":linkid" => $linkid,
                                        ":channelid" => self::SYSTEMCHANNEL,
                                        ":tag" => $tag,
                                    ]);
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log($e->getMessage());
                }
            }
        }
    }
}
