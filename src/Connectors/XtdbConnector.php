<?php

namespace LaravelXtdb\Connectors;

use Illuminate\Database\Connectors\PostgresConnector;
use PDO;

class XtdbConnector extends PostgresConnector
{
    /**
     * Bindings are inlined by the connection, so statements reach PDO without
     * parameters; emulated prepares save the server-side prepare round trip.
     *
     * @var array<int, int|bool>
     */
    protected $options = [
        PDO::ATTR_CASE => PDO::CASE_NATURAL,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_EMULATE_PREPARES => true,
    ];

    /**
     * XTDB has only the "public" schema and rejects "set search_path to <identifier>".
     *
     * @param  PDO  $connection
     * @param  array<string, mixed>  $config
     * @return void
     */
    protected function configureSearchPath($connection, $config) {}

    /**
     * @param  PDO  $connection
     * @param  array<string, mixed>  $config
     * @return void
     */
    protected function configureSynchronousCommit($connection, array $config) {}
}
