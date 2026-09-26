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
        Schema::create('receivables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item');
            $table->bigInteger('amount_untaxed');
            $table->decimal('tax_rate', 5, 4)->default(0.05);
            $table->bigInteger('amount_taxed');
            $table->date('expected_on')->index();
            $table->string('confidence', 8)->default('high');
            $table->string('status', 16)->default('planned')->index();
            $table->date('invoiced_on')->nullable();
            $table->date('received_on')->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->sourceColumns();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receivables');
    }
};
