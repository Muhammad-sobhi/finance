<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Metal prices per gram in the owner's base currency (gold = 24k).
            $table->decimal('gold_price_24k', 12, 2)->default(0);
            $table->decimal('silver_price', 12, 2)->default(0);
            $table->unsignedSmallInteger('alert_days')->default(3);
            $table->boolean('email_alerts')->default(true);
            $table->boolean('auto_fetch_rates')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['gold_price_24k', 'silver_price', 'alert_days', 'email_alerts', 'auto_fetch_rates']);
        });
    }
};
