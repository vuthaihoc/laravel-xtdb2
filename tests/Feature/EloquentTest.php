<?php

namespace LaravelXtdb\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use LaravelXtdb\Tests\Feature\Models\Counter;
use LaravelXtdb\Tests\Feature\Models\Post;
use LaravelXtdb\Tests\Feature\Models\User;

class EloquentTest extends TestCase
{
    protected array $tables = ['xt_users', 'xt_posts', 'xt_counters'];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('xt_users', function (Blueprint $table) {
            $table->ulid('_id')->primary();
            $table->string('name');
            $table->string('email');
            $table->boolean('active')->default(true);
            $table->float('score')->nullable();
            $table->timestamp('born_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('xt_posts', function (Blueprint $table) {
            $table->ulid('_id')->primary();
            $table->foreignUlid('user_id');
            $table->string('title');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    private function users(): User
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@x.io', 'active' => true, 'score' => 9.5, 'born_at' => Carbon::parse('1990-05-06 07:08:09'), 'settings' => ['theme' => 'dark', 'tags' => ['a']]]);
        User::create(['name' => 'Bob', 'email' => 'bob@x.io', 'active' => false, 'score' => 3.0]);
        User::create(['name' => 'Chào 🚀', 'email' => 'c@x.io', 'active' => true]);

        return $alice;
    }

    public function test_create_find_and_casts(): void
    {
        $alice = $this->users();

        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $alice->getKey());
        $found = User::find($alice->getKey());
        $this->assertTrue($found->active);
        $this->assertSame(9.5, $found->score);
        $this->assertSame('1990-05-06 07:08:09', $found->born_at->toDateTimeString());
        $this->assertSame(['theme' => 'dark', 'tags' => ['a']], $found->settings);
        $this->assertInstanceOf(Carbon::class, $found->created_at);
        $this->assertSame('Chào 🚀', User::where('email', 'c@x.io')->value('name'));
    }

    public function test_update_keeps_the_other_columns(): void
    {
        $alice = $this->users();

        $alice->update(['name' => 'Alice 2']);
        User::whereKey($alice->getKey())->increment('score', 2);

        $found = User::find($alice->getKey());
        $this->assertSame(['Alice 2', 'alice@x.io', 11.5], [$found->name, $found->email, $found->score]);
        $this->assertSame(['theme' => 'dark', 'tags' => ['a']], $found->settings);
    }

    public function test_queries(): void
    {
        $this->users();

        $this->assertSame(['Alice', 'Bob'], User::where('score', '>', 1)->orderByDesc('score')->pluck('name')->all());
        $this->assertSame(2, User::where('active', true)->count());
        $this->assertSame(1, User::whereLike('email', 'ALICE%')->count());
        $this->assertSame(1, User::whereDate('born_at', '1990-05-06')->whereYear('born_at', 1990)->count());
        $this->assertSame(1, User::where('settings->theme', 'dark')->count());
        $this->assertSame(1, User::whereJsonContains('settings->tags', 'a')->count());
        $this->assertSame(3, User::where('created_at', '>', now()->subMinute())->count());
        $this->assertEquals(12.5, User::sum('score'));
        $this->assertSame(2, User::orderBy('name')->paginate(2)->count());
        $this->assertSame(3, User::paginate(2)->total());
        $this->assertSame(3, User::cursor()->count());
    }

    public function test_relations(): void
    {
        $alice = $this->users();
        $alice->posts()->create(['title' => 'P1', 'published_at' => now()]);
        $alice->posts()->create(['title' => 'P2']);

        $this->assertSame(['Alice' => 2, 'Bob' => 0, 'Chào 🚀' => 0], User::with('posts')->get()->mapWithKeys(fn ($u) => [$u->name => $u->posts->count()])->sortKeys()->all());
        $this->assertSame(['Alice'], User::whereHas('posts', fn ($q) => $q->whereNotNull('published_at'))->pluck('name')->all());
        $this->assertSame(2, User::withCount('posts')->find($alice->getKey())->posts_count);
        $this->assertSame('Alice', Post::where('title', 'P1')->first()->user->name);
    }

    public function test_soft_deletes(): void
    {
        $this->users();
        $bob = User::where('name', 'Bob')->first();

        $bob->delete();
        $this->assertSame([2, 3, 1], [User::count(), User::withTrashed()->count(), User::onlyTrashed()->count()]);

        $bob->restore();
        $this->assertSame(3, User::count());

        $bob->forceDelete();
        $this->assertSame(2, User::withTrashed()->count());
    }

    public function test_first_or_create_and_update_or_create(): void
    {
        $this->users();

        $this->assertTrue(User::firstOrCreate(['email' => 'd@x.io'], ['name' => 'Dan'])->wasRecentlyCreated);
        $this->assertFalse(User::firstOrCreate(['email' => 'd@x.io'], ['name' => 'Other'])->wasRecentlyCreated);
        $this->assertSame('Dan 2', User::updateOrCreate(['email' => 'd@x.io'], ['name' => 'Dan 2'])->name);
        $this->assertSame(4, User::count());
    }

    public function test_a_model_with_the_default_id_key(): void
    {
        Schema::create('xt_counters', function (Blueprint $table) {
            $table->id();
            $table->integer('value');
        });

        $counter = Counter::create(['value' => 1]);

        $this->assertIsInt($counter->id);
        $this->assertSame(1, Counter::find($counter->id)->value);
    }
}
