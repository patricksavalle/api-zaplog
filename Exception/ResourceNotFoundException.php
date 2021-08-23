<?php

/**
 * Zaplog.COM
 *
 * @link:       api.Zaplog.com
 * @copyright:  VCK TRAVEL BV, 2016
 * @author:     patrick@patricksavalle.com
 *
 * Note: use coding standards at http://www.php-fig.org/psr/
 */

declare(strict_types = 1);

namespace Zaplog\Exception;

require_once 'Exception.php';

class ResourceNotFoundException extends Exception
{
    public function __construct(string $message = "", int $httpcode = 404)
    {
        parent::__construct($message, $httpcode);
    }
}