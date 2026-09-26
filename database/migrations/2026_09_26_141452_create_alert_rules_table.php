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
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('metric_key')->nullable();
            $table->string('query_class')->nullable();
            $table->string('operator', 4)->nullable();
            $table->decimal('threshold', 18, 4)->nullable();
            $table->decimal('critical_threshold', 18, 4)->nullable();
            $table->string('severity', 16)->default('warning');
            $table->string('category', 16)->default('company');
            $table->string('title_template');
            $table->string('fingerprint_template');
            $table->json('params')->nullable();
            $table->json('notify_channels')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
