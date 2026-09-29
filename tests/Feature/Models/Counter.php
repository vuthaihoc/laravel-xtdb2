<?php

namespace LaravelXtdb\Tests\Feature\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A model left with Laravel's default "id" key.
 */
class Counter extends Model
{
    protected $table = 'xt_counters';

    protected $guarded = [];

    public $timestamps = false;
}
