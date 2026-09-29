<?php

namespace LaravelXtdb\Schema;

use Illuminate\Database\Schema\PostgresBuilder;

/**
 * XTDB has one schema ("public") and no indexes, foreign keys, views or types.
 */
class Builder extends PostgresBuilder
{
    /**
     * @return string[]
     */
    public function getCurrentSchemaListing()
    {
        return ['public'];
    }

    /**
     * @param  string  $table
     * @return list<array<string, mixed>>
     */
    public function getIndexes($table)
    {
        return [];
    }

    /**
     * @param  string  $table
     * @return list<array<string, mixed>>
     */
    public function getForeignKeys($table)
    {
        return [];
    }

    /**
     * @param  string|string[]|null  $schema
     * @return list<array<string, mixed>>
     */
    public function getViews($schema = null)
    {
        return [];
    }

    /**
     * @param  string|string[]|null  $schema
     * @return list<array<string, mixed>>
     */
    public function getTypes($schema = null)
    {
        return [];
    }

    /**
     * Erase the rows of every table (db:wipe, migrate:fresh, RefreshDatabase).
     * XTDB keeps the table names; their rows and history are gone.
     *
     * @return void
     */
    public function dropAllTables()
    {
        $excluded = (array) ($this->connection->getConfig('dont_drop') ?? []);

        foreach ($this->getTables($this->getCurrentSchemaListing()) as $table) {
            if (! in_array($table['name'], $excluded, true)) {
                $this->connection->statement('erase from '.$this->grammar->wrapTable($table['name']).' where true');
            }
        }
    }

    /**
     * ERASE fails on an unknown table: only a known table is erased.
     *
     * @param  string  $table
     * @return void
     */
    public function dropIfExists($table)
    {
        if ($this->hasTable($table)) {
            parent::dropIfExists($table);
        }
    }

    /**
     * @return void
     */
    public function dropAllViews() {}

    /**
     * @return void
     */
    public function dropAllTypes() {}

    /**
     * No foreign keys to check.
     *
     * @return bool
     */
    public function enableForeignKeyConstraints()
    {
        return true;
    }

    /**
     * @return bool
     */
    public function disableForeignKeyConstraints()
    {
        return true;
    }
}
