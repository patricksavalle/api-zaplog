<?php


declare(strict_types=1);

namespace Zaplog\Plugins {

    // -----------------------------------------------------------------------------
    // composite design pattern
    //
    // This iterator will instantiate all plugins found in this directory:
    // -> Plugins/ParsedownFilters/*.php
    //
    // The plugins' __invoke() method will be called for every XHTML element the Parsedown
    // parser outputs.
    //
    // Plugins will be executed in undetermined order relative to eachother
    // -----------------------------------------------------------------------------

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
                $plugin = new $classname;
                if (!($plugin instanceof AbstractParsedownFilter)) {
                    error_log("$file is not an instance of AbstractParsedownFilter -> ignored");
                    continue;
                }
                $this->processors[] = $plugin;
            }
        }

        public function __invoke(array $element): array
        {
            foreach ($this->processors as $processor) $element = $processor($element);
            return $element;
        }

    }
}