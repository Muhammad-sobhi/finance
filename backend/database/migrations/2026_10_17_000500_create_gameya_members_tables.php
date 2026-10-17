<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gameya management: the manager tracks every turn (member), who paid each round and when
 * each payout was handed over. Members who have an account can be linked to their turn and
 * then see that turn only. Names, phones, emails, notes and amounts are encrypted with the
 * manager's data key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gameyas', function (Blueprint $table) {
            $table->string('role', 8)->default('member'); // member (my own share) | manager (I run it)
            $table->unsignedSmallInteger('my_turn')->nullable()->change(); // a manager may have no turn
        });

        Schema::create('gameya_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gameya_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // the manager
            $table->unsignedSmallInteger('turn');
            $table->text('name')->nullable();
            $table->text('phone')->nullable();
            $table->text('email')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_me')->default(false);
            // A member with an account: set on invite, visible to the manager only once accepted.
            $table->foreignId('linked_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->date('payout_date')->nullable();   // when this turn's pot was handed over
            $table->text('payout_amount')->nullable();
            $table->timestamps();
            $table->unique(['gameya_id', 'turn']);
            $table->index('linked_user_id');
        });

        Schema::create('gameya_member_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gameya_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('gameya_members')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // the manager
            $table->unsignedSmallInteger('round');
            $table->text('amount');
            $table->date('date');
            $table->boolean('confirmed')->default(true); // false = the member said "I paid", the manager hasn't confirmed
            $table->timestamps();
            $table->unique(['member_id', 'round']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gameya_member_payments');
        Schema::dropIfExists('gameya_members');
        Schema::table('gameyas', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
