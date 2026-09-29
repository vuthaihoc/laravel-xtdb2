<?php

namespace LaravelXtdb\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LaravelXtdb\Query\Document;
use LaravelXtdb\XtdbConnection;

class ConnectionTest extends TestCase
{
    protected array $tables = ['xt_values'];

    public function test_connects_to_xtdb(): void
    {
        $this->assertInstanceOf(XtdbConnection::class, DB::connection());
        $this->assertSame('XTDB', DB::connection()->getDriverTitle());
        $this->assertStringContainsString('XTDB', (string) DB::scalar('select version()'));
    }

    public function test_values_keep_their_types(): void
    {
        DB::table('xt_values')->insert([
            '_id' => 'v1',
            'text' => "it's a \\ ? 🚀 chào",
            'yes' => true,
            'no' => false,
            'int' => 42,
            'float' => 9.5,
            'none' => null,
            'at' => CarbonImmutable::parse('2026-01-02 03:04:05'),
            'at_string' => '2026-01-02 03:04:05',
            'doc' => new Document(['theme' => 'dark', 'tags' => ['a', 'b'], 'n' => ['k' => 1]]),
        ]);

        $row = DB::table('xt_values')->where('_id', 'v1')->first();

        $this->assertSame("it's a \\ ? 🚀 chào", $row->text);
        $this->assertTrue($row->yes);
        $this->assertFalse($row->no);
        $this->assertSame(42, $row->int);
        $this->assertEquals(9.5, $row->float);
        $this->assertNull($row->none);
        $this->assertStringStartsWith('2026-01-02 03:04:05', (string) $row->at);
        $this->assertSame(['theme' => 'dark', 'tags' => ['a', 'b'], 'n' => ['k' => 1]], json_decode((string) $row->doc, true));

        // Typed values compare as their type.
        $this->assertSame(1, DB::table('xt_values')->where('yes', true)->where('at', '>', '2026-01-01 00:00:00')->where('at_string', '<', CarbonImmutable::parse('2027-01-01'))->count());
        $this->assertSame(1, DB::table('xt_values')->where('doc->n->k', 1)->whereJsonContains('doc->tags', 'b')->whereJsonLength('doc->tags', 2)->count());
    }

    public function test_insert_generates_ids(): void
    {
        DB::table('xt_values')->insert([['text' => 'a'], ['text' => 'b']]);
        $id = DB::table('xt_values')->insertGetId(['text' => 'c']);

        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', (string) $id);
        $this->assertSame(3, DB::table('xt_values')->count());
        $this->assertSame('c', DB::table('xt_values')->where('_id', $id)->value('text'));
    }

    public function test_upsert_patches_rows(): void
    {
        DB::table('xt_values')->insert(['_id' => 'a', 'text' => 'A', 'int' => 1]);

        DB::table('xt_values')->upsert([['_id' => 'a', 'text' => 'A2'], ['_id' => 'b', 'text' => 'B']], ['_id'], ['text']);

        $this->assertSame(['a' => 'A2', 'b' => 'B'], DB::table('xt_values')->orderBy('_id')->pluck('text', '_id')->all());
        $this->assertSame(1, DB::table('xt_values')->where('_id', 'a')->value('int'));
    }

    public function test_update_and_delete_with_limit(): void
    {
        DB::table('xt_values')->insert([['_id' => 'r1', 'int' => 1], ['_id' => 'r2', 'int' => 2], ['_id' => 'r3', 'int' => 3]]);

        DB::table('xt_values')->orderBy('_id')->limit(2)->update(['text' => 'first two']);
        DB::table('xt_values')->orderByDesc('_id')->limit(1)->delete();

        $this->assertSame(['r1' => 'first two', 'r2' => 'first two'], DB::table('xt_values')->orderBy('_id')->pluck('text', '_id')->all());
    }

    public function test_aggregates_of_a_column_without_values_are_null(): void
    {
        DB::statement('create table "xt_values" ("_id", "never")');

        $this->assertNull(DB::table('xt_values')->max('never'));
        $this->assertNull(DB::table('xt_values')->min('never'));
        $this->assertSame(0, DB::table('xt_values')->sum('never'));
        $this->assertSame(0, DB::table('xt_values')->count('never'));
    }

    public function test_truncate_erases_the_rows(): void
    {
        DB::table('xt_values')->insert(['text' => 'x']);
        DB::table('xt_values')->truncate();

        $this->assertSame(0, DB::table('xt_values')->count());
    }
}
