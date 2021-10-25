<?php

declare(strict_types=1);

namespace Zaplog\Library {

    use SlimRestApi\Infra\Ini;

    class ParsedownProcessor
    {
        function __invoke($element): array
        {
            static $redirector = null;
            if ($redirector === null) $redirector = Ini::get("broken_link_redirector");
            if (isset($element['attributes']['href'])) {
                $element['attributes']['href'] = $redirector . $element['attributes']['href'];
            }
            if (strcasecmp($element['name'], 'a') === 0) {
                $element['attributes']['target'] = '_blank';
            }
            return $element;
        }
    }
}
