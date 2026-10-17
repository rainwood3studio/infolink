<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `invoice_number` is the 統一發票 number (e.g. `AB-12345678`) written when a receivable is invoiced, so the
     * 請款進度 page can tell an invoice that was actually issued from one that is merely planned.
     */
    public function up(): void
    {
        Schema::table('receivables', function (Blueprint $table) {
            $table->string('invoice_number', 32)->nullable()->after('invoiced_on');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('receivables', function (Blueprint $table) {
            $table->dropColumn('invoice_number');
        });
    }
};
