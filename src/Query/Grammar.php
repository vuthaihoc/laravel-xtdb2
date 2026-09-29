<?php

namespace LaravelXtdb\Query;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use LaravelXtdb\Exceptions\UnsupportedFeatureException;
use LaravelXtdb\XtdbConnection;

/**
 * PostgreSQL grammar adjusted to XTDB's SQL dialect.
 */
class Grammar extends PostgresGrammar
{
    /**
     * FOR SYSTEM_TIME AS OF goes between the table and its alias.
     *
     * @param  Expression|string  $table
     */
    protected function compileFrom(BaseBuilder $query, $table)
    {
        if (! $query instanceof Builder || $query->systemTime === null || ! is_string($table)) {
            return parent::compileFrom($query, $table); // @phpstan-ignore argument.type (Laravel accepts expressions)
        }

        $clause = ' for system_time as of '.$query->systemTime;
        $parts = preg_split('/\s+as\s+/i', $table) ?: [$table];

        return count($parts) === 2
            ? 'from '.$this->wrapTable($parts[0]).$clause.' as '.$this->wrapValue($this->getTablePrefix().$parts[1])
            : 'from '.$this->wrapTable($table).$clause;
    }

    /**
     * XTDB has no RETURNING: the builder generates the key before inserting.
     *
     * @param  array<array-key, mixed>  $values
     * @param  string|null  $sequence
     */
    public function compileInsertGetId(BaseBuilder $query, $values, $sequence)
    {
        return $this->compileInsert($query, $values);
    }

    /**
     * An insert replaces the whole document with the same _id, and there are
     * no unique constraints: "insert or ignore" cannot be expressed.
     *
     * @param  array<array-key, mixed>  $values
     */
    public function compileInsertOrIgnore(BaseBuilder $query, array $values)
    {
        throw new UnsupportedFeatureException('insertOrIgnore() is not supported by XTDB: an insert replaces the document with the same _id.');
    }

    /**
     * @param  array<int, string>  $columns
     * @return never
     */
    public function compileInsertOrIgnoreUsing(BaseBuilder $query, array $columns, string $sql)
    {
        throw new UnsupportedFeatureException('insertOrIgnoreUsing() is not supported by XTDB.');
    }

    /**
     * PATCH merges the given columns into the document with the same _id, or
     * inserts it. Rows are matched by _id only (XTDB has no unique
     * constraints), and existing rows get every given column, not only the
     * $update ones.
     *
     * @param  array<int, array<string, mixed>>  $values
     * @param  array<int, string>  $uniqueBy
     * @param  array<array-key, mixed>  $update
     */
    public function compileUpsert(BaseBuilder $query, array $values, array $uniqueBy, array $update)
    {
        if ($uniqueBy !== ['_id']) {
            throw new UnsupportedFeatureException('upsert() on XTDB matches rows by _id only: pass [\'_id\'] as uniqueBy.');
        }

        foreach (array_keys($update) as $key) {
            if (! is_int($key)) {
                throw new UnsupportedFeatureException('upsert() on XTDB cannot update columns with expressions.');
            }
        }

        $records = array_map(fn (array $record) => '{'.implode(', ', array_map(
            fn (string $column, mixed $value) => $this->wrap($column).': '.$this->parameter($value),
            array_keys($record),
            $record,
        )).'}', $values);

        return 'patch into '.$this->wrapTable($query->from).' records '.implode(', ', $records);
    }

    /**
     * No row locks: SELECT ... FOR UPDATE / FOR SHARE are dropped.
     *
     * @param  bool|string  $value
     */
    protected function compileLock(BaseBuilder $query, $value)
    {
        return '';
    }

    /**
     * XTDB has no ILIKE: case-insensitive matching compares lower() values.
     *
     * @param  array<string, mixed>  $where
     */
    protected function whereLike(BaseBuilder $query, $where)
    {
        $where['operator'] = ($where['not'] ? 'not ' : '').($where['caseSensitive'] ? 'like' : 'ilike');

        return $this->whereBasic($query, $where);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    protected function whereBasic(BaseBuilder $query, $where)
    {
        $operator = strtolower((string) $where['operator']);

        if ($operator === 'ilike' || $operator === 'not ilike') {
            return sprintf(
                'lower(%s) %s lower(%s)',
                $this->wrap($where['column']),
                $operator === 'ilike' ? 'like' : 'not like',
                $this->parameter($where['value'])
            );
        }

        if (str_contains($operator, 'like')) {
            return sprintf('%s %s %s', $this->wrap($where['column']), $where['operator'], $this->parameter($where['value']));
        }

        return parent::whereBasic($query, $where);
    }

    /**
     * Nested fields: "col->a->b" is (col)."a"."b".
     *
     * @param  string  $value
     */
    protected function wrapJsonSelector($value)
    {
        $path = explode('->', $value);
        $field = $this->wrapSegments(explode('.', array_shift($path)));

        foreach ($path as $segment) {
            if (preg_match('/\[\d*\]/', $segment) === 1) {
                throw new UnsupportedFeatureException("Array indexes in JSON paths are not supported on XTDB [{$value}].");
            }
        }

        return '('.$field.').'.implode('.', array_map(fn (string $segment) => $this->wrapValue(trim($segment, '\'"')), $path));
    }

    /**
     * @param  string  $value
     */
    protected function wrapJsonBooleanSelector($value)
    {
        return $this->wrapJsonSelector($value);
    }

    /**
     * @param  string  $value
     */
    protected function wrapJsonBooleanValue($value)
    {
        return $value;
    }

    /**
     * whereJsonContains(): a scalar in a list. "IS TRUE" works around XTDB
     * 2.2.0-beta3 dropping matches when "? = any(list)" is null for other rows
     * and is followed by another AND condition.
     *
     * @param  string  $column
     * @param  string  $value
     */
    protected function compileJsonContains($column, $value)
    {
        return '('.$value.' = any('.$this->wrap($column).')) is true';
    }

    /**
     * @param  mixed  $binding
     */
    public function prepareBindingForJsonContains($binding)
    {
        if (is_array($binding) || is_object($binding)) {
            throw new UnsupportedFeatureException('whereJsonContains() on XTDB takes a single scalar value.');
        }

        return $binding;
    }

    /**
     * @param  string  $column
     * @param  string  $operator
     * @param  string  $value
     */
    protected function compileJsonLength($column, $operator, $value)
    {
        return 'cardinality('.$this->wrap($column).') '.$operator.' '.$value;
    }

    /**
     * @param  string|int  $seed
     */
    public function compileRandom($seed)
    {
        throw new UnsupportedFeatureException('inRandomOrder() is not supported by XTDB (no random()).');
    }

    /**
     * @param  string  $key
     * @param  mixed  $value
     */
    protected function compileJsonUpdateColumn($key, $value)
    {
        throw new UnsupportedFeatureException("Updating a JSON path [{$key}] is not supported by XTDB: update the whole column.");
    }

    /**
     * Rows are addressed by _id where PostgreSQL uses ctid.
     *
     * @param  array<string, mixed>  $values
     */
    protected function compileUpdateWithJoinsOrLimit(BaseBuilder $query, array $values)
    {
        $alias = $this->fromAlias($query);

        return 'update '.$this->wrapTable($query->from).' set '.$this->compileUpdateColumns($query, $values)
            .' where '.$this->wrap('_id').' in ('.$this->compileSelect($query->select($alias.'._id')).')';
    }

    protected function compileDeleteWithJoinsOrLimit(BaseBuilder $query)
    {
        $alias = $this->fromAlias($query);

        return 'delete from '.$this->wrapTable($query->from)
            .' where '.$this->wrap('_id').' in ('.$this->compileSelect($query->select($alias.'._id')).')';
    }

    /**
     * The alias of the "from" table, or its name.
     */
    protected function fromAlias(BaseBuilder $query): string
    {
        $from = (string) $this->getValue($query->from);
        $parts = preg_split('/\s+as\s+/i', $from) ?: [$from];

        return (string) end($parts);
    }

    /**
     * ERASE removes the rows and their history; the table name stays known.
     *
     * @return array<string, array<int, mixed>>
     */
    public function compileTruncate(BaseBuilder $query)
    {
        return ['erase from '.$this->wrapTable($query->from).' where true' => []];
    }

    /**
     * No savepoints: nested transactions are part of the outer one.
     */
    public function supportsSavepoints()
    {
        return false;
    }

    /**
     * The SQL the connection actually sends, bindings inlined as XTDB literals.
     *
     * @param  string  $sql
     * @param  array<array-key, mixed>  $bindings
     */
    public function substituteBindingsIntoRawSql($sql, $bindings)
    {
        $datesFromStrings = ! $this->connection instanceof XtdbConnection || $this->connection->datesFromStrings();

        return Literal::inline($sql, $bindings, $datesFromStrings);
    }
}
