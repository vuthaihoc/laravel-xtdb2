<?php

namespace LaravelXtdb\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use LaravelXtdb\Exceptions\UnsupportedFeatureException;
use LaravelXtdb\Query\Processor;
use LaravelXtdb\XtdbConnection;
use LogicException;
use PHPUnit\Framework\TestCase;

class GrammarTest extends TestCase
{
    private function connection(): XtdbConnection
    {
        return new XtdbConnection(fn () => throw new LogicException('No database in unit tests.'), 'xtdb', '', ['driver' => 'xtdb', 'name' => 'xtdb']);
    }

    public function test_case_insensitive_like_compares_lower_values(): void
    {
        $this->assertSame(
            'select * from "users" where lower("email") like lower(?)',
            $this->connection()->table('users')->whereLike('email', 'A%')->toSql()
        );
        $this->assertSame(
            'select * from "users" where lower("name") not like lower(?)',
            $this->connection()->table('users')->where('name', 'not ilike', 'a%')->toSql()
        );
    }

    public function test_json_paths_are_nested_fields(): void
    {
        $query = $this->connection()->table('users')->where('settings->theme', 'dark')->whereJsonContains('settings->tags', 'a')->whereJsonLength('settings->tags', '>', 1);

        $this->assertSame(
            'select * from "users" where ("settings")."theme" = ? and (? = any(("settings")."tags")) is true and cardinality(("settings")."tags") > ?',
            $query->toSql()
        );
    }

    public function test_locks_are_dropped(): void
    {
        $this->assertSame('select * from "users" where "_id" = ?', $this->connection()->table('users')->where('_id', 1)->lockForUpdate()->toSql());
    }

    public function test_upsert_patches_by_id(): void
    {
        $sql = $this->connection()->getQueryGrammar()->compileUpsert(
            $this->connection()->table('users'),
            [['_id' => 'a', 'name' => 'A'], ['_id' => 'b', 'name' => 'B']],
            ['_id'],
            ['name'],
        );

        $this->assertSame('patch into "users" records {"_id": ?, "name": ?}, {"_id": ?, "name": ?}', $sql);
    }

    public function test_upsert_needs_the_id_as_unique_key(): void
    {
        $this->expectException(UnsupportedFeatureException::class);

        $this->connection()->table('users')->upsert([['email' => 'a@x.io', 'name' => 'A']], ['email'], ['name']);
    }

    public function test_update_with_limit_selects_the_ids(): void
    {
        $query = $this->connection()->table('users')->where('active', false)->limit(10);

        $this->assertSame(
            'update "users" set "active" = ? where "_id" in (select "users"."_id" from "users" where "active" = ? limit 10)',
            $this->connection()->getQueryGrammar()->compileUpdate($query, ['active' => true])
        );
    }

    public function test_truncate_erases(): void
    {
        $this->assertSame(['erase from "users" where true' => []], $this->connection()->getQueryGrammar()->compileTruncate($this->connection()->table('users')));
    }

    public function test_raw_sql_shows_xtdb_literals(): void
    {
        $this->assertSame(
            "select * from \"users\" where \"name\" = E'O\\'Brien' and \"active\" = TRUE",
            $this->connection()->table('users')->where('name', "O'Brien")->where('active', true)->toRawSql()
        );
    }

    public function test_schema_declares_columns_without_types(): void
    {
        $connection = $this->connection();
        $connection->useDefaultSchemaGrammar();
        $blueprint = new Blueprint($connection, 'posts', function (Blueprint $table) {
            $table->id();
            $table->string('title')->unique();
            $table->foreignId('user_id')->constrained();
            $table->timestamps();
        });
        $blueprint->create();

        $this->assertSame(['create table "posts" ("_id", "id", "title", "user_id", "created_at", "updated_at")'], $blueprint->toSql());
    }

    public function test_xtdb_types(): void
    {
        $this->assertSame(
            ['text', 'bigint', 'bigint', 'timestamp', 'timestamptz', 'boolean', 'double precision', 'document', 'unknown'],
            array_map(Processor::typeName(...), [':utf8', ':i64', '[:? :i64]', '[:timestamp-local :micro]', ':instant', '[:? :bool]', ':f64', '[:struct {a :utf8}]', ':null'])
        );
    }
}
