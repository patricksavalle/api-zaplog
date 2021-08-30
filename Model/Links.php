<?php

declare(strict_types=1);

namespace Zaplog\Model;

require_once BASE_PATH . '/Exception/ResourceNotFoundException.php';
require_once BASE_PATH . '/Library/HtmlMetadata.php';
require_once BASE_PATH . '/Library/NormalizedText.php';

use Exception;
use Zaplog\Library\HtmlMetadata;
use SlimRestApi\Infra\Db;
use Zaplog\Library\NormalizedText;

class Links
{
    static public function postLinkFromUrl(string $channelid, string $url): string
    {
        $metadata = (new HtmlMetadata)($url);

        if (Db::execute("INSERT INTO links(url,channelid,title,description,image)
                        VALUES (:url, :channelid, :title, :description, :image)",
                [
                    ":url" => $metadata["url"],
                    ":channelid" => $channelid,
                    ":title" => $metadata["title"],
                    ":description" => $metadata["description"],
                    ":image" => $metadata["image"],
                ])->rowCount() == 0
        ) {
            throw new Exception;
        }

        /** @noinspection PhpUndefinedMethodInspection */
        $linkid = Db::lastInsertId();

        foreach ($metadata['keywords'] as $tag) {
            try {
                // only accept reasonable tags
                $tag = (new NormalizedText($tag))->convertNonAscii()->convertNonPath()();
                assert(preg_match("/[\w-]{3,50}/", $tag) !== false);
                assert(substr_count($tag, "-") < 5);
                Db::execute("INSERT INTO tags(linkid, channelid, tag) VALUES (:linkid, :channelid, :tag)",
                    [
                        ":linkid" => $linkid,
                        ":channelid" => $channelid,
                        ":tag" => $tag,
                    ]);
            }
            catch (Exception $e) {
                error_log($e->getMessage() . " @ " . __FILE__ . "(" . __LINE__ . ") " . $tag);
            }
        }
        return $linkid;
    }
}