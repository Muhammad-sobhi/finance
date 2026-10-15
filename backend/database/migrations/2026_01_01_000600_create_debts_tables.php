<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('person');
            $table->string('phone', 32)->nullable();
            $table->string('direction', 12); // owed_to_me | i_owe
            $table->decimal('amount', 15, 2);
            $table->char('currency', 3);
            $table->date('date');
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable(); // encrypted
            $table->string('status', 8)->default('open'); // open | settled
            // Optional: the account the money originally left / arrived in.
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('remind_days')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('debt_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2); // in the debt's currency
            $table->date('date');
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_payments');
        Schema::dropIfExists('debts');
    }
};
