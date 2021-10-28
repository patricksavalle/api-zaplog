<?php

declare(strict_types=1);

namespace Zaplog\Plugins {

    use Psr\Http\Message\ResponseInterface as Response;
    use Psr\Http\Message\ServerRequestInterface as Request;
    use stdClass;

    /**
     * Base class for plugins
     *
     * Plugins are executed based on their filename.
     *
     * @param string $uri - the path of the request
     * @param string $uri - the request and body and path parameters of the request
     * @param array $responseData - the response payload that can be modified and returned
     */
    abstract class AbstractPlugin
    {
        abstract public function __invoke(string $requestUri, stdClass $requestArgs, &$responseData);
    }
}