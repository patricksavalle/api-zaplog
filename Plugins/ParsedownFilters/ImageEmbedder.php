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
            $getImage = function (string $url): ?string {
                try {
                    // image-specific domains, read image from the metadata
                    foreach ([
                                 "https://www.flickr.com",
                                 "https://ibb.co",
                                 "https://imagebam.com",
                                 "https://www.gettyimages.com",
                             ] as $domain) {
                        error_log($domain);
                        if (stripos($url, $domain) === 0) {
                            return ((new HtmlMetadata)($url))["image"];
                        }
                    }
                } catch (Exception $e) {
                    // nothing
                }
                return null;
            };

            if (strcmp($element['name'], "a") === 0 and isset($element['attributes']['href'])) {

                if (preg_match("@(?<base>https:\/\/pbs\.twimg\.com\/media\/[\w-]+).*@", $element['attributes']['href'], $matches) === 1) {
                    // https://pbs.twimg.com/media/FEBc-7aUUAAQMxP?format=jpg&name=small
                    try {
                        return [
                            "name" => "img",
                            "text" => '',
                            "attributes" => [
                                "width" => "100%",
                                "src" => $matches['base'] . ".png",
                            ],
                        ];
                    } catch (Exception $e) {
                        // ignore
                    }
                } elseif (($image = $getImage($element['attributes']['href'])) !== null) {
                    return [
                        "name" => "img",
                        "text" => '',
                        "attributes" => [
                            "width" => "100%",
                            "src" => $image,
                        ],
                    ];
                }
            }
            return $element;
        }
    }
}
