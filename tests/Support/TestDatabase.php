<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests\Support;

use RuntimeException;

/** Explicit, disposable database selection shared by parent tests and OS workers. */
final class TestDatabase
{
    public static function connection(string $sqlite = ':memory:'): array
    {
        if (getenv('AIRWALLEX_TEST_DATABASE') !== 'mysql') {
            return ['driver' => 'sqlite', 'database' => $sqlite, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 10000];
        }
        $database = getenv('AIRWALLEX_TEST_MYSQL_DATABASE') ?: 'airwallex_test';
        if (! preg_match('/^airwallex_test(?:_[a-z0-9]+)?$/D', $database)) {
            throw new RuntimeException('MySQL tests require an explicitly disposable airwallex_test database.');
        }

        return ['driver' => 'mysql', 'host' => getenv('AIRWALLEX_TEST_MYSQL_HOST') ?: '127.0.0.1', 'port' => getenv('AIRWALLEX_TEST_MYSQL_PORT') ?: '33079', 'database' => $database, 'username' => getenv('AIRWALLEX_TEST_MYSQL_USER') ?: 'root', 'password' => getenv('AIRWALLEX_TEST_MYSQL_PASSWORD') ?: '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_ai_ci', 'prefix' => '', 'strict' => true, 'engine' => 'InnoDB'];
    }
}
