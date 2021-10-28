<?php

declare(strict_types=1);

namespace Zaplog\Plugins\ResponseFilters {

    use stdClass;
    use Zaplog\Plugins\AbstractPlugin;

    class get__Plugin1 extends AbstractPlugin
    {
        public function __invoke(string $uri, stdClass $args, &$data)
        {
            error_log("Filter: " . __METHOD__);
            return $data;
        }
    }
}