<?php

namespace LaravelXtdb\Query;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use DbPortable\Contracts\HistoricalReads;
use DbPortable\Contracts\SearchBox;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LaravelXtdb\Exceptions\UnsupportedFeatureException;

/**
 * Every XTDB row needs an _id, and XTDB generates none: the builder adds a
 * ULID to the rows inserted without one.
 *
 * Bitemporal queries: reads at a valid time and/or a system time
 * (asOfValidTime(), validBetween(), forAllValidTime(), asOfSystemTime(),
 * history()) and writes for a period of valid time (validFrom(), validTo(),
 * erase()).
 *
 * Implements laravel-db-portable's contracts: historical reads through
 * XTDB's system time, and the search box helpers with position() (XTDB has
 * no full-text search).
 */
class Builder extends BaseBuilder implements HistoricalReads, SearchBox
{
    /**
     * The system time the "from" table is read at: "for system_time as of ...",
     * "for all system_time"...
     */
    public ?string $systemTime = null;

    /**
     * The valid time the "from" table is read at, or the one an update/delete
     * applies to: "for valid_time as of ...", "for all valid_time"...
     */
    public ?string $validTime = null;

    /**
     * The period of valid time the next insert, update or delete applies to,
     * as TIMESTAMP literals: [from, to], null meaning now / unbounded.
     *
     * @var array{0: string|null, 1: string|null}|null
     */
    public ?array $validPeriod = null;

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
                $values[$index] = $row = ['_id' => static::newId()] + $row;
            }

            if (is_array($row) && $this->validPeriod !== null) {
                [$from, $to] = $this->validPeriod;
                $values[$index] = $row + array_filter([
                    '_valid_from' => $from === null ? null : new Expression($from),
                    '_valid_to' => $to === null ? null : new Expression($to),
                ]);
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
        $this->systemTime = 'for system_time as of '.static::timestamp($time);

        return $this;
    }

    /**
     * asOfTime(): the "from" table as XTDB had stored it at a system time.
     *
     * @return $this
     */
    public function asOfSystemTime(DateTimeInterface|string $time): static
    {
        return $this->asOfTime($time);
    }

    /**
     * Every version XTDB has stored, including the ones later corrected.
     *
     * @return $this
     */
    public function forAllSystemTime(): static
    {
        $this->systemTime = 'for all system_time';

        return $this;
    }

    /**
     * The rows valid at a point in time (by default XTDB reads the rows valid now).
     *
     * @return $this
     */
    public function asOfValidTime(DateTimeInterface|string $time): static
    {
        $this->validTime = 'for valid_time as of '.static::timestamp($time);

        return $this;
    }

    /**
     * The rows valid at some point of a period, $to null meaning unbounded.
     *
     * @return $this
     */
    public function validBetween(DateTimeInterface|string $from, DateTimeInterface|string|null $to = null): static
    {
        $this->validTime = 'for valid_time from '.static::timestamp($from).' to '.($to === null ? 'NULL' : static::timestamp($to));

        return $this;
    }

    /**
     * Every valid-time version. On update() and delete(): the whole history.
     *
     * @return $this
     */
    public function forAllValidTime(): static
    {
        $this->validTime = 'for all valid_time';

        return $this;
    }

    /**
     * Select the valid-time period of each row as well (_valid_from, _valid_to).
     *
     * @return $this
     */
    public function withValidTime(): static
    {
        if ($this->columns === null) {
            $this->columns = ['*'];
        }

        return $this->addSelect(['_valid_from', '_valid_to']);
    }

    /**
     * Every valid-time version with its period, oldest first.
     *
     * @return $this
     */
    public function history(): static
    {
        return $this->forAllValidTime()->withValidTime()->orderBy('_valid_from');
    }

    /**
     * The next insert, update or delete applies from $from (null: now). An
     * insert stores the row for that period; an update or delete changes only
     * that part of the history (FOR PORTION OF VALID_TIME).
     *
     * @return $this
     */
    public function validFrom(DateTimeInterface|string|null $from, DateTimeInterface|string|null $to = null): static
    {
        $this->validPeriod = [$from === null ? null : static::timestamp($from), $to === null ? null : static::timestamp($to)];

        return $this;
    }

    /**
     * The next insert, update or delete applies until $to.
     *
     * @return $this
     */
    public function validTo(DateTimeInterface|string|null $to): static
    {
        $this->validPeriod = [$this->validPeriod[0] ?? null, $to === null ? null : static::timestamp($to)];

        return $this;
    }

    /**
     * Remove the matching rows and their whole history (e.g. to comply with
     * a data deletion request); delete() only ends their validity.
     */
    public function erase(): int
    {
        /** @var Grammar $grammar */
        $grammar = $this->grammar;

        return $this->connection->delete(
            $grammar->compileErase($this),
            $this->cleanBindings($grammar->prepareBindingsForDelete($this->bindings))
        );
    }

    /**
     * An XTDB TIMESTAMP literal of a UTC instant.
     */
    public static function timestamp(DateTimeInterface|string $time): string
    {
        return "TIMESTAMP '".static::instant($time)->format('Y-m-d\TH:i:s.uP')."'";
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
        $this->validTime = null;

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
