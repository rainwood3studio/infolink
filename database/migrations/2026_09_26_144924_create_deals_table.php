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
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('prospect_name')->nullable();
            $table->string('title');
            $table->string('stage', 16)->default('lead')->index();
            $table->bigInteger('amount_untaxed')->nullable();
            $table->unsignedTinyInteger('probability')->default(10);
            $table->bigInteger('recurring_monthly')->nullable();
            $table->date('expected_close_on')->nullable();
            $table->string('next_action')->nullable();
            $table->date('next_action_on')->nullable()->index();
            $table->timestamp('closed_at')->nullable();
            $table->sourceColumns();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
