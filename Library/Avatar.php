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
            /** @noinspection PhpStrictTypeCheckingInspection */
            $type = get_headers($this->url, 1)["Content-Type"] ?? "";
            error_log($type);
            (new UserException)($type !== false and strpos($type, "image/") === 0);
            $file = file_get_contents($this->url);
            (new UserException)($file !== false);
            $base64 = base64_encode($file);
            (new UserException("Image file too large"))(strlen($file)<16384);
            return "data:$type;base64,$base64";
        }
    }
}
