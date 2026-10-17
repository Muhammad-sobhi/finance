<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The PIN vault. Everything is encrypted in the browser (AES-GCM) with a vault key that is
 * itself wrapped by a key derived from the owner's PIN (PBKDF2). The server only stores
 * ciphertext: it can neither read items nor unwrap the key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('salt', 64);
            $table->unsignedInteger('iterations');
            $table->string('iv', 32);
            $table->text('wrapped_key');
            $table->timestamps();
        });

        Schema::create('vault_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('iv', 32);
            $table->mediumText('ciphertext');
            // Optional, plain: lets the server remind about a renewal without reading the item.
            $table->date('remind_on')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_items');
        Schema::dropIfExists('vaults');
    }
};
