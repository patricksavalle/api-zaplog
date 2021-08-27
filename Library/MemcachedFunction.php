<?php

declare(strict_types = 1);

namespace Zaplog\Library {

    use SlimRestApi\Infra\Memcache;

    class MemcachedFunction
    {
        public function __invoke(callable $function, array $param_arr = [], int $expiration = 0)
        {
            return Memcache::call_user_func_array($function, $param_arr, 60);
        }
    }
}