<?php

declare(strict_types = 1);

namespace Zaplog\Exception {

    class ResourceNotFoundException extends \Exception
    {
        public function __construct(string $message = "", int $httpcode = 404)
        {
            parent::__construct($message, $httpcode);
        }
    }

}