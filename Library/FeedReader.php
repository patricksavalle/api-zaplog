<?php

declare(strict_types=1);

namespace Zaplog\Library {

    require_once 'Feed.php';

    use Exception;
    use SlimRestApi\Infra\Db;

    class FeedReader
    {
        const SYSTEMCHANNEL = 1;

        private $urls = [
            "https://www.rt.com/rss/",
            "https://off-guardian.org/feed/",
            "http://feeds.feedburner.com/zerohedge/feed",
            "https://www.infowars.com/rss.xml",
        ];

        public function __invoke()
        {
            foreach ($this->urls as $url) {

                try {
                    $feed = (new Feed)($url);
                    foreach ($feed["item"] as $item) {

                        $metadata = (new HtmlMetadata)(urldecode($item['link']));
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
                        $linkid = Db::lastInsertId();
                        if (isset($metadata['link_keywords']))
                            foreach (explode(",", $metadata['link_keywords']) as $tag) {
                                Db::execute("INSERT INTO tags(linkid, channelid, tag) VALUES (:linkid, :channel, :tag)",
                                    [
                                        ":linkid" => $linkid,
                                        ":channel" => self::SYSTEMCHANNEL,
                                        ":tag" => trim($tag),
                                    ]);
                            }
                    }
                }
                catch (Exception $e) {
                    error_log($e->getMessage());
                }
            }
        }
    }
}
