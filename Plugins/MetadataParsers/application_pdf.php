<?php

declare(strict_types=1);

namespace Zaplog\Plugins\MetadataParsers {

    use Exception;
    use Smalot\PdfParser\Parser;
    use Zaplog\Plugins\AbstractMetadataParser;

    class application_pdf extends AbstractMetadataParser
    {
        public function __invoke(string $url): array
        {
            try {
                $details = (new Parser)->parseFile($url)->getDetails();
                $metadata['url'] = $url;
                $metadata['title'] = $details['Title'][0] ?? $details['Title'] ?? null;
                $metadata['author'] = $details['Author'] ?? null;
                error_log(print_r($metadata,true));
                return $metadata;
            } catch (Exception $e) {
                throw new Exception($e->getMessage(), 400);
            }
        }
    }
}