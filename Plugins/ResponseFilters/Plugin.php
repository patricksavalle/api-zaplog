<?php

declare(strict_types=1);

namespace Zaplog\Plugins\ResponseFilters {

    use stdClass;

    class Plugin
    {
        static public function filter(string $uri, stdClass $args, array $data): array
        {
            error_log("Plugin: " . __METHOD__);
            return $data;
        }
    }
}