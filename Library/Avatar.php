<?php

declare(strict_types=1);

namespace Zaplog\Library {

    class Avatar
    {
        protected $url;

        public function __construct(string $url = "")
        {
            assert(filter_var($url, FILTER_VALIDATE_URL) !== false);
            $this->url = $url;
        }

        public function inlineBase64(): string
        {
            $type = mime_content_type($this->url);
            assert($type !== false and strpos($type, "image/") === 0);
            $file = file_get_contents($this->url);
            assert($file !== false);
            $base64 = base64_encode($file);
            assert(strlen($base64) < 8192);
            return "data:$type;base64,$base64";
        }
    }
}
