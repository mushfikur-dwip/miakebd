<?php

namespace Tests\Unit;

use App\Database\RetryingMySqlConnector;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * suglow.com's host sometimes refuses the socket to MySQL outright -
 * "SQLSTATE[HY000] [2002] Operation not permitted" - for a moment, under a
 * burst of connections or even at night. Laravel does not retry that error,
 * so every refusal reached a shopper as "A database error occurred".
 */
class RetryingMySqlConnectorTest extends TestCase
{
    /** A connector whose attempts fail as scripted; pauses are recorded, not slept. */
    private function connector(array $failures): RetryingMySqlConnector
    {
        return new class($failures) extends RetryingMySqlConnector {
            public int $attempts = 0;
            public array $pauses = [];

            public function __construct(private array $failures)
            {
            }

            public function open(): PDO
            {
                return $this->createPdoConnection('mysql:host=localhost', 'user', 'secret', []);
            }

            protected function attempt($dsn, $username, $password, $options): PDO
            {
                $this->attempts++;

                if ($message = array_shift($this->failures)) {
                    throw new PDOException($message);
                }

                return new PDO('sqlite::memory:');
            }

            protected function pause(int $milliseconds): void
            {
                $this->pauses[] = $milliseconds;
            }
        };
    }

    private const REFUSED = 'SQLSTATE[HY000] [2002] Operation not permitted';

    public function test_a_refused_socket_is_tried_again_after_a_pause(): void
    {
        $connector = $this->connector([self::REFUSED, self::REFUSED]);

        $this->assertInstanceOf(PDO::class, $connector->open());
        $this->assertSame(3, $connector->attempts);
        $this->assertCount(2, $connector->pauses);
        // Growing, so a burst has time to clear.
        $this->assertGreaterThan($connector->pauses[0], $connector->pauses[1]);
    }

    public function test_it_gives_up_after_a_few_tries(): void
    {
        $connector = $this->connector(array_fill(0, 10, self::REFUSED));

        try {
            $connector->open();
            $this->fail('the refusal should surface once the retries run out');
        } catch (PDOException $e) {
            $this->assertStringContainsString('Operation not permitted', $e->getMessage());
        }

        $this->assertSame(4, $connector->attempts);
    }

    /** A wrong password will not fix itself; retrying it only delays the error. */
    public function test_other_errors_are_not_retried(): void
    {
        $connector = $this->connector(["SQLSTATE[HY000] [1045] Access denied for user 'u'@'localhost'"]);

        $this->expectException(PDOException::class);

        try {
            $connector->open();
        } finally {
            $this->assertSame(1, $connector->attempts);
            $this->assertSame([], $connector->pauses);
        }
    }

    public function test_it_is_the_connector_laravel_uses_for_mysql(): void
    {
        $this->assertInstanceOf(RetryingMySqlConnector::class, app('db.connector.mysql'));
    }
}
