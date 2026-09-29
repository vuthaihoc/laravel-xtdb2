<?php

namespace Xtdb;

use Illuminate\Database\Connectors\PostgresConnector;
use PDO;

/** Prototype: XTDB rejects "set search_path to <identifier>"; parameters are inlined by the connection. */
class XtdbConnector extends PostgresConnector
{
    protected $options = [
        PDO::ATTR_CASE => PDO::CASE_NATURAL,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_EMULATE_PREPARES => true,
    ];

    protected function configureSearchPath($connection, $config) {}
}
