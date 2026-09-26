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
        Schema::create('cost_baselines', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from')->unique();
            $table->bigInteger('monthly_cost');
            $table->json('breakdown')->nullable();
            $table->sourceColumns();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cost_baselines');
    }
};
