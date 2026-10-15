<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Transfer fee (sending account's currency). It's also booked as its own expense row
            // (source_type 'transfer_fee'), so reports count it as spending.
            $table->decimal('fee', 15, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', fn (Blueprint $t) => $t->dropColumn('fee'));
    }
};
