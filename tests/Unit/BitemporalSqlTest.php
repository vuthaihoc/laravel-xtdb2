<?php

namespace LaravelXtdb\Tests\Unit;

use Illuminate\Database\Query\Expression;
use LaravelXtdb\Exceptions\UnsupportedFeatureException;
use LaravelXtdb\XtdbConnection;
use LogicException;
use PHPUnit\Framework\TestCase;

class BitemporalSqlTest extends TestCase
{
    private const JAN = "TIMESTAMP '2026-01-01T00:00:00.000000+00:00'";

    private const JUL = "TIMESTAMP '2026-07-01T00:00:00.000000+00:00'";

    private function connection(): XtdbConnection
    {
        return new XtdbConnection(fn () => throw new LogicException('No database in unit tests.'), 'xtdb', '', ['driver' => 'xtdb', 'name' => 'xtdb']);
    }

    public function test_reads_at_a_valid_time_and_a_system_time(): void
    {
        $this->assertSame(
            'select * from "prices" for system_time as of '.self::JUL.' for valid_time as of '.self::JAN.' as "p" where "p"."_id" = ?',
            $this->connection()->table('prices as p')->asOfValidTime('2026-01-01')->asOfSystemTime('2026-07-01')->where('p._id', 1)->toSql()
        );
        $this->assertSame(
            'select * from "prices" for valid_time from '.self::JAN.' to '.self::JUL,
            $this->connection()->table('prices')->validBetween('2026-01-01', '2026-07-01')->toSql()
        );
        $this->assertSame(
            'select * from "prices" for valid_time from '.self::JAN.' to NULL',
            $this->connection()->table('prices')->validBetween('2026-01-01')->toSql()
        );
        $this->assertSame(
            'select * from "prices" for all system_time for all valid_time',
            $this->connection()->table('prices')->forAllValidTime()->forAllSystemTime()->toSql()
        );
        $this->assertSame(
            'select * from "prices"',
            $this->connection()->table('prices')->forAllValidTime()->forAllSystemTime()->readCurrent()->toSql()
        );
    }

    public function test_history(): void
    {
        $this->assertSame(
            'select *, "_valid_from", "_valid_to" from "prices" for all valid_time where "_id" = ? order by "_valid_from" asc',
            $this->connection()->table('prices')->where('_id', 1)->history()->toSql()
        );
        $this->assertSame(
            'select "price", "_valid_from", "_valid_to" from "prices"',
            $this->connection()->table('prices')->select('price')->withValidTime()->toSql()
        );
    }

    public function test_writes_for_a_period_of_valid_time(): void
    {
        $connection = $this->connection();
        $grammar = $connection->getQueryGrammar();

        $insert = $connection->table('prices')->validFrom('2026-01-01', '2026-07-01');
        $this->assertSame(
            'insert into "prices" ("_id", "price", "_valid_from", "_valid_to") values (?, ?, '.self::JAN.', '.self::JUL.')',
            $grammar->compileInsert($insert, [['_id' => 1, 'price' => 10, '_valid_from' => new Expression(self::JAN), '_valid_to' => new Expression(self::JUL)]])
        );

        $this->assertSame(
            'update "prices" for portion of valid_time from '.self::JAN.' to NULL as "p" set "price" = ? where "p"."_id" = ?',
            $grammar->compileUpdate($connection->table('prices as p')->validFrom('2026-01-01')->where('p._id', 1), ['price' => 11])
        );
        $this->assertSame(
            'update "prices" for portion of valid_time from CURRENT_TIMESTAMP to '.self::JUL.' set "price" = ? where "_id" = ?',
            $grammar->compileUpdate($connection->table('prices')->validTo('2026-07-01')->where('_id', 1), ['price' => 11])
        );
        $this->assertSame(
            'update "prices" for all valid_time set "price" = ? where "_id" = ?',
            $grammar->compileUpdate($connection->table('prices')->forAllValidTime()->where('_id', 1), ['price' => 11])
        );
        $this->assertSame(
            'delete from "prices" for portion of valid_time from '.self::JAN.' to '.self::JUL.' where "_id" = ?',
            $grammar->compileDelete($connection->table('prices')->validFrom('2026-01-01', '2026-07-01')->where('_id', 1))
        );
        $this->assertSame(
            'update "prices" for portion of valid_time from '.self::JAN.' to NULL set "price" = ? where "_id" in (select "prices"."_id" from "prices" where "active" = ? limit 5)',
            $grammar->compileUpdate($connection->table('prices')->validFrom('2026-01-01')->where('active', true)->limit(5), ['price' => 1])
        );
    }

    public function test_erase(): void
    {
        $this->assertSame(
            'erase from "prices" where "_id" = ?',
            $this->connection()->getQueryGrammar()->compileErase($this->connection()->table('prices')->where('_id', 1))
        );
    }

    public function test_an_update_cannot_apply_as_of_a_valid_time(): void
    {
        $this->expectException(UnsupportedFeatureException::class);

        $this->connection()->getQueryGrammar()->compileUpdate($this->connection()->table('prices')->asOfValidTime('2026-01-01'), ['price' => 1]);
    }
}
