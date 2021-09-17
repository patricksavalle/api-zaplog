<?php

declare(strict_types=1);

namespace Zaplog\Middleware {

    use Atrox\Haikunator;
    use SlimRestApi\Infra\Db;
    use stdClass;

    class Authentication extends \SlimRestApi\Middleware\Authentication
    {
        // ---------------------------------------------------------------------------------------
        // Override of the parent calls, decorates the original call. Returns logged in channel
        // ---------------------------------------------------------------------------------------

        static public function getSession(): stdClass
        {
            return Db::fetch("SELECT * FROM channels WHERE userid=:userid", [":userid" => parent::getSession()->userid]);
        }

        // ----------------------------------------------
        // 2FA action, creates a session token.
        // Decorates the parent class method.
        // ----------------------------------------------

        static public function createSession(string $userid): array
        {
            // if we see a new user, we create a new channel for him/her
            $channelname = Haikunator::haikunate();
            Db::execute("INSERT IGNORE channels(userid,name,) VALUES (MD5(:userid))",
                [
                    ':userid' => $userid,
                    ':name' => $channelname,
                    ':avatar' => "https://api.multiavatar.com/" . $channelname,
                ]);
            return [
                "token" => parent::createSession($userid),
                "channel" => Db::fetch("SELECT * FROM channels WHERE userid=MD5(:userid)", [":userid" => $userid]),
            ];
        }
    }
}