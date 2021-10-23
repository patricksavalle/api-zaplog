<?php

declare(strict_types=1);

namespace Zaplog\Library {

    use Zaplog\Exception\UserException;

    class Avatar
    {
        protected $url;

        public function __construct(string $url = "")
        {
            (new UserException)(filter_var($url, FILTER_VALIDATE_URL) !== false);
            $this->url = $url;
        }

        public function inlineBase64(): string
        {
            $type = mime_content_type($this->url);
            (new UserException)($type !== false and strpos($type, "image/") === 0);
            $file = file_get_contents($this->url);
            (new UserException)($file !== false);
            $base64 = base64_encode($file);
            (new UserException)(strlen($file)<8192);
            return "data:$type;base64,$base64";
        }
    }
}
