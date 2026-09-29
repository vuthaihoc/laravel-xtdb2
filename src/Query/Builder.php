<?php

namespace LaravelXtdb\Query;

use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Every XTDB row needs an _id, and XTDB generates none: the builder adds a
 * ULID to the rows inserted without one.
 */
class Builder extends BaseBuilder
{
    /**
     * @param  array<array-key, mixed>  $values
     */
    public function insert(array $values)
    {
        if ($values === []) {
            return true;
        }

        if (! is_array(reset($values))) {
            $values = [$values];
        }

        foreach ($values as $index => $row) {
            if (is_array($row) && ! array_key_exists('_id', $row)) {
                $values[$index] = ['_id' => static::newId()] + $row;
            }
        }

        return parent::insert($values);
    }

    /**
     * The key is generated here, as XTDB has no RETURNING. For _id it is a
     * ULID. Another $sequence (Laravel's default incrementing "id", which
     * Eloquent casts to int) gets a random positive 63-bit integer, also
     * stored as the _id.
     *
     * @param  array<string, mixed>  $values
     * @param  string|null  $sequence
     * @return int|string
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $sequence ??= '_id';
        $id = $values[$sequence] ?? $values['_id'] ?? ($sequence === '_id' ? static::newId() : random_int(1, PHP_INT_MAX));

        $values[$sequence] = $id;
        $values['_id'] ??= $id;

        $this->insert($values);

        return $id;
    }

    /**
     * XTDB fails min/max/sum/avg over a column that never held a value (its
     * type is "nothing", e.g. declared by a migration on an empty table),
     * where SQL gives NULL: the aggregate of no values.
     *
     * @param  string  $function
     * @param  array<int, mixed>  $columns
     * @return mixed
     */
    public function aggregate($function, $columns = ['*'])
    {
        try {
            return parent::aggregate($function, $columns);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'type :nothing') || str_contains($e->getMessage(), 'over type Nothing')) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * A new _id: a lowercase ULID, as Eloquent's HasUlids generates.
     */
    public static function newId(): string
    {
        return strtolower((string) Str::ulid());
    }
}
