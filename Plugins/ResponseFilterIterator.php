<?php


declare(strict_types=1);

namespace Zaplog\Plugins {

    use stdClass;

    class ResponseFilterIterator extends AbstractResponseFilter
    {
        protected $processors = [];

        public function __construct(string $method, string $uri)
        {
            $method = strtolower($method);

            // scan plugin direcory for plugins that match the request method
            foreach (glob("Plugins/ResponseFilters/{$method}_*.php") as $file) {

                // GET/links/id/10
                // get_links_id_10
                //  - get__plugin1.php           v
                //  - get_links__plugin2.php     v
                //  - get_channels__plugin2.php  x
                //  - post_links__plugin3.php    x

                // find plugins that match the request
                if (preg_match("/.*\/((\w+_)_\w+)\.php/", $file, $match) === 1
                    and stripos($method . "_" . str_replace("/", "_", $uri) . "_", $match[2]) === 0) {

                    //execute the plugin
                    require $file;
                    $classname = "Zaplog\\Plugins\\ResponseFilters\\" . $match[1];
                    $this->processors[] = new $classname;
                }
            }
        }

        public function __invoke(string $uri, stdClass $args, &$data)
        {
            foreach ($this->processors as $processor) $processor($uri, $args, $data);
        }
    }
}