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

                    foreach ((new Feed)($feed)["item"] as $item) {

                        $link = (new Url($item["link"]))->normalized();

                        if (DB::execute("SELECT * FROM links WHERE urlhash=MD5(:url)", [":url" => $link])->rowCount() === 0) {

                            error_log("reading " . $link);

                            // --------------------------------------------------------------------------
                            // we do not use the content of the feeds because many feeds have no images
                            // instead we harvest the metadata from the articles themselves. Also
                            // bypasses Feedburner links
                            // --------------------------------------------------------------------------

                            $metadata = (new HtmlMetadata)($link);
                            if (Db::execute("INSERT IGNORE INTO links(url,channelid,title,description,image)
                            VALUES (:url, :channel, :title, :description, :image)",
                                    [
                                        ":url" => $metadata["link_url"],
                                        ":channel" => self::SYSTEMCHANNEL,
                                        ":title" => $metadata["link_title"],
                                        ":description" => $metadata["link_description"],
                                        ":image" => $metadata["link_image"],
                                    ])->rowCount() == 0
                            ) {
                                continue;
                            }

                            /** @noinspection PhpUndefinedMethodInspection */
                            $linkid = Db::lastInsertId();
                            foreach (explode(",", $metadata['link_keywords'] ?? "") as $tag) {
                                $tag = trim($tag);
                                if (!empty($tag)) {
                                    // these metadata tags are not assigned to a channel (so they can be filtered)
                                    Db::execute("INSERT IGNORE INTO tags(linkid, channelid, tag) VALUES (:linkid, :channelid, :tag)",
                                        [
                                            ":linkid" => $linkid,
                                            ":channelid" => self::SYSTEMCHANNEL,
                                            ":tag" => $tag,
                                        ]);
                                }
                            }
                        } else {
                            error_log("skipping " . $link);
                        }
                    }
                } catch (Exception $e) {
                    error_log($e->getMessage());
                }
            }
        }
    }
}
