<?php

declare(strict_types=1);

namespace Zaplog\Plugins\ParsedownFilters {

    use ContentSyndication\Url;
    use SlimRestApi\Infra\Ini;
    use Zaplog\Plugins\AbstractParsedownFilter;

    class LinkDecorator extends AbstractParsedownFilter
    {
        function __invoke(array $element): array
        {
            if (strcasecmp($element['name'], 'a') === 0) {

                // add the redirector service to href's
                static $redirector = null;
                if ($redirector === null) $redirector = Ini::get("broken_link_redirector");
                if (isset($element['attributes']['href'])) {
                    $element['attributes']['href'] = $redirector . $element['attributes']['href'];
                }

                // add target= to links
                $element['attributes']['target'] = '_blank';
            }
            return $element;
        }
    }
}
