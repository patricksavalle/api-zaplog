<?php

declare(strict_types = 1);

namespace Zaplog\Exception {

    class CurlException extends \Exception
    {
        public function __construct(string $url , int $httpcode = 400)
        {
            parent::__construct("CURL exception: " . $url, $httpcode);
        }
    }

}