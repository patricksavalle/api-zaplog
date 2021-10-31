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
                $parser = new Parser();
                $pdf = $parser->parseFile($url);
                $details = $pdf->getDetails();
            } catch (Exception $e) {
                throw new Exception($e->getMessage(), 400);
            }
            $metadata['url'] = $url;
            $metadata['title'] = $details['Title'] ?? null;
            return $metadata;
        }
    }
}