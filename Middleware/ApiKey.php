<?php

declare(strict_types=1);

namespace Zaplog\Middleware {

    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use SlimRestApi\Infra\Ini;
    use Zaplog\Exception\UserException;

    class ApiKey
    {
        public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface
        {
            if (empty($request->getHeader('X-Api-Key')[0]) or $request->getHeader('X-Api-Key')[0] !== Ini::get("api_client_key")) {
                throw new UserException("Invalid token in X-Api-Key header", 401);
            }
            return $next($request, $response);
        }
    }
}