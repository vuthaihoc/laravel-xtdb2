<?php

namespace LaravelXtdb\Tests\Feature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LaravelXtdb\Eloquent\Casts\AsDocument;
use LaravelXtdb\Eloquent\HasXtdbKey;

class User extends Model
{
    use HasXtdbKey, SoftDeletes;

    protected $table = 'xt_users';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'score' => 'float', 'born_at' => 'datetime', 'settings' => AsDocument::class];
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'user_id');
    }
}
