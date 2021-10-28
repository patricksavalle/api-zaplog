<?php

declare(strict_types=1);

namespace Zaplog\Plugins\ResponseFilters {

    use stdClass;
    use Zaplog\Plugins\AbstractResponseFilter;

    class get_links_id__MediaEmbedder extends AbstractResponseFilter
    {
        public function __invoke(string $uri, stdClass $args, &$data)
        {
            error_log("Filter: " . __METHOD__);

            if ($Youtube = $this->YoutubeToken($data["link"]->url) !== null) {
                $data["link"]->xtext .= "<iframe width='420' height='315' src='http://www.youtube.com/embed/$Youtube?autoplay=1'></iframe>";
            }
            return $data;
        }

        protected function BitchuteToken(string $url): ?string
        {
            return null;
        }

        protected function YouTubeToken(string $url): ?string
        {
            // See: https://gist.github.com/rodrigoborgesdeoliveira/987683cfbfcc8d800192da1e73adc486

            if (strncmp($url, 'user/', 5) === 0) { // 1.
                return null;
            }
            if (preg_match('/^[a-zA-Z0-9\-\_]{11}$/', $url)) { // 2.
                return $url;
            }
            if (preg_match('/(?:watch\?v=|v\/|embed\/|ytscreeningroom\?v=|\?v=|\?vi=|e\/|watch\?.*vi?=|\?feature=[a-z_]*&v=|vi\/)([a-zA-Z0-9\-\_]{11})/', $url, $regularMatch)) { // 3.
                return $regularMatch[1];
            }
            if (preg_match('/([a-zA-Z0-9\-\_]{11})(?:\?[a-z]|\&[a-z])/', $url, $organicParametersMatch)) { // 4.
                return $organicParametersMatch[1];
            }
            if (preg_match('/u\/1\/([a-zA-Z0-9\-\_]{11})(?:\?rel=0)?$/', $url)) { // 5.
                return null; // 5. User channel without token.
            }

            if (preg_match('/(?:watch%3Fv%3D|watch\?v%3D)([a-zA-Z0-9\-\_]{11})[%&]/', $url, $urlEncoded)) { // 6.
                return $urlEncoded[1];
            }
            // 7. Rules for special cases
            if (preg_match('/watchv=([a-zA-Z0-9\-\_]{11})&list=/', $url, $special1)) {
                return $special1[1];
            }
            return null;
        }
    }
}