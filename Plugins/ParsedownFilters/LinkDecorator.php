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
            if (isset($element['attributes']['href'])
                and isset($element['text'])
                and strcmp($element['attributes']['href'], $element['text']) === 0) {
                $element['text'] = (new Url($element['attributes']['href']))->getDomain();
            }
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
