<?php

declare(strict_types=1);

namespace Zaplog\Library {

    require_once BASE_PATH . '/Library/Url.php';

    use DOMDocument;
    use DOMXPath;
    use SlimRestApi\Infra\Ini;
    use Zaplog\Exception\CurlException;

    class HtmlMetadata
    {
        private static function httpRequest(string $url): string
        {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_USERAGENT, Ini::get('user_agent')); // some feeds require a user agent
            curl_setopt($curl, CURLOPT_HEADER, false);
            curl_setopt($curl, CURLOPT_TIMEOUT, 20);
            curl_setopt($curl, CURLOPT_ENCODING, '');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // no echo, just return result
            if (!ini_get('open_basedir')) {
                curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); // sometime is useful :)
            }
            $result = curl_exec($curl);
            if ($result === false or curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200)
                throw new CurlException($url);
            return $result;
        }

        function __invoke(string $url): array
        {
            assert(filter_var($url, FILTER_VALIDATE_URL) !== false);
            $metadata = [];
            libxml_use_internal_errors(true);
            $doc = new DomDocument;
            $doc->loadHTML(self::httpRequest($url));
            $xpath = new DOMXPath($doc);

            $xfunc = function (string $x) use ($xpath) {
                return @$xpath->query($x)->item(0)->nodeValue;
            };

            $metadata['url']
                = $xfunc('/*/head/meta[@property="og:url"]/@content')
                ?? $xfunc('/*/head/meta[@name="twitter:url"]/@content')
                ?? $xfunc('/*/head/link[@rel="canonical"]/@href')
                ?? $url;

            $metadata['title']
                = $xfunc('/*/head/meta[@property="og:title"]/@content')
                ?? $xfunc('/*/head/meta[@name="twitter:title"]/@content')
                ?? $xfunc('/*/head/title');

            $metadata['description']
                = $xfunc('/*/head/meta[@property="og:description"]/@content')
                ?? $xfunc('/*/head/meta[@name="twitter:description"]/@content')
                ?? $xfunc('/*/head/meta[@name="description"]/@content');

            // TODO can be multiple images
            $metadata['image']
                = $xfunc('//meta[@property="og:image"]/@content')
                ?? $xfunc('/*/head/meta[@name="twitter:image"]/@content')
                ?? $xfunc('/*/head/link[@rel="apple-touch-icon"]/@href');

            $metadata['site_name']
                = $xfunc('/*/head/meta[@property="og:site_name"]/@content')
                ?? $xfunc('/*/head/meta[@name="twitter:site"]/@content');

            // TODO use JSON-LD data
            // https://jsonld.com/news-article/
            // https://jsonld.com/blog-post/

            // get RSS and Atom feeds
            // TODO can be multiple feeds, for now return first
            $metadata['rss'] = $xfunc('/*/head/link[@rel="alternate"][@type="application/rss+xml"]/@href');
            $metadata['atom'] = $xfunc('/*/head/link[@rel="alternate"][@type="application/atom+xml"]/@href');

            // keywords, author, copyright
            $metadata['keywords']
                = $xfunc('/*/head/meta[@name="keywords"]/@content')
                ?? $xfunc('/*/head/meta[@name="news_keywords"]/@content');
            $metadata['author'] = $xfunc('/*/head/meta[@name="author"]/@content');
            $metadata['copyright'] = $xfunc('/*/head/meta[@name="copyright"]/@content');

            // some URL magic
            $metadata['url'] = (new Url($metadata['url']))->normalized();
            if (isset($metadata['image'])) $metadata['image'] = (new Url($metadata['image']))->absolutized($metadata['url']);
            if (isset($metadata['rss'])) $metadata['rss'] = (new Url($metadata['rss']))->absolutized($metadata['url']);
            if (isset($metadata['atom'])) $metadata['atom'] = (new Url($metadata['atom']))->absolutized($metadata['url']);

            // TODO remove HTML entities and other garbage from descriptions etc.
            $metadata['description'] = preg_replace('/[[:^print:]]/', '', $metadata['description']);

            // normalize keywords and return as array
            $keywords = [];
            foreach (explode(",", $metadata['keywords'] ?? "") as $keyword) {
                $keyword = trim($keyword);
                if (!empty($keyword)) {
                    $keywords[] = (new NormalizedText($keyword))->convertNonAscii()->convertNonPath()();
                }
            }
            $metadata['keywords'] = array_unique($keywords);

            return $metadata;
        }
    }

}