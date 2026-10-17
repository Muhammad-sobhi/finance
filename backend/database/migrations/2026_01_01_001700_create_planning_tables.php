<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Monthly spending limit per expense category, in the owner's base currency.
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
            $table->unique(['user_id', 'category_id']);
        });

        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('target_amount', 15, 2);
            $table->char('currency', 3);
            $table->date('target_date')->nullable();
            // Linked: progress = that account's balance. Otherwise: sum of recorded contributions.
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('goal_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2); // goal currency; negative = withdrawal
            $table->date('date');
            $table->string('note', 300)->nullable();
            $table->timestamps();
        });

        // Zakat settings + which holdings count.
        Schema::table('users', function (Blueprint $table) {
            $table->string('zakat_nisab', 8)->default('gold');       // gold (85 g 24k) | silver (595 g)
            $table->string('zakat_calendar', 10)->default('hijri');  // hijri (354 d, 2.5%) | gregorian (365 d, 2.577%)
            $table->date('zakat_date')->nullable();                  // the yearly zakat day (hawl anniversary)
            $table->boolean('zakat_deduct_debts')->default(true);
        });
        Schema::table('assets', function (Blueprint $table) {
            // null = the type's default (money-like types count, a home or car doesn't).
            $table->boolean('zakatable')->nullable();
        });
        Schema::table('investments', function (Blueprint $table) {
            $table->boolean('zakatable')->nullable();
        });

        Schema::create('zakat_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2); // base currency
            $table->date('date');
            $table->decimal('zakatable_wealth', 16, 2);
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 300)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zakat_payments');
        Schema::table('investments', fn (Blueprint $t) => $t->dropColumn('zakatable'));
        Schema::table('assets', fn (Blueprint $t) => $t->dropColumn('zakatable'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['zakat_nisab', 'zakat_calendar', 'zakat_date', 'zakat_deduct_debts']));
        Schema::dropIfExists('goal_contributions');
        Schema::dropIfExists('goals');
        Schema::dropIfExists('budgets');
    }
};
