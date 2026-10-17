<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Taqseet: something bought on installments (valU, a store, a bank card plan…).
        Schema::create('installment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('provider', 120)->nullable();
            $table->char('currency', 3);
            $table->decimal('installment_amount', 15, 2);
            $table->unsignedSmallInteger('count');
            $table->date('first_due_date');
            $table->string('cycle', 12)->default('monthly');
            $table->decimal('down_payment', 15, 2)->nullable();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('remind_days')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('installment_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installment_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->decimal('amount', 15, 2);
            $table->date('date');
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['installment_plan_id', 'number']);
        });

        // Gameya (ROSCA): everyone pays each round; one member takes the whole pot per round.
        Schema::create('gameyas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('organizer', 120)->nullable();
            $table->unsignedSmallInteger('members');
            $table->unsignedSmallInteger('my_turn');
            $table->decimal('amount', 15, 2); // per round
            $table->char('currency', 3);
            $table->date('start_date');       // round 1
            $table->string('cycle', 12)->default('monthly');
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('remind_days')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('gameya_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gameya_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12); // contribution | payout
            $table->unsignedSmallInteger('round');
            $table->decimal('amount', 15, 2);
            $table->date('date');
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['gameya_id', 'kind', 'round']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gameya_payments');
        Schema::dropIfExists('gameyas');
        Schema::dropIfExists('installment_payments');
        Schema::dropIfExists('installment_plans');
    }
};
