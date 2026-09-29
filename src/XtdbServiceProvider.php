<?php

namespace LaravelXtdb;

use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use LaravelXtdb\Connectors\XtdbConnector;

class XtdbServiceProvider extends ServiceProvider
{
    /**
     * Register the `xtdb` database driver.
     *
     * A connector and a connection resolver (rather than a DatabaseManager
     * extension) let Laravel's ConnectionFactory build the connection like a
     * built-in driver: read/write connections, lazy PDO and reconnects work.
     */
    public function register(): void
    {
        $this->app->bind('db.connector.xtdb', XtdbConnector::class);

        Connection::resolverFor('xtdb', static fn ($connection, $database, $prefix, $config) => new XtdbConnection($connection, $database, $prefix, $config));
    }
}
