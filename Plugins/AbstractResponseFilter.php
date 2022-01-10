<?php

declare(strict_types=1);

namespace Zaplog\Plugins {

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
    abstract class AbstractResponseFilter
    {
        abstract public function __invoke(string $requestUri, stdClass $requestArgs, $responseData);

        /*
            responsedata is the payload just before it will be translated into JSON and returned, often an array
         */
    }
}