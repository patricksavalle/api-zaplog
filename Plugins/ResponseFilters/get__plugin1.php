<?php

declare(strict_types=1);

namespace Zaplog\Plugins\ResponseFilters {

    use stdClass;
    use Zaplog\Plugins\AbstractResponseFilter;

    class get__plugin1 extends AbstractResponseFilter
    {
        public function __invoke(string $uri, stdClass $args, &$data): array
        {
            error_log("Filter: " . __METHOD__);
            return $data;
        }
    }
}