<?php

namespace Zaplog\Library;

use Exception;
use SimpleXMLElement;
use SlimRestApi\Infra\Ini;

class Feed
{
    public static $cacheExpire = '1 day';
    public static $cacheDir;
    protected $xml;

    public function __invoke($url, $user = null, $pass = null): Feed
    {
        $xml = self::loadXml($url, $user, $pass);
        if ($xml->channel) {
            return self::fromRss($xml);
        } else {
            return self::fromAtom($xml);
        }
    }

    public static function loadRss($url, $user = null, $pass = null): Feed
    {
        return self::fromRss(self::loadXml($url, $user, $pass));
    }

    public static function loadAtom($url, $user = null, $pass = null): Feed
    {
        return self::fromAtom(self::loadXml($url, $user, $pass));
    }

    private static function fromRss(SimpleXMLElement $xml): Feed
    {
        if (!$xml->channel) {
            throw new Exception('Invalid feed.');
        }

        self::adjustNamespaces($xml);

        foreach ($xml->channel->item as $item) {
            // converts namespaces to dotted tags
            self::adjustNamespaces($item);

            // generate 'timestamp' tag
            if (isset($item->{'dc:date'})) {
                $item->timestamp = strtotime($item->{'dc:date'});
            } elseif (isset($item->pubDate)) {
                $item->timestamp = strtotime($item->pubDate);
            }
        }
        $feed = new self;
        $feed->xml = $xml->channel;
        return $feed;
    }

    private static function fromAtom(SimpleXMLElement $xml): Feed
    {
        if (!in_array('http://www.w3.org/2005/Atom', $xml->getDocNamespaces(), true)
            && !in_array('http://purl.org/atom/ns#', $xml->getDocNamespaces(), true)
        ) {
            throw new Exception('Invalid feed.');
        }

        // generate 'timestamp' tag
        foreach ($xml->entry as $entry) {
            $entry->timestamp = strtotime($entry->updated);
        }
        $feed = new self;
        $feed->xml = $xml;
        return $feed;
    }

    public function __get($name)
    {
        return $this->xml->{$name};
    }

    public function toArray(SimpleXMLElement $xml = null)
    {
        if ($xml === null) {
            $xml = $this->xml;
        }

        if (!$xml->children()) {
            return (string)$xml;
        }

        $arr = [];
        foreach ($xml->children() as $tag => $child) {
            if (count($xml->$tag) === 1) {
                $arr[$tag] = $this->toArray($child);
            } else {
                $arr[$tag][] = $this->toArray($child);
            }
        }

        return $arr;
    }

    private static function loadXml($url, $user, $pass): SimpleXMLElement
    {
        $e = self::$cacheExpire;
        $cacheFile = self::$cacheDir . '/feed.' . md5(serialize(func_get_args())) . '.xml';

        if (self::$cacheDir
            && (time() - @filemtime($cacheFile) <= (is_string($e) ? strtotime($e) - time() : $e))
            && $data = @file_get_contents($cacheFile)
        ) {
            // ok
        } elseif ($data = trim(self::httpRequest($url, $user, $pass))) {
            if (self::$cacheDir) {
                file_put_contents($cacheFile, $data);
            }
        } elseif (self::$cacheDir && $data = @file_get_contents($cacheFile)) {
            // ok
        } else {
            throw new Exception('Cannot load feed.');
        }

        return new SimpleXMLElement($data, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NOCDATA);
    }

    private static function httpRequest($url, $user, $pass): string
    {
        if (extension_loaded('curl')) {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            if ($user !== null || $pass !== null) {
                curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass");
            }
            curl_setopt($curl, CURLOPT_USERAGENT, Ini::get('user_agent')); // some feeds require a user agent
            curl_setopt($curl, CURLOPT_HEADER, false);
            curl_setopt($curl, CURLOPT_TIMEOUT, 20);
            curl_setopt($curl, CURLOPT_ENCODING, '');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // no echo, just return result
            if (!ini_get('open_basedir')) {
                curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); // sometime is useful :)
            }
            $result = curl_exec($curl);
            return curl_errno($curl) === 0 && curl_getinfo($curl, CURLINFO_HTTP_CODE) === 200
                ? $result
                : false;

        } else {
            $context = null;
            if ($user !== null && $pass !== null) {
                $options = [
                    'http' => [
                        'method' => 'GET',
                        'header' => 'Authorization: Basic ' . base64_encode($user . ':' . $pass) . "\r\n",
                        'user_agent' => Ini::get('user_agent'),
                    ],
                ];
                $context = stream_context_create($options);
            }

            return file_get_contents($url, false, $context);
        }
    }

    private static function adjustNamespaces($el)
    {
        foreach ($el->getNamespaces(true) as $prefix => $ns) {
            $children = $el->children($ns);
            foreach ($children as $tag => $content) {
                $el->{$prefix . ':' . $tag} = $content;
            }
        }
    }
}

