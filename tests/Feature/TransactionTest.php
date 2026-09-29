<?php

namespace LaravelXtdb\Tests\Feature;

use Illuminate\Support\Facades\DB;
use LaravelXtdb\Exceptions\XtdbTransactionException;
use RuntimeException;

class TransactionTest extends TestCase
{
    protected array $tables = ['xt_tx'];

    public function test_writes_commit(): void
    {
        DB::transaction(function () {
            DB::table('xt_tx')->insert(['_id' => 1, 'v' => 1]);
            DB::table('xt_tx')->where('_id', 1)->update(['v' => 2]);
        });

        $this->assertSame(2, DB::table('xt_tx')->where('_id', 1)->value('v'));
    }

    public function test_an_exception_rolls_back(): void
    {
        try {
            DB::transaction(function () {
                DB::table('xt_tx')->insert(['_id' => 1]);

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, DB::table('xt_tx')->count());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_nested_transactions_are_part_of_the_outer_one(): void
    {
        DB::transaction(fn () => DB::transaction(fn () => DB::table('xt_tx')->insert(['_id' => 1])));

        $this->assertSame(1, DB::table('xt_tx')->count());
    }

    public function test_a_query_after_a_write_is_rejected_with_advice(): void
    {
        $this->expectException(XtdbTransactionException::class);
        $this->expectExceptionMessage('Read before DB::transaction()');

        DB::transaction(function () {
            DB::table('xt_tx')->insert(['_id' => 1]);
            DB::table('xt_tx')->count();
        });
    }

    public function test_a_write_after_a_query_is_rejected_with_advice(): void
    {
        DB::table('xt_tx')->insert(['_id' => 1]);

        try {
            DB::transaction(function () {
                DB::table('xt_tx')->count();
                DB::table('xt_tx')->insert(['_id' => 2]);
            });
            $this->fail('The write was accepted.');
        } catch (XtdbTransactionException $e) {
            $this->assertStringContainsString('read-only', $e->getMessage());
        }

        $this->assertSame(1, DB::table('xt_tx')->count());
    }
}
