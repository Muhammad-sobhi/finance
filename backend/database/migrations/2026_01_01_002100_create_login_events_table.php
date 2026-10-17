<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sign-in history shown to the user; also how a new device is recognised.
        Schema::create('login_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('success');
            $table->string('method', 20);          // password | authenticator | recovery_code | trusted_device
            $table->string('device', 80);          // "Chrome on Android"
            $table->char('fingerprint', 40);       // sha1 of the device label
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_events');
    }
};
