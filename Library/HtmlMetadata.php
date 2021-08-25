<?php

declare(strict_types=1);

namespace Zaplog\Library {

    require_once BASE_PATH . '/Library/Url.php';

    use DOMDocument;
    use DOMXPath;
    use Exception;
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
            if (curl_errno($curl) !== 0)
                throw new CurlException($url);
            return $result;
        }

        function __invoke(string $url)

        {
            assert(filter_var($url, FILTER_VALIDATE_URL) !== false);
            $metadata = [];
            // read the file with our own user-agent, restore after
            // many webservers block the default PHP user-agent
            $html = self::httpRequest($url);
            libxml_use_internal_errors(true);
            $doc = new DomDocument();
            $doc->loadHTML($html);
            $xpath = new DOMXPath($doc);

            // first try og:
            $metadata['link_url'] = @$xpath->query('/*/head/meta[@property="og:url"]/@content')->item(0)->nodeValue;
            $metadata['link_title'] = @$xpath->query('/*/head/meta[@property="og:title"]/@content')->item(0)->nodeValue;
            $metadata['link_description'] = @$xpath->query('/*/head/meta[@property="og:description"]/@content')->item(0)->nodeValue;
            // TODO can be multiple images
            $metadata['link_image'] = @$xpath->query('//meta[@property="og:image"]/@content')->item(0)->nodeValue;
            $metadata['link_site_name'] = @$xpath->query('/*/head/meta[@property="og:site_name"]/@content')->item(0)->nodeValue;

            // than try twitter:
            if (empty($metadata['link_url'])) {
                $metadata['link_url'] = @$xpath->query('/*/head/meta[@name="twitter:url"]/@content')->item(0)->nodeValue;
            }
            if (empty($metadata['link_title'])) {
                $metadata['link_title'] = @$xpath->query('/*/head/meta[@name="twitter:title"]/@content')->item(0)->nodeValue;
            }
            if (empty($metadata['link_description'])) {
                $metadata['link_description'] = @$xpath->query('/*/head/meta[@name="twitter:description"]/@content')->item(0)->nodeValue;
            }
            // TODO can be multiple images
            if (empty($metadata['link_image'])) {
                $metadata['link_image'] = @$xpath->query('/*/head/meta[@name="twitter:image"]/@content')->item(0)->nodeValue;
            }
            if (empty($metadata['link_site_name'])) {
                $metadata['link_site_name'] = @$xpath->query('/*/head/meta[@name="twitter:site"]/@content')->item(0)->nodeValue;
            }

            // than be opportunistic, try other tags
            if (empty($metadata['link_description'])) {
                $metadata['link_description'] = @$xpath->query('/*/head/meta[@name="description"]/@content')->item(0)->nodeValue;
            }
            if (empty($metadata['link_description'])) {
                $metadata['link_description'] = @$xpath->query('/*/head/meta[@name="keywords"]/@content')->item(0)->nodeValue;
            }
            if (empty($metadata['link_title'])) {
                $metadata['link_title'] = @$xpath->query('/*/head/title')->item(0)->nodeValue;
            }
            if (empty($metadata['link_url'])) {
                $metadata['link_url'] = @$xpath->query('/*/head/link[@rel="canonical"]/@href')->item(0)->nodeValue;
            }
            if (empty($metadata['link_url'])) {
                $metadata['link_url'] = $url;
            }
            if (empty($metadata['link_image'])) {
                $metadata['link_image'] = @$xpath->query('/*/head/link[@rel="apple-touch-icon"]/@href')->item(0)->nodeValue;
            }

            // @Todo use Google JSON-LD data

            // get RSS and Atom feeds
            // <link rel="alternate" type="application/rss+xml" title="RSS" href="http://feeds.feedburner.com/TheRssBlog">
            // TODO can be multiple feeds, for now return first
            $metadata['link_rss'] = @$xpath->query('/*/head/link[@rel="alternate"][@type="application/rss+xml"]/@href')->item(0)->nodeValue;
            $metadata['link_atom'] = @$xpath->query('/*/head/link[@rel="alternate"][@type="application/atom+xml"]/@href')->item(0)->nodeValue;

            $metadata['link_domain'] = parse_url($metadata['link_url'], PHP_URL_HOST);
            if (empty($metadata['link_site_name'])) {
                $metadata['link_site_name'] = $metadata['link_domain'];
            }

            // keywords, author, copyright
            $metadata['link_keywords'] = @$xpath->query('/*/head/meta[@name="keywords"]/@content')->item(0)->nodeValue;
            $metadata['link_author'] = @$xpath->query('/*/head/meta[@name="author"]/@content')->item(0)->nodeValue;
            $metadata['link_copyright'] = @$xpath->query('/*/head/meta[@name="copyright"]/@content')->item(0)->nodeValue;

            // some URL magic
            $metadata['link_url'] = (new Url($metadata['link_url']))->normalized();
            if (isset($metadata['link_image'])) $metadata['link_image'] = (new Url($metadata['link_image']))->absolutized($metadata['link_url']);
            if (isset($metadata['link_rss'])) $metadata['link_rss'] = (new Url($metadata['link_rss']))->absolutized($metadata['link_url']);
            if (isset($metadata['link_atom'])) $metadata['link_atom'] = (new Url($metadata['link_atom']))->absolutized($metadata['link_url']);

            // TODO remove HTML entities and other garbage from descriptions etc.
            $metadata['link_description'] = preg_replace('/[[:^print:]]/', '', $metadata['link_description']);

            return $metadata;
        }
    }

}