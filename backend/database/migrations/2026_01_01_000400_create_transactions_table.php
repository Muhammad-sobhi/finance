<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 8); // expense | income | transfer
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('to_account_id')->nullable()->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            // `amount` is in the source account's currency; `to_amount` in the target account's (transfers).
            $table->decimal('amount', 15, 2);
            $table->char('currency', 3);
            $table->decimal('to_amount', 15, 2)->nullable();
            $table->date('date');
            $table->string('note', 500)->nullable();
            // Links a transaction to the bill/debt payment etc. that created it (later modules).
            $table->nullableMorphs('source');
            $table->timestamps();
            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
