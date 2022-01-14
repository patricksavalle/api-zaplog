<?php

declare(strict_types=1);

namespace Zaplog\Plugins\ParsedownFilters {

    use Zaplog\Plugins\AbstractParsedownFilter;

    class TagHarvester extends AbstractParsedownFilter
    {
        protected static $tags = [];
        protected static $image = null;
        protected static $title = null;

        static public function getTags(): array
        {
            return self::$tags;
        }

        static public function getFirstImage(): ?string
        {
            return self::$image;
        }

        static public function getTitle(): ?string
        {
            return self::$title;
        }

        function __invoke(array $element): array
        {
            switch ($element["name"]) {

                case "em":
                case "b":
                case "i":
                case "strong":
                    self::$tags[$element["text"]] = $element["text"];
                    break;

                case "img":
                    if (self::$image === null) self::$image = $element["attributes"]["src"];
                    break;

                case "h1":
                    // remember the first h1 (as title)
                    if (self::$title === null) self::$title = $element["text"];
                    break;
            }
            return $element;
        }
    }
}
