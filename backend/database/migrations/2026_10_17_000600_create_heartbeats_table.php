<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Last sign of life of each background part (scheduler, queue, backup) for the health checks.
        Schema::create('heartbeats', function (Blueprint $table) {
            $table->string('name', 32)->primary();
            $table->timestamp('ok_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('count')->default(0);    // e.g. failed jobs already alerted
            $table->timestamp('alerted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heartbeats');
    }
};
