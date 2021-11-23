<?php

declare(strict_types=1);

namespace Zaplog\Plugins\ParsedownFilters {

    use ContentSyndication\HtmlMetadata;
    use Exception;
    use Zaplog\Plugins\AbstractParsedownFilter;

    class ImageEmbedder extends AbstractParsedownFilter
    {
        function __invoke(array $element): array
        {
            $getImage = function (string $url): ?array {
                try {
                    // image-specific domains, read image from the public URL HTML-metadata
                    foreach ([
                                 "https://www.flickr.com",
                                 "https://ibb.co",
                                 "https://postimg.cc",
                                 "https://imagebam.com",
                                 "https://www.gettyimages.com",
                             ] as $domain) {
                        if (stripos($url, $domain) === 0) {
                            return ((new HtmlMetadata)($url));
                        }
                    }
                } catch (Exception $e) {
                    // nothing
                }
                return null;
            };

            try {

                // Translate <a> elements that are images into <img>
                //
                // To avoid copyright claims we must:
                // - not publish from non-public URL's
                // - only deep link
                //
                // We satisfy these conditions if we only use images from public URL's that
                // are offered to us by HTML og/twitter metadata.

                if (strcmp($element['name'], "a") === 0 and isset($element['attributes']['href'])) {

                    if (preg_match("@https:\/\/pbs\.twimg\.com\/media\/.*@", $element['attributes']['href']) === 1) {
                        // https://pbs.twimg.com/media/FEBc-7aUUAAQMxP?format=jpg&name=small
                        return [
                            "name" => "img",
                            "attributes" => [
                                "width" => "100%",
                                "src" => $element['attributes']['href'],
                            ],
                        ];

                    } else {

                        $image = $getImage($element['attributes']['href']);
                        if (!empty($image['image'])) {

                            return [
                                "name" => "img",
                                "attributes" => [
                                    "title" => html_entity_decode($image['title'] ?? ""),
                                    "width" => "100%",
                                    "src" => $image['image'],
                                ],
                            ];
                        }
                    }
                }
            } catch (Exception $e) {
                // ignore
            }
            return $element;
        }
    }
}
