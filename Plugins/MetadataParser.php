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
        private $curl;

        public function __destruct()
        {
            curl_close($this->curl);
        }

        public function __invoke(string $url): array
        {
            // HEAD request to check mimetype
            $this->curl = curl_init($url);
            curl_setopt($this->curl, CURLOPT_NOBODY, true);
            curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
            curl_exec($this->curl);
            if (($errno = curl_errno($this->curl)) !== 0) {
                throw new Exception(curl_strerror($errno), 400);
            }

            // prepare file + class name
            preg_match("/([^;]+)/", curl_getinfo($this->curl, CURLINFO_CONTENT_TYPE), $matches);
            $mimetype = $matches[1];
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
            if (!($parser instanceof AbstractMetadataParser)) {
                error_log("$path is not an instance of AbstractMetadataParser -> ignored");
                return [];
            }
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