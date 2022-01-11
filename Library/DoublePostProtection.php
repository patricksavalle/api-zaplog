<?php

declare(strict_types=1);

namespace Zaplog\Library {

    use Zaplog\Exception\UserException;

    class DoublePostProtection
    {
        public function __invoke($data, int $lock_ttl = 60)
        {
            // throw exception if we already locked this content
            (new UserException("Already submitted", 409))(apcu_add(md5(__METHOD__ . serialize($data)), __METHOD__, $lock_ttl));
        }
    }
}