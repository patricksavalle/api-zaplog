<?php

declare(strict_types=1);

namespace Zaplog\Library {

    use Exception;
    use Slim\Http\Request;
    use SlimRestApi\Infra\Ini;
    use Zaplog\Exception\ServerException;

    class Translation
    {
        public function __invoke(string $text, string $target_lang, string $source_lang = ""): array
        {
            assert(preg_match($target_lang, "/^[a-z][a-z]$/"));
            assert(preg_match($source_lang, "/^([a-z][a-z])?$/"));
            $curl = curl_init();
            try {
                $postdata = http_build_query(
                    ['auth_key' => Ini::get("deepl_auth_key"),
                        'target_lang' => $target_lang,
                        'source_lang' => $source_lang,
                        'text' => $text]
                );
                curl_setopt($curl, CURLOPT_URL, Ini::get("deepl_api_url"));
                curl_setopt($curl, CURLOPT_TIMEOUT, 30);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // no echo, just return result
                curl_setopt($curl, CURLOPT_POST, 1);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $postdata);
                $content = curl_exec($curl);
                $error = curl_error($curl);
                $errno = curl_errno($curl);
                if (0 !== $errno or $content === false) {
                    throw new Exception($error, $errno);
                }
            } catch (Exception $e) {
                error_log($e->getMessage() . " in " . __CLASS__);
                throw new ServerException("Translation service unavailable or failing");
            } finally {
                curl_close($curl);
            }
            $return = json_decode($content, true)["translations"][0] ?? null;
            if ($return === null) {
                throw new Exception("Translation failure, service limited or unavailable");
            }
            return [$return["text"], strtolower($return["detected_source_language"])];
        }
    }
}