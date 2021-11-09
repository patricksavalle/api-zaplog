<?php

declare(strict_types=1);

namespace Zaplog\Plugins\ResponseFilters {

    use stdClass;

    // ------------------------------------------------------------------------------
    // Called just before a post is returned to the frontend
    // If the url of a post is embeddable, append as iframe to xtext-field
    //
    // The API has already normalized the url, no need to check all possible patterns
    // ------------------------------------------------------------------------------

    class get_links_id__VideoEmbedder extends AbstractVideoEmbedder
    {
        /** @noinspection PhpParameterByRefIsNotUsedAsReferenceInspection */
        public function __invoke(string $requestUri, stdClass $requestArgs, &$responseData)
        {
            // if the link is embeddable, emmbed it
            $normalized_url = $responseData["link"]->url ?? null;
            if ($normalized_url !== null) {
                $embed = $this->getEmbedCode($normalized_url);
                if (!empty($embed)) {
                    $responseData["link"]->xtext .= $embed;
                }
            }
        }
    }
}