<?php

namespace LaravelXtdb\Tests\Feature\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelXtdb\Eloquent\Bitemporal;
use LaravelXtdb\Eloquent\HasXtdbKey;

class Price extends Model
{
    use Bitemporal, HasXtdbKey;

    protected $table = 'xt_prices';

    protected $guarded = [];

    public $timestamps = false;
}
