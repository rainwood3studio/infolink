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
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('instance_id', 32)->unique();
            $table->string('account', 64);
            $table->string('region', 32);
            $table->string('name')->nullable();
            $table->string('computer_name')->nullable();
            $table->string('platform', 16)->nullable();
            $table->string('platform_name')->nullable();
            $table->string('instance_type', 32)->nullable();
            $table->string('ping_status', 32)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_collected_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('server_disk_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->timestamp('collected_at')->index();
            $table->string('mount');
            $table->string('filesystem')->nullable();
            $table->string('fs_type', 32)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedBigInteger('used_bytes');
            $table->unsignedBigInteger('available_bytes');
            $table->decimal('used_percent', 5, 2);
            $table->timestamps();

            $table->index(['server_id', 'mount', 'collected_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_disk_samples');
        Schema::dropIfExists('servers');
    }
};
