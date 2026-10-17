<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Private disk path (storage/app/private/receipts/…); served only through the API.
            $table->string('receipt_path')->nullable();
            $table->string('receipt_mime', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', fn (Blueprint $t) => $t->dropColumn(['receipt_path', 'receipt_mime']));
    }
};
