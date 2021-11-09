<?php

declare(strict_types=1);

namespace Zaplog\Plugins\ResponseFilters {

    use stdClass;
    use Zaplog\Plugins\AbstractResponseFilter;

    // ------------------------------------------------------------------------------
    // Called just before a post is returned to the frontend
    // If the url of a post is embeddable, append as iframe to xtext-field
    //
    // The API has already normalized the url, no need to check all possible patterns
    // ------------------------------------------------------------------------------

    class get_links_id__VideoEmbedder extends AbstractResponseFilter
    {
        /** @noinspection PhpParameterByRefIsNotUsedAsReferenceInspection */
        public function __invoke(string $requestUri, stdClass $requestArgs, &$responseData)
        {
            $normalized_url = $responseData["link"]->url ?? null;
            if ($normalized_url !== null) {
                foreach (["Youtube", "Bitchute", "Odysee", "Vimeo", "FreeWorldNews", "Banned"] as $service) {
                    if (($embed = $this->{$service}($normalized_url)) !== null) {
                        $responseData["link"]->xtext .= $embed;
                        return;
                    }
                }
            }
        }

        protected function Bitchute(string $normalized_url): ?string
        {
            // https://www.bitchute.com/video/ftihsfWPhzAp/
            if (preg_match("/.*bitchute\.com\/video\/([a-zA-Z0-9_-]+)\//", $normalized_url, $matches) === 0) {
                return null;
            }
            return "<iframe width='100%'  class='video bitchute' src='https://www.bitchute.com/embed/$matches[1]/'></iframe>";
        }

        protected function Odysee(string $normalized_url): ?string
        {
            // https://odysee.com/What-is-graphene-oxide:b78c43bd498f180b76ee8bbaae9c560ee9b34c98
            if (preg_match("/.*odysee.com\/([a-zA-Z0-9_-]+):([a-zA-Z0-9]+)/", $normalized_url, $matches) === 0) {
                return null;
            }
            return "<iframe width='100%'  class='video odysee' src='https://odysee.com/$/embed/$matches[1]/$matches[2]'></iframe>";
        }

        protected function Vimeo(string $normalized_url): ?string
        {
            // https://vimeo.com/574675111
            if (preg_match("/.*vimeo\.com\/([0-9]+)/", $normalized_url, $matches) === 0) {
                return null;
            }
            return "<iframe width='100%'  class='video vimeo' src='https://player.vimeo.com/video/$matches[1]/'></iframe>";
        }

        protected function FreeWorldNews(string $normalized_url): ?string
        {
            https://freeworldnews.tv/watch?id=61897f79b2140737c32728d6
            if (preg_match("/.*freeworldnews\.tv\/watch\?id=\/([a-zA-Z0-9]+)/", $normalized_url, $matches) === 0) {
                return null;
            }
            return "<div class='ifw-player' data-video-id='$matches[1]'></div><script src='https://infowarsmedia.com/js/player.js' async></script>";
        }

        protected function Banned(string $normalized_url): ?string
        {
            // https://banned.video/watch?id=6189aaf3b2140737c32d0273
            if (preg_match("/.*banned\.video\/watch\?id=\/([a-zA-Z0-9]+)/", $normalized_url, $matches) === 0) {
                return null;
            }
            return "<div class='ifw-player' data-video-id='$matches[1]'></div><script src='https://infowarsmedia.com/js/player.js' async></script>";
        }

        protected function YouTube(string $normalized_url): ?string
        {
            // https://www.youtube.com/watch?v=UHkjxowYUdg
            if (preg_match("/.*youtube\.com\/watch.*v=([a-zA-Z0-9_-]+)/", $normalized_url, $matches) === 0) {
                return null;
            }
            return "<iframe width='100%' class='video youtube' src='https://www.youtube.com/embed/$matches[1]'></iframe>";
        }
    }
}