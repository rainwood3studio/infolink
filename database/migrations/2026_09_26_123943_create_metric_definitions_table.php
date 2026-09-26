<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('metric_definitions', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('name');
            $table->string('category', 16);
            $table->string('unit', 16);
            $table->string('period_type', 16);
            $table->string('better', 8)->default('none');
            $table->decimal('target', 18, 4)->nullable();
            $table->decimal('warn_threshold', 18, 4)->nullable();
            $table->decimal('critical_threshold', 18, 4)->nullable();
            $table->string('calculator')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metric_definitions');
    }
};
