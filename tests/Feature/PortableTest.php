<?php

namespace LaravelXtdb\Tests\Feature;

use DbPortable\Contracts\HistoricalReads;
use DbPortable\Contracts\SearchBox;
use DbPortable\DbPortableServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LaravelXtdb\XtdbServiceProvider;

/**
 * laravel-db-portable's contracts, with its service provider loaded as in an application.
 */
class PortableTest extends TestCase
{
    protected array $tables = ['xt_history', 'xt_words'];

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [XtdbServiceProvider::class, DbPortableServiceProvider::class];
    }

    public function test_the_builder_implements_the_contracts(): void
    {
        $this->assertInstanceOf(HistoricalReads::class, DB::table('xt_history'));
        $this->assertInstanceOf(SearchBox::class, DB::table('xt_history'));
    }

    public function test_as_of_time_reads_what_was_stored_then(): void
    {
        DB::table('xt_history')->insert(['_id' => 'h1', 'total' => 10]);
        usleep(300_000);
        $before = Carbon::now();
        usleep(300_000);
        DB::table('xt_history')->where('_id', 'h1')->update(['total' => 20]);
        DB::table('xt_history')->insert(['_id' => 'h2', 'total' => 5]);

        $this->assertSame(10, DB::table('xt_history')->asOfTime($before)->where('_id', 'h1')->value('total'));
        $this->assertSame(1, DB::table('xt_history')->asOfTime($before)->count());
        $this->assertSame(10, DB::table('xt_history as h')->asOfTime($before)->where('h._id', 'h1')->value('h.total'));
        $this->assertSame(2, DB::table('xt_history')->asOfTime($before)->readCurrent()->count());
        $this->assertSame(25, (int) DB::table('xt_history')->readStale()->sum('total'));
    }

    public function test_search_box(): void
    {
        DB::table('xt_words')->insert([['word' => 'Apple'], ['word' => 'pineapple'], ['word' => 'apply'], ['word' => '100%_sure']]);

        $this->assertSame(['Apple', 'apply'], DB::table('xt_words')->whereStartsWith('word', 'ap')->orderBy('word')->pluck('word')->all());
        $this->assertSame(['Apple', 'pineapple'], DB::table('xt_words')->whereContains('word', 'APPLE')->orderBy('word')->pluck('word')->all());
        $this->assertSame(['100%_sure'], DB::table('xt_words')->whereContains('word', '%_')->pluck('word')->all());
        $this->assertSame(['Apple', 'apply', 'pineapple'], DB::table('xt_words')->suggest('word', 'app')->pluck('word')->all());
    }
}
