<?php

namespace Xtdb;

use Closure;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

class XtdbConnection extends PostgresConnection
{
    /** @var array<string, mixed>|null the values of the last insertGetId(), prototype only */
    public ?array $lastInsertValues = null;

    protected function getDefaultQueryGrammar()
    {
        return new XtdbGrammar($this);
    }

    protected function getDefaultSchemaGrammar()
    {
        return new XtdbSchemaGrammar($this);
    }

    protected function getDefaultPostProcessor()
    {
        return new XtdbProcessor;
    }

    public function query()
    {
        return new class($this, $this->getQueryGrammar(), $this->getPostProcessor()) extends Builder {
            public function insertGetId(array $values, $sequence = null)
            {
                $sequence = $sequence ?: '_id';
                $values[$sequence] ??= (string) Str::ulid();
                $this->connection->lastInsertValues = $values;

                return parent::insertGetId($values, $sequence);
            }
        };
    }

    // Every statement runs with its bindings inlined as typed XTDB literals.
    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        return parent::runQueryCallback(Literal::inline($query, $bindings, (bool) ($this->getConfig('dates_from_strings') ?? true)), [], $callback);
    }
}
