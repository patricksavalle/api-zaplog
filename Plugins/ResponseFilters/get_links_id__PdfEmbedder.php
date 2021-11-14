<?php

declare(strict_types=1);

namespace Zaplog\Plugins\ResponseFilters {

    use stdClass;
    use Zaplog\Plugins\AbstractResponseFilter;

    class get_links_id__PdfEmbedder extends AbstractResponseFilter
    {
        /** @noinspection PhpParameterByRefIsNotUsedAsReferenceInspection */
        public function __invoke(string $requestUri, stdClass $requestArgs, &$responseData)
        {
            if (strcasecmp($responseData["link"]->mimetype ?? "", "application/pdf")===0) {
                $embed = "<iframe width='100%'  class='pdf' src='" . $responseData["link"]->url . "'></iframe>";
                $responseData["link"]->xtext .= "<div class='iframe-wrapper'>$embed</div>";
            }
        }
    }
}