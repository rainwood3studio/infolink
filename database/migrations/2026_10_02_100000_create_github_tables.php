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
        Schema::create('developers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('redmine_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('github_identities', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->foreignId('developer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('login')->nullable();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('github_repos', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('owner');
            $table->string('name');
            $table->string('full_name')->unique();
            $table->string('default_branch')->nullable();
            $table->boolean('is_private')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('pushed_at')->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('github_branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('github_repo_id');
            $table->foreign('github_repo_id')->references('id')->on('github_repos')->cascadeOnDelete();
            $table->string('name');
            $table->string('head_sha', 40);
            $table->timestamp('head_committed_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['github_repo_id', 'name']);
        });

        Schema::create('github_commits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('github_repo_id');
            $table->foreign('github_repo_id')->references('id')->on('github_repos')->cascadeOnDelete();
            $table->string('sha', 40);
            $table->foreignId('github_identity_id')->nullable()->constrained()->nullOnDelete();
            $table->string('branch')->nullable();
            $table->timestamp('authored_at')->index();
            $table->timestamp('committed_at')->nullable();
            $table->string('subject', 500);
            $table->text('message');
            $table->string('type', 16)->nullable();
            $table->string('scope', 64)->nullable();
            $table->json('redmine_issue_ids');
            $table->boolean('is_merge')->default(false);
            $table->boolean('is_ai_assisted')->default(false);
            $table->unsignedInteger('additions')->default(0);
            $table->unsignedInteger('deletions')->default(0);
            $table->unsignedInteger('changed_files')->nullable();
            $table->unsignedInteger('effective_additions')->nullable();
            $table->unsignedInteger('effective_deletions')->nullable();
            $table->timestamps();

            $table->unique(['github_repo_id', 'sha']);
            $table->index(['github_identity_id', 'authored_at']);
        });

        Schema::create('github_pull_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('github_repo_id');
            $table->foreign('github_repo_id')->references('id')->on('github_repos')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->foreignId('github_identity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('merged_by_identity_id')->nullable()->constrained('github_identities')->nullOnDelete();
            $table->string('title', 500);
            $table->string('state', 16);
            $table->boolean('is_draft')->default(false);
            $table->string('head_ref')->nullable();
            $table->string('base_ref')->nullable();
            $table->timestamp('opened_at')->index();
            $table->timestamp('merged_at')->nullable()->index();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('additions')->default(0);
            $table->unsignedInteger('deletions')->default(0);
            $table->json('redmine_issue_ids');
            $table->timestamps();

            $table->unique(['github_repo_id', 'number']);
        });

        Schema::create('github_reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('github_pull_request_id');
            $table->foreign('github_pull_request_id')->references('id')->on('github_pull_requests')->cascadeOnDelete();
            $table->foreignId('github_identity_id')->nullable()->constrained()->nullOnDelete();
            $table->string('state', 32);
            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('github_reviews');
        Schema::dropIfExists('github_pull_requests');
        Schema::dropIfExists('github_commits');
        Schema::dropIfExists('github_branches');
        Schema::dropIfExists('github_repos');
        Schema::dropIfExists('github_identities');
        Schema::dropIfExists('developers');
    }
};
