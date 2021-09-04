<?php

declare(strict_types=1);

namespace Zaplog\Middleware {

    use SlimRestApi\Infra\Db;
    use stdClass;

    class Authentication extends \SlimRestApi\Middleware\Authentication
    {
        static public function getSession(): stdClass
        {
            return Db::execute("SELECT channels.* FROM authentications JOIN channels ON emailhash=userid 
                WHERE token=:token", [":token" => static::$session_token])->fetch();
        }

        // ----------------------------------------------
        // 2FA action, creates a session token.
        // Decorates the parent class method.
        // ----------------------------------------------

        static public function createSession(string $userid): array
        {
            assert(filter_var($userid, FILTER_VALIDATE_EMAIL) !== false);
            // if we see a new user, we create a new channel for him/her
            Db::execute("INSERT IGNORE channels(emailhash) VALUES (MD5(:email))", [':email' => $userid]);
            return \SlimRestApi\Middleware\Authentication::createSession($userid);
        }
    }
}