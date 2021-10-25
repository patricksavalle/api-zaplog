<?php

declare(strict_types=1);

namespace Zaplog\Library {

    use SlimRestApi\Infra\Ini;

    class ParsedownProcessor
    {
        // Used in the Parsedown parser to prcoess XHTML elements before they're outputted
        function __invoke($element): array
        {
            static $redirector = null;
            if ($redirector === null) $redirector = Ini::get("broken_link_redirector");
            // add the redirector service to href's
            if (isset($element['attributes']['href'])) {
                $element['attributes']['href'] = $redirector . $element['attributes']['href'];
            }
            // add target= to links
            if (strcasecmp($element['name'], 'a') === 0) {
                $element['attributes']['target'] = '_blank';
            }
            return $element;
        }
    }
}
