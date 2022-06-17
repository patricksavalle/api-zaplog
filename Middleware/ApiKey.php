<?php

declare(strict_types=1);

namespace Zaplog\Middleware {

    use Atrox\Haikunator;
    use Multiavatar;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use SlimRestApi\Infra\Db;
    use SlimRestApi\Infra\Ini;
    use stdClass;

    class ApiKey
    {
        public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface
        {
            if ($request->getHeader('X-Api-Key')[0] === Ini::get("api_client_key")) {
                return $next($request, $response);
            }
            // deny authorization
            return $response
                ->withJson("Invalid token in X-Api-Key header")
                ->withStatus(401)
                // we must return XBasic (not Basic) to prevent clients from opening the AUTH dialog
                ->withHeader('WWW-Authenticate', 'XBasic realm=api');
        }
    }
}