<?php

declare(strict_types=1);

namespace Zaplog\Plugins\ResponseFilters {

    use stdClass;
    use Zaplog\Plugins\AbstractResponseFilter;

    class get_links__ResponseFilter2 extends AbstractResponseFilter
    {
        public function __invoke(string $uri, stdClass $args, &$data)
        {
            error_log("Filter: " . __METHOD__);
            return $data;
        }
    }
}