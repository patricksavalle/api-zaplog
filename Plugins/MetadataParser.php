<?php

declare(strict_types=1);

namespace Zaplog\Plugins {

    // ------------------------------------------------------------------
    // factory pattern: instantiates and executes the matching parser
    //
    // This factory looks for a file that matches the required mimetype.
    // All non alphanumeric characters in the mimetype are translated to
    // underscores to determine the filename. Examples:
    //
    // application/pdf  -> application_pdf.php
    // application/xml+rss -> application_xml_rss.php
    // etc.
    // -----------------------------------------------------------------

    use Exception;
    use Zaplog\Exception\UserException;

    class MetadataParser
    {
        static public function getMimetype(string $url): string
        {
            $curl = curl_init($url);
            try {
                // HEAD request to check mimetype
                curl_setopt($curl, CURLOPT_NOBODY, true);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_USERAGENT, "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/92.0.4515.159 Safari/537.36 OPR/78.0.4093.112"); // some feeds require a user agent
                curl_exec($curl);
                if (($errno = curl_errno($curl)) !== 0) {
                    throw new Exception(curl_strerror($errno), 400);
                }
                $contenttype = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
                if ($contenttype === false) {
                    throw new Exception("CURLINFO_CONTENT_TYPE error");
                }
                preg_match("/([^;]+)/", $contenttype, $matches);
                return $matches[1];
            } finally {
                curl_close($curl);
            }
        }

        static public function getMetadata(string $url): array
        {
            try {
                $mimetype = static::getMimetype($url);

                // prepare file + class name
                $name = strtolower(preg_replace("/[^\w]/", "_", $mimetype));

                // load matching parser (if any)
                $path = "Plugins/MetadataParsers/$name.php";
                if (!file_exists($path) or !is_readable($path)) {
                    throw new Exception("Unsupported file type: " . $mimetype);
                }
                require $path;

                // instantiate and execute parser
                $class = "Zaplog\\Plugins\\MetadataParsers\\$name";
                $parser = (new $class);
                assert($parser instanceof AbstractMetadataParser);
                $metadata = $parser($url);

                // sanity checks
                assert(isset($metadata["url"]));
                assert(isset($metadata["title"]));

                // add the mimetype
                $metadata['mimetype'] = $mimetype;
                return $metadata;

            } catch (Exception $e) {
                error_log($e->getMessage() . " @ " . $url);
                throw new UserException("Invalid link or document format");
            }
        }
    }
}