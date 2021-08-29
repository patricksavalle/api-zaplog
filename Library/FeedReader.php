<?php

declare(strict_types=1);

namespace Zaplog\Library {

    require_once 'Feed.php';
    require_once 'NormalizedText.php';

    use Exception;
    use SlimRestApi\Infra\Db;

    class FeedReader
    {
        private function insertLink(array $feeddata, array $metadata, string $channelid): bool
        {
            try {
                Db::execute("INSERT INTO links(url,channelid,title,description,image)
                        VALUES (:url, :channel, :title, :description, :image)",
                    [
                        ":url" => $metadata["url"],
                        ":channel" => $channelid,
                        ":title" => $metadata["title"],
                        ":description" => $metadata["description"],
                        ":image" => $metadata["image"] ?? $feeddata["ímage"]["url"] ?? null,
                    ]);
                return true;
            } catch (Exception $e) {
                error_log($e->getMessage() . " @ " . __FILE__ . "(" . __LINE__ . ") " . $metadata["url"]);
                return false;
            }
        }

        /** @noinspection PhpReturnValueOfMethodIsNeverUsedInspection */
        private function insertTag(string $linkid, string $channelid, string $tag): bool
        {
            try {
                $tag = (new NormalizedText($tag))->convertNonAscii()->convertNonPath()();
                // only accept reasonable tags
                assert(preg_match("/[\w-]{3,50}/", $tag) !== false);
                assert(substr_count($tag, "-") < 5);
                Db::execute("INSERT INTO tags(linkid, channelid, tag) 
                        VALUES (:linkid, :channelid, :tag)",
                    [
                        ":linkid" => $linkid,
                        ":channelid" => $channelid,
                        ":tag" => $tag,
                    ]);
                return true;
            } catch (Exception $e) {
                error_log($e->getMessage() . " @ " . __FILE__ . "(" . __LINE__ . ") " . $tag);
                return false;
            }
        }

        public function __invoke()
        {
            foreach (Db::execute("SELECT * FROM channels WHERE NOT feedurl IS NULL")->fetchAll() as $channel) {

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

                            $metadata = (new HtmlMetadata)($link);
                            if ($this->insertLink($content, $metadata, (string)$channel->id)) {
                                /** @noinspection PhpUndefinedMethodInspection */
                                $linkid = Db::lastInsertId();
                                foreach ($metadata['keywords'] as $tag) {
                                    $this->insertTag($linkid, (string)$channel->id, $tag);
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log($e->getMessage() . " @ " . __FILE__ . "(" . __LINE__ . ") " . $channel->feedurl);
                }
            }
        }
    }
}
