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
            if ($result === false)
                throw new CurlException($url);
            return $result;
        }

        function __invoke(string $url): array
        {
            assert(filter_var($url, FILTER_VALIDATE_URL) !== false);
            $metadata = [];
            libxml_use_internal_errors(true);
            $doc = new DomDocument();
            $doc->loadHTML(self::httpRequest($url));
            $xpath = new DOMXPath($doc);

            $xfunc = function ($x) use ($xpath)
            {
                return @$xpath->query($x)->item(0)->nodeValue;
            };

            $metadata['link_url']
                =  $xfunc('/*/head/meta[@property="og:url"]/@content')
                ?? $xfunc('/*/head/meta[@name="twitter:url"]/@content')
                ?? $xfunc('/*/head/link[@rel="canonical"]/@href')
                ?? $url;

            $metadata['link_title']
                =  $xfunc('/*/head/meta[@property="og:title"]/@content')
                ?? $xfunc('/*/head/meta[@name="twitter:title"]/@content')
                ?? $xfunc('/*/head/title');

            $metadata['link_description']
                =  $xfunc('/*/head/meta[@property="og:description"]/@content')
                ?? $xfunc('/*/head/meta[@name="twitter:description"]/@content')
                ?? $xfunc('/*/head/meta[@name="description"]/@content');

            // TODO can be multiple images
            $metadata['link_image']
                =  $xfunc('//meta[@property="og:image"]/@content')
                ?? $xfunc('/*/head/meta[@name="twitter:image"]/@content')
                ?? $xfunc('/*/head/link[@rel="apple-touch-icon"]/@href');

            $metadata['link_site_name']
                = $xfunc('/*/head/meta[@property="og:site_name"]/@content')
                 ?? $xfunc('/*/head/meta[@name="twitter:site"]/@content');

            // @Todo use Google JSON-LD data

            // get RSS and Atom feeds
            // TODO can be multiple feeds, for now return first
            $metadata['link_rss'] = $xfunc('/*/head/link[@rel="alternate"][@type="application/rss+xml"]/@href');
            $metadata['link_atom'] = $xfunc('/*/head/link[@rel="alternate"][@type="application/atom+xml"]/@href');

            $metadata['link_domain'] = parse_url($metadata['link_url'], PHP_URL_HOST);

            // keywords, author, copyright
            $metadata['link_keywords']
                =  $xfunc('/*/head/meta[@name="keywords"]/@content')
                ?? $xfunc('/*/head/meta[@name="news_keywords"]/@content');
            $metadata['link_author'] = $xfunc('/*/head/meta[@name="author"]/@content');
            $metadata['link_copyright'] = $xfunc('/*/head/meta[@name="copyright"]/@content');

            // some URL magic
            $metadata['link_url'] = (new Url($metadata['link_url']))->normalized();
            if (isset($metadata['link_image'])) $metadata['link_image'] = (new Url($metadata['link_image']))->absolutized($metadata['link_url']);
            if (isset($metadata['link_rss'])) $metadata['link_rss'] = (new Url($metadata['link_rss']))->absolutized($metadata['link_url']);
            if (isset($metadata['link_atom'])) $metadata['link_atom'] = (new Url($metadata['link_atom']))->absolutized($metadata['link_url']);

            // TODO remove HTML entities and other garbage from descriptions etc.
            $metadata['link_description'] = preg_replace('/[[:^print:]]/', '', $metadata['link_description']);

            // normalize keywords and return as array
            $keywords = [];
            foreach (explode(",", $metadata['link_keywords'] ?? "") as $keyword) {
                $keyword = trim($keyword);
                if (!empty($keyword)) {
                    $keywords[] = (new NormalizedText($keyword))->convertNonAscii()->convertNonPath()();
                }
            }
            $metadata['link_keywords'] = array_unique($keywords);

            return $metadata;
        }
    }

}