<?php

declare(strict_types=1);

namespace Zaplog\Middleware {

    use SlimRestApi\Infra\Db;
    use stdClass;

    class Authentication extends \SlimRestApi\Middleware\Authentication
    {
        // ---------------------------------------------------------------------------------------
        // Override of the parent calls, decorates the original call. Returns logged in channel
        // ---------------------------------------------------------------------------------------

        static public function getSession(): stdClass
        {
            return Db::execute("SELECT * FROM channels WHERE userid=:userid", [":userid" => parent::getSession()->userid])->fetch();
        }

        // ----------------------------------------------
        // 2FA action, creates a session token.
        // Decorates the parent class method.
        // ----------------------------------------------

        static public function createSession(string $userid): array
        {
            // if we see a new user, we create a new channel for him/her
            Db::execute("INSERT IGNORE channels(userid) VALUES (MD5(:email))", [':email' => $userid]);
            return parent::createSession($userid);
        }
    }
}