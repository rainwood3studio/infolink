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
        Schema::create('metric_values', function (Blueprint $table) {
            $table->id();
            $table->string('metric_key');
            $table->foreign('metric_key')->references('key')->on('metric_definitions')->cascadeOnUpdate()->cascadeOnDelete();
            $table->date('period_start');
            $table->string('dimension')->default('');
            $table->decimal('value', 18, 4);
            $table->sourceColumns();
            $table->timestamps();

            $table->unique(['metric_key', 'period_start', 'dimension']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metric_values');
    }
};
