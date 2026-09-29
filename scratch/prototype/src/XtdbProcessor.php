<?php

namespace Xtdb;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\PostgresProcessor;

class XtdbProcessor extends PostgresProcessor
{
    // Prototype: the insert already carries its _id; return it.
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        $query->getConnection()->insert($sql, $values);
        $sequence = $sequence ?: '_id';
        $keys = array_keys($query->getConnection()->lastInsertValues ?? []);

        return $query->getConnection()->lastInsertValues[$sequence] ?? null;
    }
}
