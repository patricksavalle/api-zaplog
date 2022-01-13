<?php

declare(strict_types=1);

namespace Zaplog\Plugins\ParsedownFilters {

    use Zaplog\Plugins\AbstractParsedownFilter;

    class TagHarvester extends AbstractParsedownFilter
    {
        protected static $tags = [];

        static public function getTags(): array
        {
            $return = array_values(self::$tags);
            self::$tags = [];
            return $return;
        }

        function __invoke(array $element): array
        {
            if (in_array($element['name'], ["em", "b", "i", "strong"])
                and strlen($element["text"]) <= 40) {
                self::$tags[$element["text"]] = $element["text"];
            }
            return $element;
        }
    }
}
