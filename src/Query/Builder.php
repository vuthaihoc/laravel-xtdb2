<?php

namespace LaravelXtdb\Query;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use DbPortable\Contracts\HistoricalReads;
use DbPortable\Contracts\SearchBox;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LaravelXtdb\Exceptions\UnsupportedFeatureException;

/**
 * Every XTDB row needs an _id, and XTDB generates none: the builder adds a
 * ULID to the rows inserted without one.
 *
 * Implements laravel-db-portable's contracts: historical reads through
 * XTDB's system time, and the search box helpers with LIKE (XTDB has no
 * full-text search).
 */
class Builder extends BaseBuilder implements HistoricalReads, SearchBox
{
    /**
     * The instant the "from" table is read at (FOR SYSTEM_TIME AS OF), as a
     * TIMESTAMP literal.
     */
    public ?string $systemTime = null;

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
     * Read the "from" table as XTDB had stored it at a point in the past:
     * a DateTimeInterface, a timestamp (UTC unless it has an offset), or a
     * duration such as "-10s", "-5m", "-1h".
     *
     * @return $this
     */
    public function asOfTime(DateTimeInterface|string $time): static
    {
        $this->systemTime = "TIMESTAMP '".static::instant($time)->format('Y-m-d\TH:i:s.uP')."'";

        return $this;
    }

    /**
     * Reads do not contend with writes on XTDB: current data is read.
     *
     * @return $this
     */
    public function readStale(): static
    {
        return $this;
    }

    /**
     * @return $this
     */
    public function readCurrent(): static
    {
        $this->systemTime = null;

        return $this;
    }

    /**
     * Values starting with the search, ignoring case. position() rather than
     * LIKE: XTDB 2.2.0-beta3 ignores LIKE escapes, so % and _ in the search
     * could not match literally. XTDB has no unaccent(): $unaccent has no effect.
     *
     * @return $this
     */
    public function whereStartsWith(string $column, string $value, bool $unaccent = false, string $boolean = 'and'): static
    {
        return $this->whereRaw($this->position($column).' = 1', [$value], $boolean);
    }

    /**
     * Values containing the search, ignoring case.
     *
     * @return $this
     */
    public function whereContains(string $column, string $value, bool $unaccent = false, string $boolean = 'and'): static
    {
        return $this->whereRaw($this->position($column).' > 0', [$value], $boolean);
    }

    /**
     * Autocomplete: values starting with the search and, from 3 characters,
     * containing it; prefix matches first, then the shortest.
     *
     * @return $this
     */
    public function suggest(string $column, string $value, bool $unaccent = false): static
    {
        $this->where(function (self $query) use ($column, $value) {
            $query->whereStartsWith($column, $value);

            if (mb_strlen($value) >= 3) {
                $query->whereContains($column, $value, boolean: 'or');
            }
        });

        return $this
            ->orderByRaw('case when '.$this->position($column).' = 1 then 0 else 1 end', [$value])
            ->orderByRaw('length('.$this->grammar->wrap($column).')')
            ->orderBy($column);
    }

    /**
     * @param  string|string[]  $columns
     * @param  array<string, mixed>  $options
     * @return $this
     */
    public function selectFullTextRelevance(string|array $columns, string $value, string $as = 'relevance', array $options = []): static
    {
        throw new UnsupportedFeatureException('XTDB has no full-text search: use whereContains() or suggest().');
    }

    /**
     * @param  string|string[]  $columns
     * @param  array<string, mixed>  $options
     * @return $this
     */
    public function orderByFullTextRelevance(string|array $columns, string $value, array $options = [], string $direction = 'desc'): static
    {
        throw new UnsupportedFeatureException('XTDB has no full-text search: use whereContains() or suggest().');
    }

    /**
     * @param  string|string[]  $columns
     * @param  array<string, mixed>  $options
     * @return $this
     */
    public function searchFullText(string|array $columns, string $value, array $options = []): static
    {
        throw new UnsupportedFeatureException('XTDB has no full-text search: use whereContains() or suggest().');
    }

    /**
     * The case-insensitive position of the bound search in the column (1-based, 0 when absent).
     */
    protected function position(string $column): string
    {
        return 'position(lower(?) in lower('.$this->grammar->wrap($column).'))';
    }

    /**
     * The UTC instant of a DateTimeInterface, timestamp or relative duration.
     */
    public static function instant(DateTimeInterface|string $time): CarbonImmutable
    {
        $utc = new DateTimeZone('UTC');

        if ($time instanceof DateTimeInterface) {
            return CarbonImmutable::instance($time)->setTimezone($utc);
        }

        if (preg_match('/^-(\d+)(s|m|h)$/', $time, $match) === 1) {
            return CarbonImmutable::now($utc)->subSeconds((int) $match[1] * ['s' => 1, 'm' => 60, 'h' => 3600][$match[2]]);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $time) === 1) {
            return CarbonImmutable::parse($time, $utc)->setTimezone($utc);
        }

        throw new InvalidArgumentException("Invalid time [{$time}]: use a DateTimeInterface, a timestamp or a duration such as '-10s'.");
    }

    /**
     * A new _id: a lowercase ULID, as Eloquent's HasUlids generates.
     */
    public static function newId(): string
    {
        return strtolower((string) Str::ulid());
    }
}
