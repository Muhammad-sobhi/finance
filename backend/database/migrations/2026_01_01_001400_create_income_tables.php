<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recurring income (salary, rent received, retainer…): same shape as bills, but
        // "receive" records income and rolls the next expected date.
        Schema::create('income_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->char('currency', 3);
            $table->string('cycle', 10);
            $table->date('next_date');
            $table->unsignedSmallInteger('remind_days')->nullable();
            $table->boolean('auto_pay')->default(false); // UI: "record automatically"
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('last_paid_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'is_active', 'next_date']);
        });

        // Freelance / one-off work: agreed amount, payments received over time.
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('client');
            $table->string('name');
            $table->decimal('amount', 15, 2); // agreed
            $table->char('currency', 3);
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable(); // when payment is expected
            $table->string('status', 10)->default('active'); // active | done | cancelled
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('remind_days')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('project_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2); // in the project's currency
            $table->date('date');
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_payments');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('income_sources');
    }
};
