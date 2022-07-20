<?php

declare(strict_types=1);

namespace Zaplog\Middleware {

    use Atrox\Haikunator;
    use Multiavatar;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use SlimRestApi\Infra\Db;
    use Zaplog\Exception\UserException;

    class Authentication extends \SlimRestApi\Middleware\Authentication
    {
        static public function getSessionTtl(): int
        {
            return 48;
        }

        // ---------------------------------------------------------------------------------------
        // Override of the parent calls, decorates the original call. Returns logged in channel
        // ---------------------------------------------------------------------------------------

        static public function getSession()
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
            (new UserException("Invalid userid"))($newuserid !== false);
            $hasheduserid = md5($newuserid);
            Db::execute("UPDATE channels SET userid=:newuserid WHERE userid=:userid",
                [":newuserid" => $hasheduserid, ":userid" => parent::getSession()->userid]);
            return [
                "token" => parent::createSession($newuserid),
                "channel" => Db::fetch("SELECT * FROM channels WHERE userid=:userid", [":userid" => $hasheduserid]),
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
            (new UserException("Invalid userid"))($userid !== false);
            $hasheduserid = md5($userid);
            // if we see a new user, we create a new channel for him/her
            $channelname = Haikunator::haikunate();
            $avatar = "data:image/svg+xml;base64," . base64_encode((new Multiavatar)($channelname, null, null));
            Db::execute("INSERT IGNORE channels(userid,name,avatar) VALUES (:userid,:name,:avatar)",
                [
                    ':userid' => $hasheduserid,
                    ':name' => $channelname,
                    ':avatar' => $avatar,
                ]);
            return [
                "token" => parent::createSession($userid),
                "channel" => Db::fetch("SELECT * FROM channels WHERE userid=:userid", [":userid" => $hasheduserid]),
            ];
        }

        public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface
        {
            $return = parent::__invoke($request, $response, $next);
            $session = parent::getSession();
            if (!empty($session)) {
                Db::execute("UPDATE channels SET lastseendatetime=CURRENT_TIMESTAMP WHERE userid=:userid", [":userid" => $session->userid]);
            }
            return $return;
        }
    }
}