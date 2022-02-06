<?php

declare(strict_types=1);

namespace Zaplog\Plugins\ParsedownFilters {

    use Zaplog\Plugins\AbstractParsedownFilter;

    /*
     * Generates a HTML outline widget based on H2 headers in the Markdown
     */

    class OutlineGenerator extends AbstractParsedownFilter
    {
        protected static $outline = "";
        private static $count = 0;

        static public function getOutline(): ?string
        {
            $outline = "<ul>". self::$outline . "</ul>";
            return (self::$count<3 or self::$count>16 or strlen($outline)>1000) ? null : $outline;
        }

        function __invoke(array $element): array
        {
            if (in_array($element["name"], ["h1","h2","h3"])) {
                // add anchor / id
                $element['attributes']['id'] = "section" . self::$count;
                // add href to anchor
                self::$outline .= "<li><a href=#section'". self::$count . "'>" . $element['text'] . "</a></li>";
                self::$count++;
            }
            return $element;
        }
    }
}
