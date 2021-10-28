<?php

declare(strict_types=1);

namespace Zaplog\Plugins\ParsedownFilters {

    use stdClass;
    use Zaplog\Plugins\AbstractPlugin;

    class plugin1 extends AbstractPlugin
    {
        public function __invoke(string $uri, stdClass $args, &$data)
        {
            error_log("Filter: " . __METHOD__);
            return $data;
        }
    }
}