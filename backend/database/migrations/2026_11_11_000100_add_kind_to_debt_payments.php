<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debt_payments', function (Blueprint $table) {
            // payment = money paid back; advance = more money lent/borrowed on the same debt.
            $table->string('kind', 8)->default('payment')->after('debt_id');
        });
    }

    public function down(): void
    {
        Schema::table('debt_payments', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
