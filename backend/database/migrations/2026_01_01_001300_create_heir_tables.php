<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('heir_enabled')->default(false);
            $table->unsignedSmallInteger('heir_inactivity_days')->default(60);
            $table->unsignedSmallInteger('heir_grace_days')->default(14);
            // Dead-man switch: set when inactivity crosses the threshold (grace window starts).
            $table->timestamp('heir_warning_started_at')->nullable();
            $table->timestamp('heir_last_reminder_at')->nullable();
        });

        Schema::create('heirs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // owner
            $table->string('name');
            $table->string('relation', 60)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email');
            $table->text('national_id')->nullable(); // encrypted
            $table->text('address')->nullable();     // encrypted
            $table->text('notes')->nullable();       // encrypted
            $table->foreignId('login_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamps();
            $table->index(['email']);
        });

        Schema::create('trusted_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 32)->nullable();
            $table->string('relation', 60)->nullable();
            $table->timestamps();
        });

        Schema::create('heir_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // owner
            $table->foreignId('heir_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12)->default('pending'); // pending | confirmed | cancelled | activated
            $table->string('requested_ip', 45)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('trusted_contacts')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('activates_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'activates_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heir_requests');
        Schema::dropIfExists('trusted_contacts');
        Schema::dropIfExists('heirs');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['heir_enabled', 'heir_inactivity_days', 'heir_grace_days', 'heir_warning_started_at', 'heir_last_reminder_at']);
        });
    }
};
