<?php

declare(strict_types=1);

namespace Zaplog\Library {

    use Exception;
    use SimpleXMLElement;
    use SlimRestApi\Infra\Ini;
    use Zaplog\Exception\CurlException;

    class Feed
    {
        protected $xml;

        public function __invoke(string $url, string $user = "", string $pass = ""): array
        {
            $xml = self::loadXml($url, $user, $pass);
            if ($xml->channel) {
                return self::fromRss($xml)->toArray();
            } else {
                return self::fromAtom($xml)->toArray();
            }
        }

        public static function loadRss(string $url, string $user = "", string $pass = ""): Feed
        {
            return self::fromRss(self::loadXml($url, $user, $pass));
        }

        public static function loadAtom(string $url, string $user = "", string $pass = ""): Feed
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
                    $item->timestamp = strtotime((string)$item->{'dc:date'});
                } elseif (isset($item->pubDate)) {
                    $item->timestamp = strtotime((string)$item->pubDate);
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
                $entry->timestamp = strtotime((string)$entry->updated);
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

        private static function loadXml(string $url, string $user, string $pass): SimpleXMLElement
        {
            $data = trim(self::httpRequest($url, $user, $pass));
            return new SimpleXMLElement($data, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NOCDATA);
        }

        private static function httpRequest(string $url, string $user, string $pass): string
        {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            if (!empty($user) or !empty($pass)) {
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
            if (curl_errno($curl) !== 0)
                throw new CurlException($url);
            return $result;
        }

        private static function adjustNamespaces(SimpleXMLElement $el)
        {
            foreach ($el->getNamespaces(true) as $prefix => $ns) {
                $children = $el->children($ns);
                foreach ($children as $tag => $content) {
                    $el->{$prefix . ':' . $tag} = $content;
                }
            }
        }
    }
}