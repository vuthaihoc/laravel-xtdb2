<?php

require __DIR__.'/vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Xtdb\XtdbConnection;
use Xtdb\XtdbConnector;

$capsule = new Capsule;
$capsule->addConnection(['driver' => 'xtdb', 'host' => '127.0.0.1', 'port' => (int) (getenv('XTDB_PORT') ?: 5435), 'database' => 'xtdb', 'username' => 'xtdb', 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'sslmode' => 'disable']);
Connection::resolverFor('xtdb', fn ($pdo, $database, $prefix, $config) => new XtdbConnection($pdo, $database, $prefix, $config));
$capsule->getDatabaseManager()->extend('xtdb', function (array $config, string $name) {
    $config['name'] = $name;
    return new XtdbConnection(fn () => (new XtdbConnector)->connect($config), $config['database'], $config['prefix'], $config);
});
$capsule->setEventDispatcher(new Dispatcher(new Container));
$capsule->setAsGlobal();
$capsule->bootEloquent();
$db = $capsule->getConnection();
$schema = $capsule->schema();
$p = 's'.substr(uniqid(), -6).'_';

$pass = $fail = 0;
function t(string $label, callable $fn): void {
    global $pass, $fail;
    try { $r = $fn(); $pass++; echo "OK   $label".($r !== null ? ' => '.substr(json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 170) : '')."\n"; }
    catch (Throwable $e) { $fail++; echo "FAIL $label => ".get_class($e).': '.substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 230)."\n"; }
}

abstract class XModel extends Model { use HasUlids; protected $primaryKey = '_id'; }
class User extends XModel {
    use SoftDeletes;
    protected $table = 'x_users';
    protected $guarded = [];
    protected function casts(): array { return ['active' => 'boolean', 'settings' => 'array', 'born_at' => 'datetime', 'score' => 'float']; }
    public function posts() { return $this->hasMany(Post::class, 'user_id'); }
}
class Post extends XModel {
    protected $table = 'x_posts';
    protected $guarded = [];
    protected function casts(): array { return ['published_at' => 'datetime']; }
    public function user() { return $this->belongsTo(User::class, 'user_id'); }
}
$usersTable = 'x_users'; $postsTable = 'x_posts';
foreach ([$usersTable, $postsTable] as $table) { try { $db->statement("erase from \"{$table}\" where true"); } catch (Throwable) {} }
$U = fn () => User::query();
$P = fn () => Post::query();

echo "--- schema\n";
t('Schema::create users', fn () => $schema->create($usersTable, function (Blueprint $t) { $t->ulid('_id')->primary(); $t->string('name'); $t->string('email')->unique(); $t->boolean('active')->default(true); $t->json('settings')->nullable(); $t->float('score')->nullable(); $t->timestamp('born_at')->nullable(); $t->timestamps(); $t->softDeletes(); }));
t('Schema::create posts (+ foreign key)', fn () => $schema->create($postsTable, function (Blueprint $t) use ($usersTable) { $t->ulid('_id')->primary(); $t->foreignUlid('user_id')->constrained($usersTable); $t->string('title'); $t->text('body')->nullable(); $t->timestamp('published_at')->nullable(); $t->timestamps(); }));
t('Schema::hasTable', fn () => [$schema->hasTable($usersTable), $schema->hasTable($p.'nope')]);
t('Schema::hasColumn', fn () => [$schema->hasColumn($usersTable, 'email'), $schema->hasColumn($usersTable, 'nope')]);
t('Schema::getColumnListing', fn () => $schema->getColumnListing($usersTable));
t('Schema::table add column', fn () => $schema->table($usersTable, fn (Blueprint $t) => $t->string('nickname')->nullable()));
t('Schema::getTables', fn () => count(array_filter($schema->getTables(), fn ($x) => str_starts_with($x['name'], $p))));
t('Schema::getColumns types', fn () => array_column($schema->getColumns($usersTable), 'type_name', 'name'));

echo "--- eloquent writes\n";
$alice = null;
t('User::create', function () use (&$alice) { $alice = new User; $alice->fill(['name' => 'Alice', 'email' => 'alice@x.io', 'active' => true, 'settings' => ['theme' => 'dark', 'tags' => ['a']], 'score' => 9.5, 'born_at' => Carbon::parse('1990-05-06 07:08:09')])->save(); return $alice->getKey(); });
t('create 2 more', function () use ($U) { foreach ([['Bob', 'bob@x.io', false, 3.0], ['Chào 🚀', 'c@x.io', true, null]] as [$n, $e, $a, $s]) { $u = new User; $u->fill(['name' => $n, 'email' => $e, 'active' => $a, 'score' => $s])->save(); } return $U()->count(); });
t('find + casts round-trip', function () use ($U, &$alice) { $u = $U()->find($alice->getKey()); return ['active' => $u->active, 'settings' => $u->settings, 'score' => $u->score, 'born_at' => $u->born_at?->toDateTimeString(), 'created_at_class' => get_class($u->created_at)]; });
t('update via save', function () use ($U, &$alice) { $u = $U()->find($alice->getKey()); $u->name = 'Alice 2'; $u->save(); return $U()->find($alice->getKey())->only('name', 'email', 'active'); });
t('update keeps other columns', fn () => $U()->find($alice->getKey())->only('email', 'score', 'settings'));
t('query update() return value', fn () => $U()->where('active', false)->update(['score' => 4.0]));
t('increment', function () use ($U, &$alice) { $U()->whereKey($alice->getKey())->increment('score', 2); return $U()->find($alice->getKey())->score; });
t('Post create + belongsTo', function () use ($P, &$alice) { foreach (['P1' => '2026-01-01 10:00:00', 'P2' => null] as $title => $at) { $post = new Post; $post->fill(['user_id' => $alice->getKey(), 'title' => $title, 'published_at' => $at])->save(); } return $P()->count(); });

echo "--- eloquent reads\n";
t('where / orderBy / limit', fn () => $U()->where('score', '>', 1)->orderBy('score', 'desc')->limit(2)->pluck('name'));
t('whereIn / whereNull / whereNotNull', fn () => [$U()->whereIn('name', ['Bob', 'nobody'])->count(), $U()->whereNull('score')->count(), $U()->whereNotNull('born_at')->count()]);
t('where bool column', fn () => $U()->where('active', true)->count());
t('whereLike (case-insensitive)', fn () => $U()->whereLike('email', 'ALICE%')->count());
t('where "like"', fn () => $U()->where('name', 'like', 'Ali%')->count());
t('whereDate / whereYear', fn () => [$U()->whereDate('born_at', '1990-05-06')->count(), $U()->whereYear('born_at', 1990)->count()]);
t('where datetime >', fn () => $U()->where('born_at', '>', Carbon::parse('1980-01-01'))->count());
t('where created_at > now-1h', fn () => $U()->where('created_at', '>', Carbon::now()->subHour())->count());
t('json where settings->theme', fn () => $U()->where('settings->theme', 'dark')->count());
t('whereJsonContains', fn () => $U()->whereJsonContains('settings->tags', 'a')->count());
t('aggregates', fn () => [$U()->count(), $U()->sum('score'), $U()->max('score'), round((float) $U()->avg('score'), 2)]);
t('exists / doesntExist', fn () => [$U()->where('name', 'Bob')->exists(), $U()->where('name', 'zzz')->doesntExist()]);
t('paginate', fn () => ($pg = $U()->orderBy('name')->paginate(2))->total().' total, page items '.count($pg->items()));
t('chunk', function () use ($U) { $n = 0; $U()->orderBy('_id')->chunk(2, function ($rows) use (&$n) { $n += count($rows); }); return $n; });
t('cursor / lazy', fn () => iterator_count($U()->cursor()));
t('eager load with()', fn () => $U()->with('posts')->get()->mapWithKeys(fn ($u) => [$u->name => $u->posts->count()]));
t('whereHas', fn () => $U()->whereHas('posts', fn ($q) => $q->whereNotNull('published_at'))->pluck('name'));
t('withCount', fn () => $U()->withCount('posts')->pluck('posts_count', 'name'));
t('latest() / oldest()', fn () => $U()->latest()->value('name'));
t('select distinct', fn () => $U()->distinct()->pluck('active'));
t('groupBy having', fn () => $U()->selectRaw('active, count(*) as n')->groupBy('active')->having('n', '>', 0)->get()->toArray());
t('inRandomOrder', fn () => $U()->inRandomOrder()->count());
t('firstOrCreate (outside tx)', fn () => User::firstOrCreate(['email' => 'd@x.io'], ['name' => 'Dan'])->wasRecentlyCreated);
t('updateOrCreate', fn () => $U()->updateOrCreate(['email' => 'd@x.io'], ['name' => 'Dan 2'])->name);
t('upsert', fn () => $db->table($usersTable)->upsert([['_id' => 'fixed-1', 'email' => 'e@x.io', 'name' => 'Eve']], ['_id'], ['name']));

echo "--- deletes\n";
t('soft delete + withTrashed', function () use ($U) { $U()->where('name', 'Bob')->first()->delete(); return [$U()->count(), $U()->withTrashed()->count(), $U()->onlyTrashed()->count()]; });
t('restore', function () use ($U) { $U()->onlyTrashed()->first()->restore(); return $U()->count(); });
t('forceDelete', function () use ($U) { $U()->where('name', 'Bob')->first()->forceDelete(); return $U()->withTrashed()->count(); });

echo "--- query builder\n";
t('insertGetId (generated _id)', fn () => $db->table($postsTable)->insertGetId(['title' => 'qb', 'user_id' => 'x']));
t('insert multi rows', fn () => $db->table($postsTable)->insert([['_id' => 'm1', 'title' => 'a', 'user_id' => 'x'], ['_id' => 'm2', 'title' => 'b', 'user_id' => 'x']]));
t('delete() return value', fn () => $db->table($postsTable)->where('_id', 'm1')->delete());
t('join', fn () => $db->table($postsTable.' as p')->join($usersTable.' as u', 'u._id', '=', 'p.user_id')->pluck('u.name')->unique()->values());
t('union', fn () => $db->table($usersTable)->select('name')->union($db->table($postsTable)->select('title'))->count());
t('subquery whereIn', fn () => $U()->whereIn('_id', $db->table($postsTable)->select('user_id'))->count());
t('toRawSql', fn () => $U()->where('name', "O'Brien")->where('active', true)->toRawSql());

echo "--- transactions\n";
t('DB::transaction writes only', fn () => $db->transaction(function () use ($db, $postsTable) { $db->table($postsTable)->insert(['_id' => 't1', 'title' => 'tx']); $db->table($postsTable)->where('_id', 't1')->update(['title' => 'tx2']); return 'committed'; }));
t('DB::transaction read + write', fn () => $db->transaction(function () use ($db, $postsTable) { $n = $db->table($postsTable)->count(); $db->table($postsTable)->insert(['_id' => 't2', 'title' => 'n'.$n]); }));
t('rollback on exception', function () use ($db, $postsTable) { try { $db->transaction(function () use ($db, $postsTable) { $db->table($postsTable)->insert(['_id' => 't3', 'title' => 'x']); throw new RuntimeException('boom'); }); } catch (RuntimeException) {} return $db->table($postsTable)->where('_id', 't3')->count(); });
t('nested transaction', fn () => $db->transaction(fn () => $db->transaction(fn () => $db->table($postsTable)->insert(['_id' => 't4', 'title' => 'nested']))));

echo "--- bitemporal via raw SQL\n";
t('history FOR ALL VALID_TIME', fn () => array_map(fn ($r) => $r->name, $db->select("select name from \"{$usersTable}\" for all valid_time where \"_id\" = ? order by \"_valid_from\"", [$alice->getKey()])));
t('FOR SYSTEM_TIME AS OF (before)', fn () => $db->selectOne("select count(*) as n from \"{$usersTable}\" for system_time as of ?", [Carbon::parse('2020-01-01')])->n);

echo "--- truncate / drop\n";
t('truncate', function () use ($db, $postsTable) { $db->table($postsTable)->truncate(); return $db->table($postsTable)->count(); });
t('Schema::drop', function () use ($schema, $postsTable) { $schema->drop($postsTable); return $schema->hasTable($postsTable); });

echo "\n$pass passed, $fail failed\n";
