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
        Schema::table('github_repos', function (Blueprint $table) {
            $table->foreignId('deal_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
            $table->json('branch_projects')->nullable()->after('deal_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('github_repos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deal_id');
            $table->dropColumn('branch_projects');
        });
    }
};
