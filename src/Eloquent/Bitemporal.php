<?php

namespace LaravelXtdb\Eloquent;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use LaravelXtdb\Query\Builder as QueryBuilder;

/**
 * Valid-time history for XTDB models.
 *
 * - $model->versions(): every valid-time version of the row, oldest first,
 *   with its _valid_from / _valid_to attributes.
 * - $model->saveValidFrom($from, $to): save the changes for a period of
 *   valid time only (a price valid from next month, a correction of the past).
 * - $model->deleteValidFrom($from, $to): end the row's validity for a period.
 * - $model->erase(): remove the row and its whole history.
 *
 * Queries: Model::asOfValidTime($time), Model::validBetween($from, $to),
 * Model::forAllValidTime(), Model::asOfSystemTime($time) (see Query\Builder).
 *
 * @mixin Model
 */
trait Bitemporal
{
    /**
     * The period of valid time the pending save applies to.
     *
     * @var array{0: DateTimeInterface|string|null, 1: DateTimeInterface|string|null}|null
     */
    protected ?array $pendingValidPeriod = null;

    /**
     * Every valid-time version of this row, oldest first.
     *
     * @return Collection<int, static>
     */
    public function versions(): Collection
    {
        /** @var Collection<int, static> */
        return $this->newQueryWithoutScopes()->whereKey($this->getKey())->history()->get();
    }

    /**
     * Save the model for a period of valid time: from $from (null: now) to $to (null: unbounded).
     */
    public function saveValidFrom(DateTimeInterface|string|null $from, DateTimeInterface|string|null $to = null): bool
    {
        $this->pendingValidPeriod = [$from, $to];

        try {
            return $this->save();
        } finally {
            $this->pendingValidPeriod = null;
        }
    }

    /**
     * End the row's validity for a period of valid time; its history is kept.
     */
    public function deleteValidFrom(DateTimeInterface|string|null $from, DateTimeInterface|string|null $to = null): int
    {
        return $this->baseQueryForThisRow()->validFrom($from, $to)->delete();
    }

    /**
     * Remove the row and its whole history.
     */
    public function erase(): int
    {
        $erased = $this->baseQueryForThisRow()->erase();
        $this->exists = false;

        return $erased;
    }

    /**
     * @return Builder<static>
     */
    public function newModelQuery()
    {
        $query = parent::newModelQuery();

        if ($this->pendingValidPeriod !== null && $query->getQuery() instanceof QueryBuilder) {
            $query->getQuery()->validFrom(...$this->pendingValidPeriod);
        }

        return $query;
    }

    protected function baseQueryForThisRow(): QueryBuilder
    {
        /** @var QueryBuilder $query */
        $query = $this->newModelQuery()->whereKey($this->getKey())->toBase();

        return $query;
    }
}
