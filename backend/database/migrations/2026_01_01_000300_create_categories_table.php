<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Seeded defaults carry a `key` so the UI can translate them; renaming clears it.
            $table->string('key', 32)->nullable();
            $table->string('name');
            $table->string('type', 8); // expense | income
            $table->string('icon', 24)->default('tag');
            $table->string('color', 7)->default('#6b6a5c');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
