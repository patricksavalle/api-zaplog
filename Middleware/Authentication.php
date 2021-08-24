<?php

declare(strict_types = 1);

namespace Zaplog\Middleware;

require_once BASE_PATH . '/Exception/AuthenticationException.php';

use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SlimRestApi\Infra\Db;
use SlimRestApi\Infra\Password;
use stdClass;

// TODO change to HTTP Bearer authentication

class Authentication
{
    static protected $session_token = null;

    static public function token(): stdClass
    {
        return static::$session_token;
    }

    // ----------------------------------------------
    // 2FA trigger, creates a session token
    // Token will be autodeleted on inactivity
    // ----------------------------------------------

    static public function createSession( string $email )
    {
        assert(filter_var($email, FILTER_VALIDATE_EMAIL) !== false);
        // make sure a channel exists
        Db::execute("INSERT IGNORE channels(email) VALUES (:email)", [':email' => $email]);
        $token = Password::randomMD5();
        if (Db::execute("INSERT INTO sessions(token,channelid) 
                SELECT :token, id FROM channels WHERE email=:email",
                [
                    ":token" => $token,
                    ":email" => $email,
                ])->rowCount() == 0
        ) {
            throw new Exception;
        }
        return ['X-Session-Token' => $token];
    }

    // --------------------------------------------------
    // The SLIM API middleware plugin, verifies the token
    // --------------------------------------------------

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface
    {
        if (isset($request->getHeader('X-Session-Token')[0])
            and $this->tokenAuthentication($request->getHeader('X-Session-Token')[0])
        ) {
            return $next($request, $response);
        }

        return $response
            ->withJson("Invalid token in X-Session-Token header")
            ->withStatus(401)
            // we must return XBasic (not Basic) to prevent clients from opening the AUTH dialog
            ->withHeader('WWW-Authenticate', 'XBasic realm=api');
    }

    private function tokenAuthentication(string $token): bool
    {
        // check for the session token, a database event deletes timed-out sessions
        static::$session_token = Db::execute("SELECT * FROM sessions WHERE sessions.token=:token", [':token' => $token])->fetch();
        if (!static::$session_token) {
            return false;
        }
        // actualize the token
        Db::execute("UPDATE sessions SET lastupdate=NOW() WHERE token=:token", [':token' => $token]);
        return true;
    }

}
