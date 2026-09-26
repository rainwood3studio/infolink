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
        Schema::create('redmine_status_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date');
            $table->string('project_identifier');
            $table->string('status');
            $table->string('assignee_name')->default('');
            $table->unsignedInteger('count');
            $table->unsignedInteger('stalled_30d')->default(0);
            $table->unsignedInteger('stalled_90d')->default(0);
            $table->unsignedInteger('overdue')->default(0);
            $table->boolean('is_reconstructed')->default(false);
            $table->timestamps();

            $table->unique(['snapshot_date', 'project_identifier', 'status', 'assignee_name'], 'redmine_snapshots_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('redmine_status_snapshots');
    }
};
