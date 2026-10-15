<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per owner per month; refreshed daily so it ends as the month-end value.
        Schema::create('net_worth_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('month'); // first day of the month
            $table->decimal('net_worth', 16, 2);
            $table->json('breakdown');
            $table->timestamps();
            $table->unique(['user_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('net_worth_snapshots');
    }
};
