<?php

declare(strict_types=1);

namespace Zaplog\Middleware {

    use Exception;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;

    class DoublePostPreventor
    {
        public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface
        {
            // we will use the APCu cache, there is a risk the cache gets evicted before use
            $checksum = md5(__METHOD__ . serialize($request->getParsedBody() ?? []));
            if (apcu_fetch($checksum) !== false) {
                // double post
                throw new Exception("This post was already submitted, see concepts", 409);
            }
            apcu_add($checksum, $checksum, 60);
            return $next($request, $response);
        }
    }
}