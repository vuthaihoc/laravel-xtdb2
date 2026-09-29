<?php

namespace LaravelXtdb\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Fluent;
use LaravelXtdb\Exceptions\UnsupportedFeatureException;

/**
 * XTDB tables are schemaless (XTDB 2.2+). "create table t (a, b)" declares
 * the table and its columns, so queries can name them before a row is
 * written; a later "create table" adds columns. Column types, defaults,
 * indexes, keys and foreign keys do not exist and compile to nothing.
 */
class Grammar extends PostgresGrammar
{
    /** Columns every XTDB table has. */
    public const SYSTEM_COLUMNS = ['_valid_from', '_valid_to', '_system_from', '_system_to'];

    /**
     * Declarations are not transactional, and a migration that reads (hasTable)
     * before it writes would make an XTDB transaction read-only.
     *
     * @var bool
     */
    protected $transactions = false;

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command)
    {
        return $this->compileDeclare($blueprint);
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command)
    {
        return $this->compileDeclare($blueprint);
    }

    /**
     * "create table" with _id and the added columns, without types.
     */
    protected function compileDeclare(Blueprint $blueprint): string
    {
        $columns = ['_id'];

        foreach ($blueprint->getAddedColumns() as $column) {
            $columns[] = (string) $column->get('name');
        }

        return 'create table '.$this->wrapTable($blueprint).' ('.$this->columnize(array_values(array_unique($columns))).')';
    }

    /**
     * Removing a table erases its rows and their history; XTDB keeps the name.
     *
     * @param  Fluent<string, mixed>  $command
     */
    public function compileDrop(Blueprint $blueprint, Fluent $command)
    {
        return 'erase from '.$this->wrapTable($blueprint).' where true';
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileDropIfExists(Blueprint $blueprint, Fluent $command)
    {
        return $this->compileDrop($blueprint, $command);
    }

    /**
     * @param  string|string[]|null  $schema
     */
    public function compileTables($schema)
    {
        return 'select table_name as name, table_schema as schema from information_schema.tables where '
            .$this->compileSchemaWhereClause($schema, 'table_schema').' order by table_schema, table_name';
    }

    /**
     * @param  string|null  $schema
     * @param  string  $table
     */
    public function compileColumns($schema, $table)
    {
        return sprintf(
            'select column_name as name, data_type as type, is_nullable as nullable from information_schema.columns '
            .'where table_schema = %s and table_name = %s and column_name not in (%s) order by ordinal_position',
            $this->quoteString($schema ?? 'public'),
            $this->quoteString($table),
            implode(', ', array_map(fn (string $column) => $this->quoteString($column), self::SYSTEM_COLUMNS)),
        );
    }

    public function compileSchemas()
    {
        return "select schema_name as name, schema_name = 'public' as \"default\" from information_schema.schemata "
            ."where schema_name not in ('information_schema', 'pg_catalog', 'xt') order by schema_name";
    }

    /**
     * @param  string|string[]|null  $schema
     * @param  string  $column
     */
    protected function compileSchemaWhereClause($schema, $column)
    {
        if ($schema === null || $schema === []) {
            return $column." not in ('information_schema', 'pg_catalog', 'xt')";
        }

        return $column.' in ('.$this->quoteString($schema).')';
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileRename(Blueprint $blueprint, Fluent $command)
    {
        throw new UnsupportedFeatureException('Renaming a table is not supported by XTDB.');
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileRenameColumn(Blueprint $blueprint, Fluent $command)
    {
        throw new UnsupportedFeatureException('Renaming a column is not supported by XTDB.');
    }

    /**
     * Columns cannot be removed: the values stay in the existing rows.
     *
     * @param  Fluent<string, mixed>  $command
     */
    public function compileDropColumn(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /**
     * Column types do not exist, so a changed column needs no statement.
     *
     * @param  Fluent<string, mixed>  $command
     */
    public function compileChange(Blueprint $blueprint, Fluent $command)
    {
        return [];
    }

    /** @param  Fluent<string, mixed>  $command */
    public function compilePrimary(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /** @param  Fluent<string, mixed>  $command */
    public function compileUnique(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /** @param  Fluent<string, mixed>  $command */
    public function compileIndex(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /** @param  Fluent<string, mixed>  $command */
    public function compileFulltext(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /** @param  Fluent<string, mixed>  $command */
    public function compileSpatialIndex(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /** @param  Fluent<string, mixed>  $command */
    public function compileVectorIndex(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /** @param  Fluent<string, mixed>  $command */
    public function compileForeign(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /** @param  Fluent<string, mixed>  $command */
    public function compileDropPrimary(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /** @param  Fluent<string, mixed>  $command */
    public function compileDropUnique(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /** @param  Fluent<string, mixed>  $command */
    public function compileDropIndex(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /** @param  Fluent<string, mixed>  $command */
    public function compileDropFullText(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /** @param  Fluent<string, mixed>  $command */
    public function compileDropSpatialIndex(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /** @param  Fluent<string, mixed>  $command */
    public function compileDropForeign(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /** @param  Fluent<string, mixed>  $command */
    public function compileRenameIndex(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /** @param  Fluent<string, mixed>  $command */
    public function compileComment(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /** @param  Fluent<string, mixed>  $command */
    public function compileTableComment(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /** @param  Fluent<string, mixed>  $command */
    public function compileAutoIncrementStartingValues(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /**
     * @param  string  $name
     */
    public function compileCreateDatabase($name)
    {
        throw new UnsupportedFeatureException('XTDB has a single database per node.');
    }

    /**
     * @param  string  $name
     */
    public function compileDropDatabaseIfExists($name)
    {
        throw new UnsupportedFeatureException('XTDB has a single database per node.');
    }
}
