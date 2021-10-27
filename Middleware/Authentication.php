<?php

declare(strict_types=1);

namespace Zaplog\Middleware {

    use Atrox\Haikunator;
    use Multiavatar;
    use SlimRestApi\Infra\Db;
    use stdClass;

    class Authentication extends \SlimRestApi\Middleware\Authentication
    {
        static public function getSessionTtl(): int
        {
            return 24;
        }

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

        static public function updateIdentity(string $newuserid): array
        {
            // for now only accept email id's
            $newuserid = filter_var(filter_var(strtolower($newuserid), FILTER_SANITIZE_EMAIL), FILTER_VALIDATE_EMAIL);
            assert($newuserid !== false);
            Db::execute("UPDATE channels SET userid=:newuserid WHERE userid=:userid",
                [":newuserid" => $newuserid, ":userid" => parent::getSession()->userid]);
            return [
                "token" => parent::createSession($newuserid),
                "channel" => Db::fetch("SELECT * FROM channels WHERE userid=MD5(:userid)", [":userid" => $newuserid]),
            ];
        }

        // ----------------------------------------------
        // 2FA action, creates a session token.
        // Decorates the parent class method.
        // ----------------------------------------------

        static public function createSession(string $userid): array
        {
            // for now only accept email id's
            $userid = filter_var(filter_var(strtolower($userid), FILTER_SANITIZE_EMAIL), FILTER_VALIDATE_EMAIL);
            assert($userid !== false);
            // if we see a new user, we create a new channel for him/her
            $channelname = Haikunator::haikunate();
            $avatar = "data:image/svg+xml;base64," . base64_encode((new Multiavatar)($channelname, null, null));
            Db::execute("INSERT IGNORE channels(userid,name,avatar) VALUES (MD5(:userid),:name,:avatar)",
                [
                    ':userid' => $userid,
                    ':name' => $channelname,
                    ':avatar' => $avatar,
                ]);
            return [
                "token" => parent::createSession($userid),
                "channel" => Db::fetch("SELECT * FROM channels WHERE userid=MD5(:userid)", [":userid" => $userid]),
            ];
        }
    }
}