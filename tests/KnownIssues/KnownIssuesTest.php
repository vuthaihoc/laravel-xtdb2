<?php

namespace LaravelXtdb\Tests\KnownIssues;

use Throwable;

/**
 * Last verified against XTDB 2.2.0-beta3 and the 2026-09-28 nightly: 24 failures
 * (open issues) and 2 passes (fixed since 2.1.0, kept as regression checks).
 */
class KnownIssuesTest extends TestCase
{
    // ---------------------------------------------------------------- parameters and literals

    /**
     * "Missing types for args - client must specify types for all non-null params in DML statements":
     * PDO (and most PostgreSQL clients) send parameters untyped.
     * Driver workaround: bindings are inlined as typed literals (Query\Literal).
     */
    public function test_untyped_parameters_in_dml(): void
    {
        $statement = self::connect(emulatePrepares: false)->prepare('insert into '.$this->table('params').' (_id, name) values ($1, $2)');

        try {
            $statement->execute([1, 'a']);
        } catch (Throwable $e) {
            $this->fail($e->getMessage());
        }

        $this->assertSame([['name' => 'a']], $this->runSql('select name from '.$this->table('params')));
    }

    /**
     * Inside E'...', a doubled quote stays two quotes ("it''s") instead of one.
     * Driver workaround: quotes are escaped as \' in E'' strings.
     */
    public function test_doubled_quote_inside_an_escape_string(): void
    {
        $this->assertSame([['v' => "it's"]], $this->runSql("select E'it''s' as v"));
    }

    // ---------------------------------------------------------------- queries

    /**
     * LIKE ... ESCAPE matches nothing, so % and _ cannot be matched literally.
     * Driver workaround: whereStartsWith()/whereContains()/suggest() use position().
     */
    public function test_like_escape(): void
    {
        $rows = $this->runSql(
            'insert into '.$this->table('like')." records {_id: 1, w: '100%_sure'}, {_id: 2, w: '100xysure'}",
            'select _id from '.$this->table('like')." where w like '%!%!_%' escape '!'",
        );

        $this->assertSame([['_id' => 1]], $rows);
    }

    /**
     * "x = any(list)" as the left operand of AND/OR evaluates to false, whatever the other operand;
     * parenthesised it is right. Two chained ones fail with "Unknown symbol: '_sq_N'". Also in 2.1.0.
     * Driver workaround: whereJsonContains() compiles to "(? = any(list)) is true".
     */
    public function test_any_followed_by_another_condition(): void
    {
        $rows = $this->runSql(
            'insert into '.$this->table('any')." records {_id: 1, tags: ['a', 'b']}",
            'select _id from '.$this->table('any')." where 'a' = any(tags) and true",
        );

        $this->assertSame([['_id' => 1]], $rows);
    }

    public function test_two_quantified_comparisons(): void
    {
        $rows = $this->runSql(
            'insert into '.$this->table('any2')." records {_id: 1, tags: ['a', 'b']}",
            'select _id from '.$this->table('any2')." where 'a' = any(tags) and 'b' = any(tags)",
        );

        $this->assertSame([['_id' => 1]], $rows);
    }

    /**
     * Internal error: 'Cannot invoke "clojure.lang.IFn.invoke(Object)"'.
     * Driver workaround: not used by the driver.
     */
    public function test_any_over_a_coalesced_list(): void
    {
        $rows = $this->runSql(
            'insert into '.$this->table('anyc')." records {_id: 1, tags: ['a']}, {_id: 2, tags: NULL}",
            'select _id from '.$this->table('anyc')." where 'a' = any(coalesce(tags, []))",
        );

        $this->assertSame([['_id' => 1]], $rows);
    }

    /**
     * "Incomparable types in min/max aggregate: #xt/type :nothing" (and "Cannot compute SUM over type
     * Nothing") for a declared column without values; SQL gives NULL.
     * Driver workaround: Query\Builder::aggregate() returns null.
     */
    public function test_max_of_a_column_without_values(): void
    {
        $rows = $this->runSql(
            'create table '.$this->table('agg').' (_id, batch)',
            'select max(batch) as m, sum(batch) as s from '.$this->table('agg'),
        );

        $this->assertSame([['m' => null, 's' => null]], $rows);
    }

    /**
     * A column remembers the types it held after its rows are erased, and ORDER BY then fails:
     * "compare not applicable to types i64 and utf8".
     * Driver workaround: none; the README asks for one type per column.
     */
    public function test_column_types_after_erase(): void
    {
        $rows = $this->runSql(
            'insert into '.$this->table('types').' (_id) values (1)',
            'erase from '.$this->table('types').' where true',
            'insert into '.$this->table('types')." (_id) values ('a'), ('b')",
            'select _id from '.$this->table('types').' order by _id',
        );

        $this->assertSame([['_id' => 'a'], ['_id' => 'b']], $rows);
    }

    public function test_ilike(): void
    {
        $this->assertSame([['v' => true]], $this->runSql("select 'ABC' ilike 'a%' as v"));
    }

    /**
     * Driver workaround: inRandomOrder() throws UnsupportedFeatureException.
     */
    public function test_random(): void
    {
        $this->assertCount(1, $this->runSql('select random() as r'));
    }

    /**
     * Driver workaround: none (Laravel does not generate window functions).
     */
    public function test_window_function(): void
    {
        $rows = $this->runSql(
            'insert into '.$this->table('win').' records {_id: 1}, {_id: 2}',
            'select _id, count(*) over () as total from '.$this->table('win').' order by _id',
        );

        $this->assertSame([['_id' => 1, 'total' => 2], ['_id' => 2, 'total' => 2]], $rows);
    }

    /**
     * Driver workaround: lock clauses are dropped.
     */
    public function test_select_for_update(): void
    {
        $this->runSql('insert into '.$this->table('lock').' records {_id: 1}');

        $this->assertCount(1, $this->runSql('select _id from '.$this->table('lock').' where _id = 1 for update'));
    }

    // ---------------------------------------------------------------- writes

    /**
     * update/delete report 0 affected rows.
     * Driver workaround: none; update() and delete() return 0.
     */
    public function test_affected_rows(): void
    {
        $pdo = self::connect();
        $pdo->exec('insert into '.$this->table('rows').' records {_id: 1, v: 1}, {_id: 2, v: 1}');

        $this->assertSame(2, $pdo->exec('update '.$this->table('rows').' set v = 2 where v = 1'));
    }

    /**
     * RETURNING is accepted and ignored.
     * Driver workaround: keys are generated by the client.
     */
    public function test_insert_returning(): void
    {
        $this->assertSame([['_id' => 1]], $this->runSql('insert into '.$this->table('ret').' (_id, v) values (1, 2) returning _id'));
    }

    /**
     * Driver workaround: upsert() compiles to PATCH ... RECORDS (matched by _id).
     */
    public function test_on_conflict(): void
    {
        $rows = $this->runSql(
            'insert into '.$this->table('upsert').' (_id, v) values (1, 1)',
            'insert into '.$this->table('upsert').' (_id, v) values (1, 2) on conflict (_id) do update set v = excluded.v',
            'select v from '.$this->table('upsert'),
        );

        $this->assertSame([['v' => 2]], $rows);
    }

    /**
     * "Subqueries are not allowed in this context".
     * Driver workaround: none.
     */
    public function test_subquery_in_update_set(): void
    {
        $rows = $this->runSql(
            'insert into '.$this->table('sub').' records {_id: 1, n: 0}, {_id: 2, n: 0}',
            'update '.$this->table('sub').' set n = (select count(*) from '.$this->table('sub').') where _id = 1',
            'select n from '.$this->table('sub').' where _id = 1',
        );

        $this->assertSame([['n' => 2]], $rows);
    }

    // ---------------------------------------------------------------- transactions

    /**
     * "DML is not allowed in a READ ONLY transaction": the first statement decides the transaction mode.
     * Driver workaround: XtdbTransactionException explains how to split reads and writes.
     */
    public function test_write_after_a_query_in_a_transaction(): void
    {
        $rows = $this->runSql(
            'insert into '.$this->table('tx').' records {_id: 1}',
            'begin',
            'select count(*) as n from '.$this->table('tx'),
            'insert into '.$this->table('tx').' records {_id: 2}',
            'commit',
            'select count(*) as n from '.$this->table('tx'),
        );

        $this->assertSame([['n' => 2]], $rows);
    }

    /**
     * "Queries are unsupported in a DML transaction".
     * Driver workaround: XtdbTransactionException.
     */
    public function test_query_after_a_write_in_a_transaction(): void
    {
        $rows = $this->runSql(
            'create table '.$this->table('txw').' (_id)',
            'begin',
            'insert into '.$this->table('txw').' records {_id: 1}',
            'select count(*) as n from '.$this->table('txw'),
        );

        $this->assertSame([['n' => 1]], $rows);
    }

    /**
     * Driver workaround: nested transactions are part of the outer one (supportsSavepoints() is false).
     */
    public function test_savepoints(): void
    {
        $this->runSql('begin', 'insert into '.$this->table('sp').' records {_id: 1}', 'savepoint trans2', 'rollback to savepoint trans2', 'commit');

        $this->assertSame([['n' => 1]], $this->runSql('select count(*) as n from '.$this->table('sp')));
    }

    // ---------------------------------------------------------------- schema and session

    /**
     * Column types are rejected ("mismatched input 'bigint'").
     * Driver workaround: the schema grammar declares columns without types.
     */
    public function test_create_table_with_column_types(): void
    {
        $this->runSql('create table '.$this->table('typed').' (_id bigint, name varchar(255))');

        $this->assertSame([], $this->runSql('select * from '.$this->table('typed')));
    }

    /**
     * Driver workaround: dropping a column does nothing; renaming throws.
     */
    public function test_alter_table(): void
    {
        $this->runSql('create table '.$this->table('alter').' (_id, name)', 'alter table '.$this->table('alter').' rename column name to title');

        $this->assertSame([], $this->runSql('select title from '.$this->table('alter')));
    }

    /**
     * Driver workaround: drop and dropIfExists erase the rows.
     */
    public function test_drop_table(): void
    {
        $this->runSql('create table '.$this->table('drop').' (_id)', 'drop table '.$this->table('drop'));

        $this->assertSame([], $this->runSql("select table_name from information_schema.tables where table_name = '{$this->prefix}drop'"));
    }

    /**
     * "Table not found": ERASE of a table that was never declared or written.
     * Driver workaround: dropIfExists() checks hasTable() first.
     */
    public function test_erase_an_unknown_table(): void
    {
        $this->assertSame([], $this->runSql('erase from '.$this->table('never').' where true'));
    }

    /**
     * Only an unquoted value is accepted.
     * Driver workaround: the connector does not set the search path.
     */
    public function test_set_search_path_to_a_quoted_identifier(): void
    {
        $this->assertSame([], $this->runSql('set search_path to "public"'));
    }

    // ---------------------------------------------------------------- fixed in 2.2 (regression checks)

    /**
     * 2.1.0: a character beyond the Basic Multilingual Plane in INSERT ... VALUES failed to parse.
     * The driver still escapes them as \UXXXXXXXX.
     */
    public function test_emoji_in_insert_values(): void
    {
        $rows = $this->runSql(
            'insert into '.$this->table('emoji')." (_id, s) values (1, 'Xin chào 🚀')",
            'select s from '.$this->table('emoji'),
        );

        $this->assertSame([['s' => 'Xin chào 🚀']], $rows);
    }

    /**
     * 2.1.0: no ->> operator.
     */
    public function test_json_arrow_operator(): void
    {
        $rows = $this->runSql(
            'insert into '.$this->table('arrow')." records {_id: 1, meta: {source: 'yt'}}",
            'select meta->>\'source\' as s from '.$this->table('arrow'),
        );

        $this->assertSame([['s' => 'yt']], $rows);
    }
}
