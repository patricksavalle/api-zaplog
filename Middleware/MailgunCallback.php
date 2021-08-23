<?php

declare(strict_types = 1);

namespace Zaplog\Middleware;

require_once BASE_PATH . '/Exception/AuthenticationException.php';

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SlimRestApi\Infra\Ini;
use Zaplog\Exception\AuthenticationException;

class MailgunCallback
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface
    {
        // see: https://documentation.mailgun.com/user_manual.html#securing-webhooks
        // and: http://php.net/manual/en/function.hash-hmac.php
        // and: https://github.com/mailgun/mailgun-php/blob/master/src/Mailgun/Mailgun.php#L75
        $args = (object)$_POST;
        if ((time() - $args->timestamp) < 15) {
            $hash = hash_hmac("sha256", $args->timestamp . $args->token, Ini::get('mailgun_api_key'));
            if (hash_equals($hash, $args->signature)) {
                return $next($request, $response);
            }
        }
        error_log('Illegal callback:' . print_r($_REQUEST, true));
        throw new AuthenticationException;
    }
}
