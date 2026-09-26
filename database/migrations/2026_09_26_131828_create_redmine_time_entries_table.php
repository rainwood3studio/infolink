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
        Schema::create('redmine_time_entries', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('issue_id')->nullable()->index();
            $table->string('project_identifier')->index();
            $table->unsignedInteger('user_id');
            $table->string('user_name')->index();
            $table->string('activity');
            $table->decimal('hours', 8, 2);
            $table->date('spent_on')->index();
            $table->text('comments')->nullable();
            $table->timestamp('updated_on');
            $table->json('raw');
            $table->timestamp('synced_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('redmine_time_entries');
    }
};
