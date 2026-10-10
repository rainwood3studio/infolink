<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `counterparties` are the bank statement names that belong to the customer (a related company paying on its
     * behalf, a name longer than ours); the maintenance columns describe a recurring maintenance contract so the
     * 客戶收款 page can check each period against the deposits.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->json('counterparties')->nullable()->after('redmine_project_ids');
            $table->bigInteger('maintenance_fee_untaxed')->nullable()->after('status');
            $table->string('maintenance_cycle', 8)->nullable()->after('maintenance_fee_untaxed');
            $table->date('maintenance_since')->nullable()->after('maintenance_cycle');
            $table->date('maintenance_until')->nullable()->after('maintenance_since');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['counterparties', 'maintenance_fee_untaxed', 'maintenance_cycle', 'maintenance_since', 'maintenance_until']);
        });
    }
};
