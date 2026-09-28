<?php

namespace App\Database;

use Illuminate\Database\Connectors\MySqlConnector;
use PDO;
use PDOException;

/**
 * The MySQL connector, with a few patient retries when the host refuses the
 * socket.
 *
 * On suglow.com's Hostinger server a connection is sometimes refused outright
 * with "SQLSTATE[HY000] [2002] Operation not permitted" - under bursts (30
 * simultaneous requests: 9 refused; 20: none) and occasionally even at night.
 * The refusal comes from the operating system, not MySQL (max_user_connections
 * is 75), and lasts a moment. Laravel does not treat it as retryable, so every
 * one reached a shopper as "A database error occurred" and left part of the
 * page empty. A short, growing, jittered pause lets the burst clear; anything
 * else - a wrong password, an unknown database - fails at once as before.
 *
 * Bound as `db.connector.mysql` in AppServiceProvider.
 */
class RetryingMySqlConnector extends MySqlConnector
{
    /** Base pauses before each retry, in milliseconds; each gets up to as much again at random. */
    private const PAUSES_MS = [100, 250, 500];

    protected function createPdoConnection($dsn, $username, #[\SensitiveParameter] $password, $options)
    {
        foreach (self::PAUSES_MS as $pause) {
            try {
                return $this->attempt($dsn, $username, $password, $options);
            } catch (PDOException $e) {
                if (!str_contains($e->getMessage(), '[2002] Operation not permitted')) {
                    throw $e;
                }

                // Jitter, so the requests of one burst do not all come back
                // at the same instant and collide again.
                $this->pause($pause + random_int(0, $pause));
            }
        }

        return $this->attempt($dsn, $username, $password, $options);
    }

    protected function attempt($dsn, $username, #[\SensitiveParameter] $password, $options): PDO
    {
        return parent::createPdoConnection($dsn, $username, $password, $options);
    }

    protected function pause(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }
}
