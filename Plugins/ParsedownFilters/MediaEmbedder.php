<?php

declare(strict_types=1);

namespace Zaplog\Plugins\ParsedownFilters {

    use ContentSyndication\HtmlMetadata;
    use ContentSyndication\Mimetype;
    use Exception;
    use Zaplog\Plugins\AbstractParsedownFilter;

    // To avoid copyright claims we must:
    // - only use publically availabe images
    // - only deep link to images
    // https://law.stackexchange.com/questions/38575/who-is-infringing-copyright-when-hotlinking-is-involved
    // We satisfy these conditions if we only deeplink to public URL's og:image

    class MediaEmbedder extends AbstractParsedownFilter
    {
        function __invoke(array $element): array
        {
            $getEmbedLink = function (string $url): ?array {
                // https://www.youtube.com/watch?v=UHkjxowYUdg
                if (preg_match("/.*youtube\.com\/watch.*v=([a-zA-Z0-9_-]+)/", $url, $matches) === 1) {
                    return ["https://www.youtube.com/embed/$matches[1]", "youtube video"];
                }
                // https://open.spotify.com/show/4rOoJ6Egrf8K2IrywzwOMk
                if (preg_match("/.*open\.spotify\.com\/episode\/([a-zA-Z0-9_-]+)/", $url, $matches) === 1) {
                    return ["https://open.spotify.com/embed/episode/$matches[1]", "spotify audio"];
                }
                // https://www.bitchute.com/video/ftihsfWPhzAp/
                if (preg_match("/.*bitchute\.com\/video\/([a-zA-Z0-9_-]+)\//", $url, $matches) === 1) {
                    return ["https://www.bitchute.com/embed/$matches[1]/", "bitchute video"];
                }
                // https://odysee.com/What-is-graphene-oxide:b78c43bd498f180b76ee8bbaae9c560ee9b34c98
                if (preg_match("/.*odysee.com\/([a-zA-Z0-9_-]+):([a-zA-Z0-9]+)/", $url, $matches) === 1) {
                    return ["https://odysee.com/$/embed/$matches[1]/$matches[2]", "odysee video"];
                }
                // https://vimeo.com/574675111
                if (preg_match("/.*vimeo\.com\/([0-9]+)/", $url, $matches) === 1) {
                    return ["https://player.vimeo.com/video/$matches[1]/", "vimeo video"];
                }
                // https://www.docdroid.net/M4dJCZc/schwab2020-pdf
                if (preg_match("/.*docdroid.net\/([a-zA-Z0-9_-]+)\/(.+)/", $url, $matches) === 1) {
                    return [$url, "docdroid pdf"];
                }
                return null;
            };

            // the Parsedown parser recognized and translated Markdown image syntax, we intercept it
            if (strcmp($element['name'], "img") === 0 and isset($element['attributes']['src'])) {

                try {

                    $metadata = (new HtmlMetadata)($element['attributes']['src']);

                    // EMBEDS: for specific domains we will use Markdown image-syntax to embed media
                    [$embedurl, $class] = $getEmbedLink($metadata["url"]);
                    if (!empty($embedurl)) {

                        return [
                            "name" => "iframe",
                            "title" => html_entity_decode($metadata['title'] ?? ""),
                            "text" => html_entity_decode($metadata['title'] ?? ""), // forces Parsedown parser to insert a closing tag
                            "attributes" => [
                                "width" => "100%",
                                "src" => $embedurl,
                                "class" => "$class",
                            ],
                        ];
                    }

                    // IMAGES: other domains get the og:image (no copyright) through metadata-inspection
                    if (!empty($metadata['image'])) {

                        return [
                            "name" => "img",
                            "attributes" => [
                                "class" => "image",
                                "width" => "100%",
                                "title" => html_entity_decode($metadata['title'] ?? ""),
//                                "width" => "100%",
                                "src" => $metadata['image'],
                            ],
                        ];
                    }

                } catch (Exception $e) {
                    // ignore
                }

                try {

                    $mimetype = (new Mimetype)($element['attributes']['src']);

                    // PDF
                    if ($mimetype === "application/pdf") {

                        return [
                            "name" => "iframe",
                            "text" => "", // forces Parsedown parser to insert a closing tag
                            "attributes" => [
                                "width" => "100%",
                                "src" => $element['attributes']['src'],
                                "class" => "pdf",
                            ],
                        ];
                    }

                } catch (Exception $e) {
                    // ignore
                }

                // block normal <img> elements to avoid copyright claims, translate into links
                return [
                    "name" => "a",
                    "text" => $element['attributes']['alt'] ?? $element['attributes']['title'],
                    "attributes" => [
                        "class" => "blocked-image-link",
                        "href" => $element['attributes']['src'],
                    ],
                ];
            }
            return $element;
        }
    }
}
