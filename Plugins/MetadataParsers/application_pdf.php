<?php

declare(strict_types=1);

namespace Zaplog\Plugins\MetadataParsers {

    // ----------------------------------------------------------
    // This filter is called on every HTML element that is
    // about to be outputted by the Parsedown conversion
    //
    // Element structure
    // ----------------------------------------------------------

    use Exception;
    use Zaplog\Plugins\AbstractMetadataParser;

    class application_pdf extends AbstractMetadataParser
    {
        public function __invoke(string $url): array
        {
            // return (new PdfMetadata)($url);
            throw new Exception("Unsupported file type", 400);
        }
    }
}