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
        Schema::create('redmine_status_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('issue_id')->index();
            $table->string('project_identifier');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('assignee_name')->nullable();
            $table->string('previous_assignee_name')->nullable();
            $table->timestamp('changed_at')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('redmine_status_changes');
    }
};
