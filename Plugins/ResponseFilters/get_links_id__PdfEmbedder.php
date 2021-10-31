<?php

declare(strict_types=1);

namespace Zaplog\Plugins\ResponseFilters {

    use stdClass;
    use Zaplog\Plugins\AbstractResponseFilter;

    class get_links_id__PdfEmbedder extends AbstractResponseFilter
    {
        public function __invoke(string $uri, stdClass $args, &$data)
        {
            if (strcasecmp($data["link"]->mimetype ?? "", "application/pdf")===0) {
                $data["link"]->xtext .= "<iframe width='100%'  class='pdf' src='" . $data["link"]->url . "'></iframe>";
            }
        }
    }
}