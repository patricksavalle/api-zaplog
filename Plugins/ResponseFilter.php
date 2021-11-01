<?php


declare(strict_types=1);

namespace Zaplog\Plugins {

    use stdClass;

    // ---------------------------------------------------------------------------
    // composite pattern
    //
    // This class execute all filters (files) that match the mangled request-name
    // 
    // request:
    //  - GET/links/id/10               get_links_id_10
    //
    // pluginfiles:
    //  - get_links_id_10__plugin.php   get_links_id_10    v
    //  - get__plugin1.php              get_               v
    //  - get_links__plugin2.php        get_links_         v
    //  - get_channels__plugin2.php     get_channels_      -
    //  - post_links__plugin3.php       post_links_        -
    //
    // Multiple plugins can match the same request and are executed in order,
    // from longest matching string length to shortest.
    //
    // This request:
    //  - GET/links/id/10
    //
    // Will execute these plugin in this order:   
    //  1. get_links_id_10__plugin.php
    //  2. get_links__plugin2.php 
    //  3. get__plugin1.php
    // --------------------------------------------------------------------------

    class ResponseFilter extends AbstractResponseFilter
    {
        protected $processors = [];

        public function __construct(string $method, string $uri)
        {
            $method = strtolower($method);

            // scan plugin direcory for plugins that match the request method
            foreach (glob("Plugins/ResponseFilters/{$method}_*.php") as $file) {

                // find plugins that match the request
                if (preg_match("/.*\/((\w+_)_\w+)\.php/", $file, $match) === 1
                    and stripos($method . "_" . str_replace("/", "_", $uri) . "_", $match[2]) === 0) {
                    $this->processors[] = [$match[2], $file, $match[1]];
                }
            }
            // execution order longest match to shortest
            usort($this->processors,
                function (array $x, array $y): int {
                    return -strcasecmp($x[0], $y[0]);
                });
        }

        public function __invoke(string $requestUri, stdClass $requestArgs, &$responseData)
        {
            foreach ($this->processors as [$request, $file, $classname]) {
                //execute the plugin
                require $file;
                $classname = "Zaplog\\Plugins\\ResponseFilters\\" . $classname;
                $filter = new $classname;
                assert($filter instanceof AbstractResponseFilter);
                $filter($requestUri, $requestArgs, $responseData);
            }
        }
    }
}