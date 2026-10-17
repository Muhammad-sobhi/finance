<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);              // may invite new owners
            $table->timestamp('invited_at')->nullable();              // set for invited accounts
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            // An invited account is activated when its owner first sets a password (email_verified_at).
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invited_by');
            $table->dropColumn(['is_admin', 'invited_at']);
        });
    }
};
