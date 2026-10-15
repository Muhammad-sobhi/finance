<?php

namespace App\Services;

use App\Models;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Encrypted, portable backup of one owner's data.
 *
 * The file is gzip'd JSON sealed with AES-256-GCM under a key derived from the owner's
 * passphrase (PBKDF2-SHA256), so it is useless without it — the server never stores the
 * passphrase. Encrypted columns are written in plain text inside the sealed file, so a
 * restore works on a server with a different APP_KEY. Ids are remapped on restore.
 */
class BackupService
{
    public const FORMAT = 'treasury-backup';

    public const ITERATIONS = 210000;

    /** Owner settings carried over (not credentials, 2FA or heir access state). */
    private const USER_FIELDS = [
        'name', 'locale', 'theme', 'list_mode', 'base_currency', 'gold_price_24k', 'silver_price', 'alert_days', 'email_alerts',
        'auto_fetch_rates', 'auto_metal_prices', 'heir_enabled', 'heir_inactivity_days', 'heir_grace_days',
        'zakat_nisab', 'zakat_calendar', 'zakat_date', 'zakat_deduct_debts',
    ];

    /**
     * Restore order. [table key, model, parent (key, fk) for child rows or null, foreign keys => table key].
     *
     * @return list<array{0: string, 1: class-string<Model>, 2: array{0: string, 1: string}|null, 3: array<string, string>}>
     */
    private function tables(): array
    {
        return [
            ['exchange_rates', Models\ExchangeRate::class, null, []],
            ['accounts', Models\Account::class, null, []],
            ['categories', Models\Category::class, null, []],
            ['debts', Models\Debt::class, null, ['account_id' => 'accounts']],
            ['debt_payments', Models\DebtPayment::class, ['debts', 'debt_id'], ['account_id' => 'accounts']],
            ['bills', Models\Bill::class, null, ['category_id' => 'categories', 'account_id' => 'accounts']],
            ['subscriptions', Models\Subscription::class, null, ['category_id' => 'categories', 'account_id' => 'accounts']],
            ['income_sources', Models\IncomeSource::class, null, ['category_id' => 'categories', 'account_id' => 'accounts']],
            ['projects', Models\Project::class, null, ['category_id' => 'categories']],
            ['project_payments', Models\ProjectPayment::class, ['projects', 'project_id'], ['account_id' => 'accounts']],
            ['investments', Models\Investment::class, null, []],
            ['investment_values', Models\InvestmentValue::class, ['investments', 'investment_id'], []],
            ['assets', Models\Asset::class, null, []],
            ['net_worth_snapshots', Models\NetWorthSnapshot::class, null, []],
            ['budgets', Models\Budget::class, null, ['category_id' => 'categories']],
            ['goals', Models\Goal::class, null, ['account_id' => 'accounts']],
            ['goal_contributions', Models\GoalContribution::class, ['goals', 'goal_id'], []],
            ['zakat_payments', Models\ZakatPayment::class, null, ['account_id' => 'accounts']],
            ['installment_plans', Models\InstallmentPlan::class, null, ['account_id' => 'accounts', 'category_id' => 'categories']],
            ['installment_payments', Models\InstallmentPayment::class, ['installment_plans', 'installment_plan_id'], ['account_id' => 'accounts']],
            ['gameyas', Models\Gameya::class, null, ['account_id' => 'accounts']],
            ['gameya_payments', Models\GameyaPayment::class, ['gameyas', 'gameya_id'], ['account_id' => 'accounts']],
            ['heirs', Models\Heir::class, null, []],
            ['trusted_contacts', Models\TrustedContact::class, null, []],
            ['transactions', Models\Transaction::class, null, ['account_id' => 'accounts', 'to_account_id' => 'accounts', 'category_id' => 'categories']],
        ];
    }

    /** Morph name of a transaction source → table key. */
    private const SOURCES = [
        'debt' => 'debts', 'debt_payment' => 'debt_payments', 'bill' => 'bills', 'subscription' => 'subscriptions',
        'income_source' => 'income_sources', 'project_payment' => 'project_payments', 'zakat_payment' => 'zakat_payments',
        'installment_payment' => 'installment_payments', 'gameya_payment' => 'gameya_payments', 'transfer_fee' => 'transactions',
    ];

    /** @return array<string, mixed> */
    public function export(User $owner): array
    {
        $data = ['user' => $owner->only(self::USER_FIELDS), 'tables' => []];
        $data['user']['zakat_date'] = $owner->zakat_date?->toDateString();
        $ids = [];
        foreach ($this->tables() as [$key, $class, $parent]) {
            $query = $parent ? $class::query()->whereIn($parent[1], $ids[$parent[0]] ?? []) : $class::query()->where('user_id', $owner->id);
            $rows = $query->orderBy('id')->get();
            $ids[$key] = $rows->pluck('id')->all();
            $data['tables'][$key] = $rows->map(fn (Model $m) => $this->row($m))->all();
        }

        return $data;
    }

    /** Sealed file contents. */
    public function seal(User $owner, string $passphrase): string
    {
        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $key = hash_pbkdf2('sha256', $passphrase, $salt, self::ITERATIONS, 32, true);
        $plain = gzencode(json_encode($this->export($owner), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 9);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return json_encode([
            'format' => self::FORMAT, 'version' => 1, 'created_at' => now()->toIso8601String(),
            'kdf' => 'pbkdf2-sha256', 'iterations' => self::ITERATIONS,
            'salt' => base64_encode($salt), 'iv' => base64_encode($iv), 'tag' => base64_encode($tag), 'data' => base64_encode($cipher),
        ], JSON_PRETTY_PRINT);
    }

    /** @return array<string, mixed> */
    public function open(string $file, string $passphrase): array
    {
        $box = json_decode($file, true);
        if (! is_array($box) || ($box['format'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('Not a backup file.');
        }
        $key = hash_pbkdf2('sha256', $passphrase, base64_decode($box['salt']), (int) $box['iterations'], 32, true);
        $plain = openssl_decrypt(base64_decode($box['data']), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, base64_decode($box['iv']), base64_decode($box['tag']));
        if ($plain === false) {
            throw new RuntimeException('Wrong passphrase or damaged file.');
        }

        return json_decode(gzdecode($plain), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Restore into an owner account that has no data yet. Returns rows restored per table. */
    public function restore(User $owner, array $data): array
    {
        if ($owner->accounts()->exists() || $owner->transactions()->exists()) {
            throw new RuntimeException('This account already has data. Restore into a new, empty owner.');
        }

        return DB::transaction(function () use ($owner, $data) {
            $owner->categories()->delete(); // the seeded defaults; the backup brings its own
            $owner->forceFill(array_intersect_key($data['user'] ?? [], array_flip(self::USER_FIELDS)))->save();

            $map = [];
            $counts = [];
            foreach ($this->tables() as [$key, $class, $parent, $fks]) {
                $counts[$key] = 0;
                foreach ($data['tables'][$key] ?? [] as $row) {
                    $oldId = $row['raw']['id'];
                    $raw = $row['raw'];
                    unset($raw['id']);
                    if ($parent) {
                        $raw[$parent[1]] = $map[$parent[0]][$raw[$parent[1]]] ?? throw new RuntimeException("Broken link in {$key}.");
                    } else {
                        $raw['user_id'] = $owner->id;
                    }
                    foreach ($fks as $col => $table) {
                        if (isset($raw[$col])) {
                            $raw[$col] = $map[$table][$raw[$col]] ?? null;
                        }
                    }
                    if ($key === 'transactions' && $raw['source_type']) {
                        $raw['source_id'] = $map[self::SOURCES[$raw['source_type']] ?? ''][$raw['source_id']] ?? null;
                        $raw['source_type'] = $raw['source_id'] ? $raw['source_type'] : null;
                    }
                    if ($key === 'heirs') {
                        $raw['login_user_id'] = null;
                    }
                    $model = new $class;
                    $model->setRawAttributes($raw);
                    foreach ($row['secret'] as $col => $value) {
                        $model->setAttribute($col, $value);
                    }
                    $model->save();
                    $map[$key][$oldId] = $model->id;
                    $counts[$key]++;
                }
            }

            return $counts;
        });
    }

    /** Raw column values, except encrypted columns which are stored decrypted. */
    private function row(Model $m): array
    {
        $raw = $m->getAttributes();
        $secret = [];
        foreach ($m->getCasts() as $col => $cast) {
            if (str_starts_with((string) $cast, 'encrypted') && array_key_exists($col, $raw)) {
                $secret[$col] = $m->getAttribute($col);
                unset($raw[$col]);
            }
        }

        return ['raw' => $raw, 'secret' => $secret];
    }
}
