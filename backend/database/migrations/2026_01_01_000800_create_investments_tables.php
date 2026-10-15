<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 16);
            $table->string('platform')->nullable();
            $table->decimal('invested_amount', 15, 2);
            $table->decimal('current_value', 15, 2);
            $table->char('currency', 3);
            $table->decimal('quantity', 18, 6)->nullable();
            $table->string('unit', 24)->nullable();
            $table->date('start_date')->nullable();
            $table->date('maturity_date')->nullable();
            $table->decimal('annual_return', 7, 3)->nullable(); // percent
            $table->string('payout_cycle', 10)->nullable(); // monthly | quarterly | yearly | end
            $table->unsignedSmallInteger('remind_days')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('investment_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('value', 15, 2);
            $table->timestamps();
            $table->unique(['investment_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_values');
        Schema::dropIfExists('investments');
    }
};
