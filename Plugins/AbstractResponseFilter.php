<?php

declare(strict_types=1);

namespace Zaplog\Plugins {

    use stdClass;

    /**
     * Place your plugins in the same directory as this plugin base class.
     * The plugins will automatically be executed for every API response,
     * in NO PARTICULAR ORDER. Don't rely on the order in which plugins run.
     *
     * @param string $uri - the path of the request
     * @param string $uri - the request and body and path parameters of the request
     * @param array $responseData - the response payload that can be modified and returned
     */
    abstract class AbstractResponseFilter
    {
        abstract public function __invoke(string $requestUri, stdClass $requestArgs, &$responseData): array;
    }
}