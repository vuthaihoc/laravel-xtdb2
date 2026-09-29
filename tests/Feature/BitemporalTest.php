<?php

namespace LaravelXtdb\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaravelXtdb\Tests\Feature\Models\Price;

/**
 * A price list over valid time: 10 from January, 12 from July, corrected to 11 for March.
 */
class BitemporalTest extends TestCase
{
    protected array $tables = ['xt_prices'];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('xt_prices', function (Blueprint $table) {
            $table->ulid('_id')->primary();
            $table->string('sku');
            $table->integer('price');
        });

        DB::table('xt_prices')->validFrom('2026-01-01', '2026-07-01')->insert(['_id' => 'p1', 'sku' => 'A', 'price' => 10]);
        DB::table('xt_prices')->validFrom('2026-07-01')->insert(['_id' => 'p1', 'sku' => 'A', 'price' => 12]);
        DB::table('xt_prices')->validFrom('2026-03-01', '2026-04-01')->where('_id', 'p1')->update(['price' => 11]);
    }

    public function test_reads_at_a_valid_time(): void
    {
        $price = fn (string $at) => DB::table('xt_prices')->asOfValidTime($at)->where('_id', 'p1')->value('price');

        $this->assertSame([10, 11, 10, 12], [$price('2026-02-01'), $price('2026-03-15'), $price('2026-05-01'), $price('2026-08-01')]);
        $this->assertNull($price('2025-12-31'));
        // The current valid time (later than July 2026 here).
        $this->assertSame(12, DB::table('xt_prices')->where('_id', 'p1')->value('price'));
    }

    public function test_history_and_periods(): void
    {
        $history = DB::table('xt_prices')->where('_id', 'p1')->history()->get();

        $this->assertSame([10, 11, 10, 12], $history->pluck('price')->all());
        $this->assertStringStartsWith('2026-03-01', (string) $history[1]->_valid_from);
        $this->assertStringStartsWith('2026-04-01', (string) $history[1]->_valid_to);
        $this->assertNull($history[3]->_valid_to);

        $this->assertSame([11, 10], DB::table('xt_prices')->validBetween('2026-03-15', '2026-05-01')->orderBy('_valid_from')->pluck('price')->all());
        $this->assertSame(4, DB::table('xt_prices')->forAllValidTime()->count());
    }

    public function test_system_time_keeps_what_was_known(): void
    {
        $before = Carbon::now();
        usleep(300_000);
        DB::table('xt_prices')->forAllValidTime()->where('_id', 'p1')->update(['price' => 99]);

        $this->assertSame([99], DB::table('xt_prices')->forAllValidTime()->distinct()->pluck('price')->all());
        $this->assertSame(10, DB::table('xt_prices')->asOfSystemTime($before)->asOfValidTime('2026-02-01')->where('_id', 'p1')->value('price'));
    }

    public function test_delete_for_a_period_and_erase(): void
    {
        DB::table('xt_prices')->validFrom('2026-05-01', '2026-06-01')->where('_id', 'p1')->delete();

        $this->assertNull(DB::table('xt_prices')->asOfValidTime('2026-05-15')->where('_id', 'p1')->value('price'));
        $this->assertSame(10, DB::table('xt_prices')->asOfValidTime('2026-04-15')->where('_id', 'p1')->value('price'));

        DB::table('xt_prices')->where('_id', 'p1')->erase();

        $this->assertSame(0, DB::table('xt_prices')->forAllValidTime()->forAllSystemTime()->count());
    }

    public function test_eloquent(): void
    {
        $price = Price::find('p1');
        $this->assertSame(12, $price->price);

        $price->price = 13;
        $price->saveValidFrom('2026-09-01');
        $this->assertSame(12, Price::asOfValidTime('2026-08-01')->find('p1')->price);
        $this->assertSame(13, Price::asOfValidTime('2026-10-01')->find('p1')->price);

        $versions = $price->versions();
        $this->assertSame([10, 11, 10, 12, 13], $versions->pluck('price')->all());
        $this->assertStringStartsWith('2026-09-01', (string) $versions->last()->_valid_from);

        $price->deleteValidFrom('2026-01-01', '2026-02-01');
        $this->assertNull(Price::asOfValidTime('2026-01-15')->find('p1'));

        $new = new Price(['sku' => 'B', 'price' => 5]);
        $new->saveValidFrom('2027-01-01');
        $this->assertNull(Price::asOfValidTime('2026-12-31')->find($new->getKey()));
        $this->assertSame(5, Price::asOfValidTime('2027-01-02')->find($new->getKey())->price);

        $price->erase();
        $this->assertSame(0, Price::forAllValidTime()->forAllSystemTime()->whereKey('p1')->count());
    }
}
