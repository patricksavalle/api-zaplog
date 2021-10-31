<?php


declare(strict_types=1);

namespace Zaplog\Plugins {

    use stdClass;

    // composite design pattern

    class ParsedownFilter extends AbstractParsedownFilter
    {
        protected $processors = [];

        public function __construct()
        {
            // scan plugin direcory for plugins
            foreach (glob("Plugins/ParsedownFilters/*.php") as $file) {
                //instantiate the plugin
                preg_match("/.*\/(\w+)\.php/", $file, $match);
                require $file;
                $classname = "Zaplog\\Plugins\\ParsedownFilters\\" . $match[1];
                $this->processors[] = new $classname;
            }
        }

        public function __invoke($element): array
        {
            foreach ($this->processors as $processor) $element = $processor($element);
            return $element;
        }

    }
}