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
        Schema::create('action_items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('detail')->nullable();
            $table->string('priority', 4)->default('p2');
            $table->string('status', 16)->default('todo')->index();
            $table->date('due_on')->nullable()->index();
            $table->string('owner')->nullable();
            $table->nullableMorphs('related');
            $table->timestamp('completed_at')->nullable();
            $table->sourceColumns();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('action_items');
    }
};
