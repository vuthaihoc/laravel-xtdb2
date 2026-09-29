<?php

namespace LaravelXtdb\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use LaravelXtdb\Tests\Feature\Models\Policy;
use LaravelXtdb\Tests\Feature\Models\Price;
use LaravelXtdb\Tests\Feature\Models\Salary;

/**
 * The use cases of docs/bitemporal.md.
 */
class BitemporalUseCasesTest extends TestCase
{
    protected array $tables = ['xt_prices', 'xt_salaries', 'xt_policies'];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('xt_prices', function (Blueprint $table) {
            $table->string('sku');
            $table->integer('price');
        });
        Schema::create('xt_salaries', function (Blueprint $table) {
            $table->string('employee_id');
            $table->integer('amount');
        });
        Schema::create('xt_policies', fn (Blueprint $table) => $table->string('customer_id'));
    }

    public function test_scheduled_price_changes_and_a_one_week_promotion(): void
    {
        $price = new Price(['sku' => 'A-100', 'price' => 10]);
        $price->saveValidFrom('2026-01-01');

        $price->price = 12;
        $price->saveValidFrom('2026-07-01');
        $price->price = 9;
        $price->saveValidFrom('2026-07-20', '2026-07-27');

        $at = fn (string $date) => Price::asOfValidTime($date)->where('sku', 'A-100')->value('price');

        $this->assertSame([10, 12, 9, 12], [$at('2026-06-15'), $at('2026-07-15'), $at('2026-07-22'), $at('2026-08-01')]);
    }

    public function test_a_back_dated_raise_and_what_payroll_knew(): void
    {
        $salary = new Salary(['employee_id' => 'e1', 'amount' => 1000]);
        $salary->saveValidFrom('2026-01-01');

        usleep(200_000);
        $payrollRanAt = Carbon::now();
        usleep(200_000);

        $salary->amount = 1200;
        $salary->saveValidFrom('2026-02-01');

        $february = Salary::asOfValidTime('2026-02-15')->where('employee_id', 'e1')->value('amount');
        $paid = Salary::asOfSystemTime($payrollRanAt)->asOfValidTime('2026-02-15')->where('employee_id', 'e1')->value('amount');

        $this->assertSame([1200, 1000, 200], [$february, $paid, $february - $paid]);
        $this->assertSame(1000, Salary::asOfValidTime('2026-01-15')->where('employee_id', 'e1')->value('amount'));
    }

    public function test_coverage_periods_with_a_suspension(): void
    {
        $policy = new Policy(['customer_id' => 'c1']);
        $policy->saveValidFrom('2026-01-01', '2027-01-01');
        $policy->deleteValidFrom('2026-03-01', '2026-04-01');

        $this->assertNull(Policy::asOfValidTime('2026-03-15')->find($policy->getKey()));
        $this->assertNotNull(Policy::asOfValidTime('2026-04-15')->find($policy->getKey()));
        // Active at some point of the period: yes across the suspension start, no within it.
        $this->assertSame(1, Policy::validBetween('2026-02-20', '2026-03-20')->count());
        $this->assertSame(0, Policy::validBetween('2026-03-10', '2026-03-20')->count());
        $this->assertSame(0, Policy::asOfValidTime('2027-02-01')->count());
    }

    public function test_restoring_an_earlier_value(): void
    {
        $price = new Price(['sku' => 'B', 'price' => 5]);
        $price->saveValidFrom('2026-01-01');
        $price->price = 7;
        $price->saveValidFrom('2026-02-01');

        $old = Price::asOfValidTime('2026-01-15')->find($price->getKey());
        $price->price = $old->price;
        $price->saveValidFrom(Carbon::now());

        $this->assertSame(5, Price::find($price->getKey())->price);
        $this->assertSame([5, 7, 5], $price->versions()->pluck('price')->all());
    }

    public function test_month_end_snapshots(): void
    {
        foreach (['2026-01-10' => null, '2026-02-10' => '2026-03-05', '2026-03-20' => null] as $from => $to) {
            (new Salary(['employee_id' => $from, 'amount' => 1]))->saveValidFrom($from, $to);
        }

        $headcount = collect(range(1, 3))->mapWithKeys(fn (int $month) => [
            $month => Salary::asOfValidTime(Carbon::create(2026, $month)->endOfMonth())->count(),
        ])->all();

        $this->assertSame([1 => 1, 2 => 2, 3 => 2], $headcount);
    }
}
