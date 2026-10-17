<?php

use App\Services\DataVault;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Per-owner encryption of financial data (see DataVault / EncryptedForOwner).
 * Needs the data master key configured when there is existing data to convert.
 */
return new class extends Migration
{
    /**
     * Encrypted columns and their previous definition (for down()).
     * [type, args, nullable, default]; 'app_key' marks columns that used the old `encrypted` cast.
     */
    private const COLUMNS = [
        'accounts' => [
            'name' => ['string', [], false], 'provider' => ['string', [], true],
            'balance' => ['decimal', [15, 2], false, 0], 'credit_limit' => ['decimal', [15, 2], true],
            'last4' => ['string', [4], true], 'account_number' => ['text', [], true, null, 'app_key'], 'notes' => ['text', [], true, null, 'app_key'],
        ],
        'transactions' => [
            'amount' => ['decimal', [15, 2], false], 'to_amount' => ['decimal', [15, 2], true],
            'fee' => ['decimal', [15, 2], true], 'note' => ['string', [500], true],
        ],
        'debts' => [
            'person' => ['string', [], false], 'phone' => ['string', [32], true],
            'amount' => ['decimal', [15, 2], false], 'notes' => ['text', [], true, null, 'app_key'],
        ],
        'debt_payments' => ['amount' => ['decimal', [15, 2], false], 'note' => ['string', [500], true]],
        'bills' => ['name' => ['string', [], false], 'amount' => ['decimal', [15, 2], false], 'notes' => ['text', [], true]],
        'subscriptions' => ['name' => ['string', [], false], 'amount' => ['decimal', [15, 2], false], 'notes' => ['text', [], true]],
        'investments' => [
            'name' => ['string', [], false], 'platform' => ['string', [], true],
            'invested_amount' => ['decimal', [15, 2], false], 'current_value' => ['decimal', [15, 2], false],
            'quantity' => ['decimal', [18, 6], true], 'annual_return' => ['decimal', [7, 3], true], 'notes' => ['text', [], true],
        ],
        'investment_values' => ['value' => ['decimal', [15, 2], false]],
        'assets' => [
            'name' => ['string', [], false], 'purchase_price' => ['decimal', [15, 2], true], 'current_value' => ['decimal', [15, 2], true],
            'grams' => ['decimal', [12, 3], true], 'location' => ['string', [], true], 'notes' => ['text', [], true],
        ],
        'net_worth_snapshots' => ['net_worth' => ['decimal', [16, 2], false], 'breakdown' => ['json', [], false]],
        'heirs' => [
            'name' => ['string', [], false], 'relation' => ['string', [60], true], 'phone' => ['string', [32], true],
            'national_id' => ['text', [], true, null, 'app_key'], 'address' => ['text', [], true, null, 'app_key'], 'notes' => ['text', [], true, null, 'app_key'],
        ],
        'trusted_contacts' => ['name' => ['string', [], false], 'phone' => ['string', [32], true], 'relation' => ['string', [60], true]],
        'income_sources' => ['name' => ['string', [], false], 'amount' => ['decimal', [15, 2], false], 'notes' => ['text', [], true]],
        'projects' => [
            'client' => ['string', [], false], 'name' => ['string', [], false],
            'amount' => ['decimal', [15, 2], false], 'notes' => ['text', [], true, null, 'app_key'],
        ],
        'project_payments' => ['amount' => ['decimal', [15, 2], false], 'note' => ['string', [500], true]],
        'budgets' => ['amount' => ['decimal', [15, 2], false]],
        'goals' => ['name' => ['string', [], false], 'target_amount' => ['decimal', [15, 2], false], 'notes' => ['text', [], true]],
        'goal_contributions' => ['amount' => ['decimal', [15, 2], false], 'note' => ['string', [300], true]],
        'zakat_payments' => ['amount' => ['decimal', [15, 2], false], 'zakatable_wealth' => ['decimal', [16, 2], false], 'note' => ['string', [300], true]],
        'installment_plans' => [
            'name' => ['string', [], false], 'provider' => ['string', [120], true],
            'installment_amount' => ['decimal', [15, 2], false], 'down_payment' => ['decimal', [15, 2], true], 'notes' => ['text', [], true],
        ],
        'installment_payments' => ['amount' => ['decimal', [15, 2], false]],
        'gameyas' => [
            'name' => ['string', [], false], 'organizer' => ['string', [120], true],
            'amount' => ['decimal', [15, 2], false], 'notes' => ['text', [], true],
        ],
        'gameya_payments' => ['amount' => ['decimal', [15, 2], false]],
    ];

    /** Child tables get their owner's id so each row can be decrypted on its own. */
    private const CHILDREN = [
        'debt_payments' => ['debts', 'debt_id'], 'investment_values' => ['investments', 'investment_id'],
        'project_payments' => ['projects', 'project_id'], 'goal_contributions' => ['goals', 'goal_id'],
        'installment_payments' => ['installment_plans', 'installment_plan_id'], 'gameya_payments' => ['gameyas', 'gameya_id'],
    ];

    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->text('data_key')->nullable());

        foreach (self::CHILDREN as $table => [$parent, $fk]) {
            Schema::table($table, fn (Blueprint $t) => $t->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete());
            DB::statement("update {$table} set user_id = (select user_id from {$parent} where {$parent}.id = {$table}.{$fk})");
        }

        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns) {
                foreach ($columns as $name => $def) {
                    $t->text($name)->nullable($def[2])->change();
                }
            });
        }

        $vault = app(DataVault::class);
        foreach (self::COLUMNS as $table => $columns) {
            $this->convert($table, 'user_id', array_keys($columns), function ($value, $owner, $column) use ($vault, $columns) {
                $plain = ($columns[$column][4] ?? null) === 'app_key' ? Crypt::decryptString($value) : (string) $value;

                return $vault->encrypt($owner, $plain);
            });
        }
        $this->convert('notifications', 'notifiable_id', ['data'], fn ($value, $owner) => $vault->encrypt($owner, $value), true);
        $this->sealReceipts(fn ($bytes, $owner) => $vault->encrypt($owner, $bytes), true);
    }

    public function down(): void
    {
        $vault = app(DataVault::class);
        foreach (self::COLUMNS as $table => $columns) {
            $this->convert($table, 'user_id', array_keys($columns), function ($value, $owner, $column) use ($vault, $columns) {
                $plain = $vault->decrypt($owner, $value);

                return ($columns[$column][4] ?? null) === 'app_key' ? Crypt::encryptString($plain) : $plain;
            });
        }
        $this->convert('notifications', 'notifiable_id', ['data'], fn ($value, $owner) => $vault->decrypt($owner, $value), true);
        $this->sealReceipts(fn ($bytes, $owner) => $vault->decrypt($owner, $bytes), false);

        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns) {
                foreach ($columns as $name => [$type, $args, $nullable]) {
                    $column = $t->{$type}($name, ...$args)->nullable($nullable);
                    if (array_key_exists(3, $columns[$name]) && $columns[$name][3] !== null) {
                        $column->default($columns[$name][3]);
                    }
                    $column->change();
                }
            });
        }

        foreach (array_keys(self::CHILDREN) as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('user_id'));
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('data_key'));
    }

    /** Receipt files: encrypt into `<path>.enc` (or back), then point the transaction at the new file. */
    private function sealReceipts(callable $fn, bool $seal): void
    {
        $disk = Storage::disk('local');
        DB::table('transactions')->whereNotNull('receipt_path')->orderBy('id')->chunkById(200, function ($rows) use ($disk, $fn, $seal) {
            foreach ($rows as $row) {
                if (str_ends_with($row->receipt_path, '.enc') === $seal || ! $disk->exists($row->receipt_path)) {
                    continue;
                }
                $path = $seal ? $row->receipt_path.'.enc' : substr($row->receipt_path, 0, -4);
                $disk->put($path, $fn($disk->get($row->receipt_path), (int) $row->user_id));
                DB::table('transactions')->where('id', $row->id)->update(['receipt_path' => $path]);
                $disk->delete($row->receipt_path);
            }
        });
    }

    /** Rewrite the given columns of every row with $fn(value, ownerId, column). */
    private function convert(string $table, string $ownerColumn, array $columns, callable $fn, bool $usersOnly = false): void
    {
        DB::table($table)
            ->when($usersOnly, fn ($q) => $q->whereIn('notifiable_type', ['user', 'App\\Models\\User']))
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table, $ownerColumn, $columns, $fn) {
                foreach ($rows as $row) {
                    $changes = [];
                    foreach ($columns as $column) {
                        if ($row->{$column} !== null) {
                            $changes[$column] = $fn($row->{$column}, (int) $row->{$ownerColumn}, $column);
                        }
                    }
                    if ($changes !== []) {
                        DB::table($table)->where('id', $row->id)->update($changes);
                    }
                }
            });
    }
};
