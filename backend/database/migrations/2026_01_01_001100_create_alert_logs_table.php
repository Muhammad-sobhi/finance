<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per item + stage + due date: each alert fires exactly once, and a
        // rolled-forward due date (next cycle) alerts again.
        Schema::create('alert_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->unsignedBigInteger('item_id');
            $table->string('stage', 8); // before | due | overdue
            $table->date('due_date');
            $table->timestamp('sent_at');
            $table->unique(['user_id', 'kind', 'item_id', 'stage', 'due_date'], 'alert_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_logs');
    }
};
