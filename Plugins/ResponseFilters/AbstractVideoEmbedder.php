<?php

declare(strict_types=1);

namespace Zaplog\Plugins\ResponseFilters {

    use Zaplog\Plugins\AbstractResponseFilter;

    abstract class AbstractVideoEmbedder extends AbstractResponseFilter
    {
        protected function getEmbedCode(string $url): ?string
        {
            foreach (["Youtube", "Spotify", "Bitchute", "Odysee", "Vimeo", "FreeWorldNews", "Banned", "DocDroid"] as $service) {
                if (($embed = $this->{$service}($url)) !== null) {
                    return "<div class='iframe-wrapper'>$embed</div>";
                }
            }
            return null;
        }
        protected function Spotify(string $normalized_url): ?string
        {
            // https://open.spotify.com/show/4rOoJ6Egrf8K2IrywzwOMk
            if (preg_match("/.*open\.spotify\.com\/episode\/([a-zA-Z0-9_-]+)/", $normalized_url, $matches) === 0) {
                return null;
            }
            return "<iframe class='video spotify' src='https://open.spotify.com/embed/episode/$matches[1]'></iframe>";
        }

        protected function Bitchute(string $normalized_url): ?string
        {
            // https://www.bitchute.com/video/ftihsfWPhzAp/
            if (preg_match("/.*bitchute\.com\/video\/([a-zA-Z0-9_-]+)\//", $normalized_url, $matches) === 0) {
                return null;
            }
            return "<iframe class='video bitchute' src='https://www.bitchute.com/embed/$matches[1]/'></iframe>";
        }

        protected function Odysee(string $normalized_url): ?string
        {
            // https://odysee.com/What-is-graphene-oxide:b78c43bd498f180b76ee8bbaae9c560ee9b34c98
            if (preg_match("/.*odysee.com\/([a-zA-Z0-9_-]+):([a-zA-Z0-9]+)/", $normalized_url, $matches) === 0) {
                return null;
            }
            return "<iframe class='video odysee' src='https://odysee.com/$/embed/$matches[1]/$matches[2]'></iframe>";
        }

        protected function Vimeo(string $normalized_url): ?string
        {
            // https://vimeo.com/574675111
            if (preg_match("/.*vimeo\.com\/([0-9]+)/", $normalized_url, $matches) === 0) {
                return null;
            }
            return "<iframe class='video vimeo' src='https://player.vimeo.com/video/$matches[1]/'></iframe>";
        }

        /** @noinspection JSUnresolvedLibraryURL */
        protected function FreeWorldNews(string $normalized_url): ?string
        {
            // https://freeworldnews.tv/watch?id=61897f79b2140737c32728d6
            if (preg_match("/.*freeworldnews\.tv\/watch\?id=\/([a-zA-Z0-9]+)/", $normalized_url, $matches) === 0) {
                return null;
            }
            return "<div class='video ifw-player' data-video-id='$matches[1]'></div><script src='https://infowarsmedia.com/js/player.js' async></script>";
        }

        /** @noinspection JSUnresolvedLibraryURL */
        protected function Banned(string $normalized_url): ?string
        {
            // https://banned.video/watch?id=6189aaf3b2140737c32d0273
            if (preg_match("/.*banned\.video\/watch\?id=\/([a-zA-Z0-9]+)/", $normalized_url, $matches) === 0) {
                return null;
            }
            return "<div class='video ifw-player' data-video-id='$matches[1]'></div><script src='https://infowarsmedia.com/js/player.js' async></script>";
        }

        protected function YouTube(string $normalized_url): ?string
        {
            // https://www.youtube.com/watch?v=UHkjxowYUdg
            if (preg_match("/.*youtube\.com\/watch.*v=([a-zA-Z0-9_-]+)/", $normalized_url, $matches) === 0) {
                return null;
            }
            return "<iframe class='video youtube' src='https://www.youtube.com/embed/$matches[1]'></iframe>";
        }
        protected function DocDroid(string $normalized_url): ?string
        {
            // https://www.docdroid.net/M4dJCZc/schwab2020-pdf
            if (preg_match("/.*docdroid.net\/([a-zA-Z0-9_-]+)\/(.+)/", $$normalized_url) === 0) {
                return null;
            }
            return "<iframe class='pdf docdroid' src='$normalized_url'></iframe>";
        }
    }
}