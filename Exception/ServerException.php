<?php

declare(strict_types = 1);

namespace Zaplog\Exception {

    class ServerException extends AssertException
    {
        public function __construct(string $message = "")
        {
            parent::__construct($message, 500);
        }
    }

}