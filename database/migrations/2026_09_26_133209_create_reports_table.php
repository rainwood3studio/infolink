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
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32)->index();
            $table->date('period_start')->index();
            $table->date('period_end')->nullable();
            $table->string('title');
            $table->text('body');
            $table->json('metrics_snapshot')->nullable();
            $table->boolean('notify')->default(false);
            $table->timestamp('notified_at')->nullable();
            $table->sourceColumns();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
