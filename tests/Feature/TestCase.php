<?php

namespace LaravelXtdb\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use LaravelXtdb\XtdbServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

/**
 * Feature tests run on an XTDB 2.2+ server (XTDB_HOST / XTDB_PORT, see phpunit.xml.dist).
 * XTDB keeps table names after their rows are erased, so each test erases the
 * tables it uses in setUp().
 */
abstract class TestCase extends OrchestraTestCase
{
    /** @var list<string> tables erased before each test */
    protected array $tables = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->tables as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [XtdbServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'xtdb');
        $app['config']->set('database.connections.xtdb', [
            'driver' => 'xtdb',
            'host' => self::env('XTDB_HOST', '127.0.0.1'),
            'port' => (int) self::env('XTDB_PORT', '5435'),
            'database' => self::env('XTDB_DATABASE', 'xtdb'),
            'username' => self::env('XTDB_USERNAME', 'xtdb'),
            'password' => self::env('XTDB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'sslmode' => 'disable',
        ]);
    }

    protected static function env(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }
}
