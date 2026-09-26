<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Postgres jsonb reorders keys; stats are shown in the order they were written, so keep them as json.
     */
    public function up(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->json('stats')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->jsonb('stats')->nullable()->change();
        });
    }
};
