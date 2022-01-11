<?php

declare(strict_types=1);

namespace Zaplog\Exception {

    class UserException extends AssertException
    {
        public function __construct(string $message = "", int $code = 400)
        {
            parent::__construct($message, $code);
        }
    }

}