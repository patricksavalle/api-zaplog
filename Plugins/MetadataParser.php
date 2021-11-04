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

    class MetadataParser
    {
        static public function getMimetype(string $url): string
        {
            $curl = curl_init($url);
            try {
                // HEAD request to check mimetype
                curl_setopt($curl, CURLOPT_NOBODY, true);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_exec($curl);
                if (($errno = curl_errno($curl)) !== 0) {
                    throw new Exception(curl_strerror($errno), 400);
                }
                preg_match("/([^;]+)/", curl_getinfo($curl, CURLINFO_CONTENT_TYPE), $matches);
                return $matches[1];
            } finally {
                curl_close($curl);
            }
        }

        static public function getMetadata(string $url): array
        {
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
        }
    }
}