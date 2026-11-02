<?php

namespace Tests;

use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * Base for tests that run PlaceOrder in parallel OS processes (Concurrency::run).
 *
 * An in-memory SQLite database cannot be shared between processes, and RefreshDatabase wraps
 * each test in a transaction the other processes could not see. So these tests use the real
 * MySQL server with its own database and DatabaseMigrations. The database is switched through
 * the process environment, which the child processes inherit.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    use DatabaseMigrations;

    public const DATABASE = 'ecommerce_testing';

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        $this->useMysqlTestingDatabase();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->originalEnv as $key => $value) {
            $this->putEnv($key, $value === false ? null : $value);
        }
    }

    /**
     * Runs right before migrate:fresh: never wipe anything but the testing database.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $name = DB::connection()->getDatabaseName();

        if (DB::connection()->getDriverName() !== 'mysql' || $name !== self::DATABASE) {
            throw new RuntimeException("Refusing to migrate [{$name}]: concurrency tests only run on ".self::DATABASE.'.');
        }
    }

    private function useMysqlTestingDatabase(): void
    {
        $dotenv = Dotenv::createArrayBacked(dirname(__DIR__))->safeLoad();

        $host = (string) ($dotenv['DB_HOST'] ?? '127.0.0.1');
        $port = (string) ($dotenv['DB_PORT'] ?? '3306');
        $user = (string) ($dotenv['DB_USERNAME'] ?? 'root');
        $password = (string) ($dotenv['DB_PASSWORD'] ?? '');

        (new PDO("mysql:host={$host};port={$port}", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))
            ->exec('CREATE DATABASE IF NOT EXISTS `'.self::DATABASE.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $values = [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $host,
            'DB_PORT' => $port,
            'DB_DATABASE' => self::DATABASE,
            'DB_USERNAME' => $user,
            'DB_PASSWORD' => $password,
            'DB_URL' => '',
        ];

        foreach ($values as $key => $value) {
            $this->originalEnv[$key] = getenv($key);
            $this->putEnv($key, $value);
        }
    }

    private function putEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
