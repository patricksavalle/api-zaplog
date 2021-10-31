<?php

declare(strict_types=1);

namespace Zaplog\Plugins\MetadataParsers {

    // ----------------------------------------------------------
    // This filter is called on every HTML element that is
    // about to be outputted by the Parsedown conversion
    //
    // Element structure
    // ----------------------------------------------------------

    use ContentSyndication\HtmlMetadata;
    use Zaplog\Plugins\AbstractMetadataParser;

    class text_html extends AbstractMetadataParser
    {
        public function __invoke(string $url): array
        {
            return (new HtmlMetadata)($url);
        }
    }
}