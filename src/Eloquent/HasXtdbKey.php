<?php

namespace LaravelXtdb\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * XTDB identifies every row by its _id column, and generates none: the
 * model's key is _id, a ULID generated when the model is created.
 *
 * @mixin Model
 */
trait HasXtdbKey
{
    use HasUlids;

    public function getKeyName()
    {
        return '_id';
    }
}
