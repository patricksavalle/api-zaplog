<?php

// explicitely set to OFF, see: https://gitlab.com/zaplog/web-zaplog/-/issues/61
declare(strict_types=0);

namespace Zaplog\Library {

    use Zaplog\Exception\UserException;

    class Avatar
    {
        protected $url;

        public function __construct(string $url = "")
        {
            (new UserException("Invalid link"))(filter_var($url, FILTER_VALIDATE_URL) !== false);
            $this->url = $url;
        }

        public function inlineBase64(): string
        {
            $type = get_headers($this->url, true)["Content-Type"] ?? "";
            (new UserException("Incorrect type"))($type !== false and strpos($type, "image/") === 0);
            $file = file_get_contents($this->url);
            (new UserException("Cannot read file"))($file !== false);
            $base64 = base64_encode($file);
            (new UserException("Image file too large (>12KB)"))(strlen($file) < 16384);
            return "data:$type;base64,$base64";
        }
    }
}