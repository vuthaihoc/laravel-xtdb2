<?php

namespace Xtdb;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Fluent;

/**
 * Prototype (XTDB 2.2+): tables are schemaless. "create table t (a, b)" declares the table and
 * its columns (types are ignored, a later create adds columns); indexes and keys do not exist.
 */
class XtdbSchemaGrammar extends PostgresGrammar
{
    public function compileCreate(Blueprint $blueprint, Fluent $command)
    {
        return $this->declare($blueprint);
    }

    public function compileAdd(Blueprint $blueprint, Fluent $command)
    {
        return $this->declare($blueprint);
    }

    private function declare(Blueprint $blueprint): string
    {
        $columns = array_map(fn ($column) => $this->wrap($column->name), $blueprint->getAddedColumns());
        $columns = array_unique(array_merge([$this->wrap('_id')], $columns));

        return 'create table '.$this->wrapTable($blueprint).' ('.implode(', ', $columns).')';
    }

    // Removing a table erases its rows (history included); the table name stays known.
    public function compileDrop(Blueprint $blueprint, Fluent $command)
    {
        return 'erase from '.$this->wrapTable($blueprint).' where true';
    }

    public function compileDropIfExists(Blueprint $blueprint, Fluent $command)
    {
        return $this->compileDrop($blueprint, $command);
    }

    // No indexes, keys or constraints.
    public function compilePrimary(Blueprint $blueprint, Fluent $command) { return null; }
    public function compileUnique(Blueprint $blueprint, Fluent $command) { return null; }
    public function compileIndex(Blueprint $blueprint, Fluent $command) { return null; }
    public function compileFulltext(Blueprint $blueprint, Fluent $command) { return null; }
    public function compileSpatialIndex(Blueprint $blueprint, Fluent $command) { return null; }
    public function compileForeign(Blueprint $blueprint, Fluent $command) { return null; }
    public function compileComment(Blueprint $blueprint, Fluent $command) { return null; }
    public function compileTableComment(Blueprint $blueprint, Fluent $command) { return null; }
}
