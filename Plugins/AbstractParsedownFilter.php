<?php

declare(strict_types=1);

namespace Zaplog\Plugins {

    // ----------------------------------------------------------
    // This filter is called on every HTML element that is
    // about to be outputted by the Parsedown conversion
    //
    // Element structure
    // ----------------------------------------------------------

    abstract class AbstractParsedownFilter
    {
        abstract public function __invoke(array $element): array;

        /*
            Example of element:

            $elements = [
                "name" => "a",
                "text" => "...",
                "attributes" => [
                    "href" => "...",
                    "title" => "...",
                ]
            ]

        */
    }
}