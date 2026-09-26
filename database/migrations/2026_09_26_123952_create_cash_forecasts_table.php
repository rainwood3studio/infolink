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
        Schema::create('cash_forecasts', function (Blueprint $table) {
            $table->id();
            $table->date('as_of')->index();
            $table->bigInteger('opening_balance');
            $table->jsonb('rows');
            $table->jsonb('assumptions')->nullable();
            $table->bigInteger('min_balance');
            $table->string('min_balance_month', 7);
            $table->bigInteger('year_end_balance');
            $table->sourceColumns();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_forecasts');
    }
};
