<?php

namespace LaravelXtdb\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaravelXtdb\Exceptions\UnsupportedFeatureException;

class SchemaTest extends TestCase
{
    protected array $tables = ['xt_schema', 'xt_migrated', 'migrations'];

    public function test_create_declares_the_table_and_its_columns(): void
    {
        Schema::create('xt_schema', function (Blueprint $table) {
            $table->ulid('_id')->primary();
            $table->string('email')->unique();
            $table->foreignUlid('team_id')->index();
            $table->softDeletes();
        });

        // Declared columns can be queried before any row exists.
        $this->assertSame(0, DB::table('xt_schema')->whereNull('deleted_at')->where('email', 'x')->count());
        $this->assertTrue(Schema::hasTable('xt_schema'));
        $this->assertFalse(Schema::hasTable('xt_missing'));
        $this->assertTrue(Schema::hasColumns('xt_schema', ['_id', 'email', 'team_id', 'deleted_at']));
        $this->assertSame([], Schema::getIndexes('xt_schema'));
        $this->assertSame([], Schema::getForeignKeys('xt_schema'));

        Schema::table('xt_schema', fn (Blueprint $table) => $table->string('nickname')->nullable());
        $this->assertTrue(Schema::hasColumn('xt_schema', 'nickname'));

        DB::table('xt_schema')->insert(['email' => 'a@x.io', 'team_id' => 't1']);
        $types = array_column(Schema::getColumns('xt_schema'), 'type_name', 'name');
        $this->assertSame('text', $types['email']);
        $this->assertContains('xt_schema', Schema::getTableListing(schemaQualified: false));
    }

    public function test_drop_erases_the_rows(): void
    {
        Schema::create('xt_schema', fn (Blueprint $table) => $table->string('name'));
        DB::table('xt_schema')->insert(['name' => 'x']);

        Schema::dropIfExists('xt_schema');

        $this->assertSame(0, DB::table('xt_schema')->count());
    }

    public function test_renaming_is_not_supported(): void
    {
        $this->expectException(UnsupportedFeatureException::class);

        Schema::table('xt_schema', fn (Blueprint $table) => $table->renameColumn('name', 'title'));
    }

    public function test_migrations_run_and_roll_back(): void
    {
        $path = __DIR__.'/migrations';

        $this->artisan('migrate', ['--path' => $path, '--realpath' => true])->assertSuccessful();
        $this->assertSame(['2026_01_01_000000_create_xt_migrated_table'], DB::table('migrations')->pluck('migration')->all());
        $this->assertSame(0, DB::table('xt_migrated')->whereNull('deleted_at')->count());

        $this->artisan('migrate:rollback', ['--path' => $path, '--realpath' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('migrations')->count());

        $this->artisan('migrate:fresh', ['--path' => $path, '--realpath' => true])->assertSuccessful();
        $this->assertSame(1, DB::table('migrations')->count());
    }
}
