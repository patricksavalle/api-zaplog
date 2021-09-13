<?php

declare(strict_types = 1);

namespace Zaplog\Exception {

    use Exception;

    abstract class AssertException extends Exception
    {
        public function __construct(string $message, int $httpcode)
        {
            parent::__construct($message, $httpcode);
        }

        public function __invoke( $value )
        {
            if ($value===false) {
                throw $this;
            }
            return $value;
        }
    }

}