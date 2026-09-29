<?php

namespace LaravelXtdb;

use Closure;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use LaravelXtdb\Exceptions\XtdbTransactionException;
use LaravelXtdb\Query\Builder;
use LaravelXtdb\Query\Grammar as QueryGrammar;
use LaravelXtdb\Query\Literal;
use LaravelXtdb\Query\Processor;
use LaravelXtdb\Schema\Builder as SchemaBuilder;
use LaravelXtdb\Schema\Grammar as SchemaGrammar;

/**
 * An XTDB 2 connection over the PostgreSQL wire protocol.
 *
 * XTDB rejects untyped parameters in DML and keeps parameters sent as text
 * as text, so every statement runs with its bindings inlined as typed XTDB
 * literals (see Literal).
 */
class XtdbConnection extends PostgresConnection
{
    public function getDriverTitle()
    {
        return 'XTDB';
    }

    /**
     * Whether date-and-time strings ("2026-01-02 03:04:05") are sent as
     * timestamps. Eloquent formats dates to strings, so this is on unless the
     * connection sets 'dates_from_strings' => false.
     */
    public function datesFromStrings(): bool
    {
        return (bool) ($this->getConfig('dates_from_strings') ?? true);
    }

    /**
     * @return Builder
     */
    public function query()
    {
        return new Builder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    /**
     * @return SchemaBuilder
     */
    public function getSchemaBuilder()
    {
        if ($this->schemaGrammar === null) {
            $this->useDefaultSchemaGrammar();
        }

        return new SchemaBuilder($this);
    }

    protected function getDefaultQueryGrammar()
    {
        return new QueryGrammar($this);
    }

    protected function getDefaultSchemaGrammar()
    {
        return new SchemaGrammar($this);
    }

    protected function getDefaultPostProcessor()
    {
        return new Processor;
    }

    /**
     * Bindings keep their PHP types: Literal writes booleans and dates as
     * XTDB literals, where Laravel would turn them into integers and strings.
     * PDO never receives bindings (they are inlined), so this only shapes
     * toRawSql().
     *
     * @param  array<array-key, mixed>  $bindings
     * @return array<array-key, mixed>
     */
    public function prepareBindings(array $bindings)
    {
        return $bindings;
    }

    /**
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     */
    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        if ($bindings !== []) {
            $query = Literal::inline($query, $bindings, $this->datesFromStrings());
        }

        try {
            return parent::runQueryCallback($query, [], $callback);
        } catch (QueryException $e) {
            throw $this->transactionModeException($e) ?? $e;
        }
    }

    /**
     * XTDB transactions are read-only or read-write (decided by the first
     * statement), and a read-write transaction runs no queries.
     */
    protected function transactionModeException(QueryException $e): ?XtdbTransactionException
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'xtdb/queries-in-read-write-tx'),
            str_contains($message, 'Queries are unsupported in a DML transaction') => XtdbTransactionException::readInWriteTransaction($e),
            str_contains($message, 'xtdb/dml-in-read-only-tx'),
            str_contains($message, 'DML is not allowed in a READ ONLY transaction') => XtdbTransactionException::writeInReadTransaction($e),
            default => null,
        };
    }
}
