<?php

namespace LaravelXtdb\Tests\KnownIssues;

use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Throwable;

/**
 * Known XTDB bugs and PostgreSQL incompatibilities the driver works around,
 * as plain SQL that PostgreSQL accepts. Each test asserts the PostgreSQL
 * behaviour, so it FAILS while the issue exists and starts PASSING once an
 * XTDB release fixes it; the driver workaround named in the test can then be
 * reconsidered.
 *
 * Not part of `composer test`; run with `composer test:known-issues`.
 */
abstract class TestCase extends BaseTestCase
{
    /** A table name prefix per test: XTDB has one database and keeps table names. */
    protected string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'ki_'.bin2hex(random_bytes(4)).'_';
    }

    protected function table(string $name): string
    {
        return '"'.$this->prefix.$name.'"';
    }

    /**
     * A new connection (a failed XTDB transaction must not leak into the next check).
     */
    protected static function connect(bool $emulatePrepares = true): PDO
    {
        return new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=disable', self::env('XTDB_HOST', '127.0.0.1'), self::env('XTDB_PORT', '5435'), self::env('XTDB_DATABASE', 'xtdb')),
            self::env('XTDB_USERNAME', 'xtdb'),
            self::env('XTDB_PASSWORD', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => $emulatePrepares, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
    }

    /**
     * Run statements on one fresh connection and return the rows of the last
     * one, or fail with the server error.
     *
     * @return list<array<string, mixed>>
     */
    protected function runSql(string ...$statements): array
    {
        $pdo = self::connect();
        $rows = [];

        foreach ($statements as $statement) {
            try {
                $result = $pdo->query($statement);
                $rows = $result !== false && $result->columnCount() > 0 ? $result->fetchAll() : [];
            } catch (Throwable $e) {
                $this->fail("XTDB rejected [{$statement}]: ".preg_replace('/\s+/', ' ', mb_substr($e->getMessage(), 0, 300)));
            }
        }

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    protected static function env(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }
}
