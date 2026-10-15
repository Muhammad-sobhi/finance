<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $common = function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->char('currency', 3);
            $table->string('cycle', 10); // once | weekly | monthly | quarterly | yearly
            $table->unsignedSmallInteger('remind_days')->nullable(); // null → owner default
            $table->boolean('auto_pay')->default(false);
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('last_paid_at')->nullable();
        };

        Schema::create('bills', function (Blueprint $table) use ($common) {
            $common($table);
            $table->date('due_date');
            $table->timestamps();
            $table->index(['user_id', 'is_active', 'due_date']);
        });

        Schema::create('subscriptions', function (Blueprint $table) use ($common) {
            $common($table);
            $table->string('scope', 10)->default('personal'); // personal | work
            $table->date('start_date')->nullable();
            $table->date('renew_date');
            $table->timestamps();
            $table->index(['user_id', 'is_active', 'renew_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('bills');
    }
};
