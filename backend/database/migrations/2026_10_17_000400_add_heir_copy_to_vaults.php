<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The vault PIN becomes optional, and the owner can leave a copy of the vault key for their
 * heirs (`escrow_key`, encrypted with the owner's data key like the rest of their data).
 * Released only to the owner when they chose no PIN, or to heirs once heir access is active.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vaults', function (Blueprint $table) {
            $table->string('salt', 64)->nullable()->change();
            $table->unsignedInteger('iterations')->nullable()->change();
            $table->string('iv', 32)->nullable()->change();
            $table->text('wrapped_key')->nullable()->change();
            $table->text('escrow_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vaults', function (Blueprint $table) {
            $table->dropColumn('escrow_key');
        });
    }
};
