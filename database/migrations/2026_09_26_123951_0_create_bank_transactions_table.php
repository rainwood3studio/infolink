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
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->date('txn_date')->index();
            $table->unsignedInteger('sequence')->default(0);
            $table->string('summary');
            $table->string('counterparty')->nullable();
            $table->bigInteger('withdrawal')->default(0);
            $table->bigInteger('deposit')->default(0);
            $table->bigInteger('balance');
            $table->string('category', 16)->default('other')->index();
            $table->boolean('is_one_off')->default(false);
            $table->foreignId('receivable_id')->nullable()->constrained()->nullOnDelete();
            $table->sourceColumns();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
