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
        Schema::create('redmine_issues', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('project_id')->index();
            $table->string('project_identifier')->index();
            $table->string('project_name');
            $table->unsignedSmallInteger('tracker_id');
            $table->string('tracker');
            $table->unsignedSmallInteger('status_id');
            $table->string('status')->index();
            $table->boolean('is_closed')->index();
            $table->unsignedSmallInteger('priority_id');
            $table->string('priority');
            $table->unsignedInteger('assignee_id')->nullable();
            $table->string('assignee_name')->nullable()->index();
            $table->unsignedInteger('author_id')->nullable();
            $table->string('author_name')->nullable();
            $table->string('subject', 512);
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedTinyInteger('done_ratio')->default(0);
            $table->decimal('estimated_hours', 8, 2)->nullable();
            $table->timestamp('created_on');
            $table->timestamp('updated_on')->index();
            $table->timestamp('closed_on')->nullable()->index();
            $table->json('raw');
            $table->timestamp('synced_at');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('redmine_issues');
    }
};
