<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xt_migrated', function (Blueprint $table) {
            $table->ulid('_id')->primary();
            $table->string('name')->index();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xt_migrated');
    }
};
