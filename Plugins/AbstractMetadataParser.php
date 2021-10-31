<?php

declare(strict_types=1);

namespace Zaplog\Plugins {

    // ------------------------------------------------------------------
    // This filter is called to parse the mimetype matching the filename
    // ------------------------------------------------------------------

    abstract class AbstractMetadataParser
    {
        abstract public function __invoke(string $url): array;
    }
}