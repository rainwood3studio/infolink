<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Dated obligations with their own rhythm (營業稅 every other month, 勞健保 at month end, 營所稅 in May, bonuses,
     * insurance, rent). `amount` is a positive outflow. `due_day` is clamped to the month's last day; `due_months`
     * lists the month numbers for non-monthly cadences (null = every month). `in_baseline` marks amounts that the
     * cost baseline already contains: shown on the calendar, not deducted again by the forecast.
     */
    public function up(): void
    {
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->bigInteger('amount');
            $table->string('cadence', 12)->default('monthly');
            $table->unsignedTinyInteger('due_day')->default(1);
            $table->json('due_months')->nullable();
            $table->string('category', 16)->default('other')->index();
            $table->boolean('in_baseline')->default(false);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->sourceColumns();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_expenses');
    }
};
