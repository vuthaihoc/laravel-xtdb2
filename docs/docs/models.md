# Models

Every XTDB row is identified by `_id`, and XTDB generates no keys. Use `HasXtdbKey`: the key is `_id`, a ULID
generated when the model is created.

```php
use Illuminate\Database\Eloquent\Model;
use LaravelXtdb\Eloquent\Casts\AsDocument;
use LaravelXtdb\Eloquent\HasXtdbKey;

class User extends Model
{
    use HasXtdbKey;

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'settings' => AsDocument::class,   // a queryable nested document
        ];
    }
}

User::create(['name' => 'Alice', 'settings' => ['theme' => 'dark', 'tags' => ['a']]]);
User::where('settings->theme', 'dark')->whereJsonContains('settings->tags', 'a')->get();
```

- Relations, soft deletes, casts, pagination, `chunk()`, `cursor()`, `firstOrCreate()` and `updateOrCreate()` work
  as usual. Foreign keys are ordinary columns holding the related `_id`.
- A model keeping Laravel's incrementing `id` gets a random positive 64-bit integer, stored as `id` and `_id`.
- `DB::table()->insert()` adds a ULID `_id` to rows without one; `insertGetId()` returns the generated key.
- Laravel's `array`/`json` casts store JSON **text**, whose fields XTDB cannot query: use `AsDocument`, or bind
  `new LaravelXtdb\Query\Document([...])` in the query builder.
