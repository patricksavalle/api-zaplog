<?php

declare(strict_types = 1);

namespace Zaplog\Library;

require_once BASE_PATH . '/Exception/ResourceNotFoundException.php';

use Exception;
use SlimRestApi\Infra\Db;
use SlimRestApi\Infra\Password;
use stdClass;
use Zaplog\Exception\ResourceNotFoundException;

class Locker
{
    /**
     * Store a json return a secret to this json,
     * can be used for many things among which a
     * login session, one-time login codes etc.
     */
    static public function stash($iterable, int $ttl = 10 * 60): string
    {
        // TODO get this to work and remove from datamodel.sql

//        Db::execute("CREATE TABLE IF NOT EXISTS tokens
//            (
//                hash               CHAR(32)  NOT NULL,
//                json               JSON      NOT NULL,
//                expirationdatetime TIMESTAMP NOT NULL,
//                PRIMARY KEY (hash),
//                INDEX ( expirationdatetime )
//            ) ENGINE = MYISAM;");
//
//        Db::execute("CREATE EVENT IF NOT EXISTS expire_tokens
//            ON SCHEDULE EVERY 1 HOUR
//            DO DELETE FROM tokens WHERE tokens.expirationdatetime < CURRENT_TIMESTAMP;");

        $json = json_encode($iterable);
        if (empty($json)) {
            throw new Exception;
        }
        $hash = Password::randomMD5();
        if (Db::execute("INSERT INTO tokens(hash, json, expirationdatetime) VALUES (:hash, :json, ADDDATE( NOW(), INTERVAL :ttl SECOND))",
                [
                    ":hash" => $hash,
                    ":json" => $json,
                    ":ttl" => $ttl,
                ])->rowCount() == 0
        ) {
            throw new Exception;
        }
        return $hash;
    }

    static public function unstash(string $hash): stdClass
    {
        // try to get the token from the database
        $row = Db::execute("SELECT json FROM tokens WHERE hash = :hash AND NOW() < expirationdatetime", [":hash" => $hash])->fetch();
        if (empty($row)) {
            throw new ResourceNotFoundException("Invalid or expired token or link", 401);
        }
        // remove the token from the database
        if (Db::execute("DELETE FROM tokens WHERE hash = :hash", [":hash" => $hash])->rowCount() == 0) {
            throw new Exception;
        }
        return json_decode($row->json);
    }

}
