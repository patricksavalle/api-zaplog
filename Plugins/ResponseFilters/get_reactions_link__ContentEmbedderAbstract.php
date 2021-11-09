<?php /** @noinspection PhpParameterByRefIsNotUsedAsReferenceInspection */

declare(strict_types=1);

namespace Zaplog\Plugins\ResponseFilters {

    use stdClass;

    // ------------------------------------------------------------------------------
    // Called just before a post is returned to the frontend
    // If the url of a post is embeddable, append as iframe to xtext-field
    //
    // The API has already normalized the url, no need to check all possible patterns
    // ------------------------------------------------------------------------------

    class get_reactions_link__ContentEmbedderAbstract extends AbstractVideoEmbedder
    {
        public function __invoke(string $requestUri, stdClass $requestArgs, &$responseData)
        {
            foreach ($responseData as $reaction) {
                // if the reaction is an url
                if (($url = filter_var(trim(strip_tags($reaction->xtext)) ?? null, FILTER_VALIDATE_URL)) !== false) {
                    $embed = $this->getEmbedCode($url);
                    if (!empty($embed)) {
                        $reaction->xtext = $embed;
                    }
                }
            }
        }
    }
}