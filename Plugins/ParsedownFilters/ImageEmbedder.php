<?php

declare(strict_types=1);

namespace Zaplog\Plugins\ParsedownFilters {

    use ContentSyndication\HtmlMetadata;
    use Exception;
    use Zaplog\Plugins\AbstractParsedownFilter;

    // To avoid copyright claims we must:
    // - not publish from non-public URL's
    // - only deep link
    //
    // We satisfy these conditions if we only use images from public URL's that
    // are offered to us by HTML og/twitter metadata.

    class ImageEmbedder extends AbstractParsedownFilter
    {
        function __invoke(array $element): array
        {
            // domain we can safely deeplink
            $isAllowableDeeplink = function (string $url): bool {
                foreach ([
                             "https://pbs.twimg.com/media",
                             "https://i.ytimg.com",
                         ] as $domain)
                    if (stripos($url, $domain) === 0) return true;
                return false;
            };

            // get deeplinks through safe metadata inspection
            $getImageByMetadata = function (string $url): ?array {
                try {
                    // image-specific domains, read image from the public URL HTML-metadata
                    foreach ([
                                 "https://www.flickr.com",
                                 "https://ibb.co",
                                 "https://postimg.cc",
                                 "https://imagebam.com",
                                 "https://www.gettyimages.com",
                             ] as $domain)
                        if (stripos($url, $domain) === 0) return (new HtmlMetadata)($url);
                } catch (Exception $e) {
                    // nothing
                }
                return null;
            };

            if (strcmp($element['name'], "a") === 0 and isset($element['attributes']['href'])) {

                try {

                    // for specific domains we will translate links into images
                    $image = $getImageByMetadata($element['attributes']['href']);
                    if (!empty($image['image'])) {

                        return [
                            "name" => "img",
                            "attributes" => [
                                "class" => "image",
                                "title" => html_entity_decode($image['title'] ?? ""),
                                "width" => "100%",
                                "src" => $image['image'],
                            ],
                        ];
                    }

                } catch (Exception $e) {
                    // ignore
                }

            } elseif (strcmp($element['name'], "img") === 0 and isset($element['attributes']['src'])) {

                try {

                    // Specific domains we can allow as direct deeplinks
                    if ($isAllowableDeeplink($element['attributes']['src'])) {
                        return $element;
                    }

                    // other domains we must get through metadata-inspection
                    $metadata = (new HtmlMetadata)($element['attributes']['src']);
                    if (!empty($metadata['image'])) {

                        return [
                            "name" => "img",
                            "attributes" => [
                                "class" => "image",
                                "title" => html_entity_decode($metadata['title'] ?? ""),
                                "width" => "100%",
                                "src" => $metadata['image'],
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
