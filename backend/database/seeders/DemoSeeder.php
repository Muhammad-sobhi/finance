<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Models\User;
use App\Services\DebtService;
use App\Services\NetWorth;
use App\Services\TransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/** Local demo owner (demo@example.com / password) with accounts and ~6 months of expenses. */
class DemoSeeder extends Seeder
{
    public function run(TransactionService $service): void
    {
        $owner = User::firstOrCreate(['email' => 'demo@example.com'], [
            'name' => 'Muhammad', 'password' => 'password', 'last_activity_at' => now(),
        ]);
        if ($owner->accounts()->exists()) {
            return;
        }
        $owner->exchangeRates()->create(['currency' => 'USD', 'rate' => 48.5]);

        // [name, type, provider, currency, final balance, credit limit, due day]
        $accounts = collect([
            ['NBE Debit', AccountType::DebitCard, 'NBE', 'EGP', 42300],
            ['CIB Credit', AccountType::CreditCard, 'CIB', 'EGP', -6400, 40000, 25],
            ['InstaPay', AccountType::InstaPay, 'InstaPay', 'EGP', 8200],
            ['Vodafone Cash', AccountType::VodafoneCash, 'Vodafone', 'EGP', 2750],
            ['Etisalat Cash', AccountType::EtisalatCash, 'e&', 'EGP', 1100],
            ['Orange Cash', AccountType::OrangeCash, 'Orange', 'EGP', 640],
            ['Cash at home', AccountType::Cash, null, 'EGP', 3150],
            ['Wallet cash', AccountType::Cash, null, 'EGP', 820],
            ['Banque Misr Debit', AccountType::DebitCard, 'Banque Misr', 'EGP', 15800],
            ['Savings account', AccountType::Savings, 'NBE', 'EGP', 120000],
            ['USD wallet', AccountType::Cash, null, 'USD', 500],
            ['WE Pay', AccountType::WePay, 'WE', 'EGP', 300],
        ]);
        $targets = $accounts->pluck(4)->all();
        $accounts = $accounts->map(fn ($a, $i) => $owner->accounts()->create([
            'name' => $a[0], 'type' => $a[1], 'provider' => $a[2], 'currency' => $a[3],
            'balance' => 0, 'credit_limit' => $a[5] ?? null, 'due_day' => $a[6] ?? null, 'sort' => $i,
        ]));

        $cats = $owner->categories()->pluck('id', 'key');
        $spendable = $accounts->whereIn('type', [AccountType::DebitCard, AccountType::Cash, AccountType::VodafoneCash, AccountType::InstaPay])
            ->where('currency', 'EGP')->values();
        $notes = ['food' => 'Groceries', 'transport' => 'Uber', 'shopping' => 'Clothes', 'health' => 'Pharmacy', 'entertainment' => 'Cinema', 'family' => 'Gift', 'bills' => 'Electricity'];
        $start = CarbonImmutable::today()->subMonths(5)->startOfMonth();

        mt_srand(42);
        for ($d = $start; $d <= CarbonImmutable::today(); $d = $d->addDay()) {
            if ($d->day === 1) {
                $service->create($owner, ['type' => 'income', 'account_id' => $accounts[0]->id, 'category_id' => $cats['salary'], 'amount' => 35000, 'date' => $d->toDateString(), 'note' => 'Salary']);
            }
            for ($n = mt_rand(0, 3); $n > 0; $n--) {
                $key = array_rand($notes);
                $service->create($owner, [
                    'type' => 'expense', 'account_id' => $spendable[mt_rand(0, $spendable->count() - 1)]->id,
                    'category_id' => $cats[$key], 'amount' => mt_rand(3, 90) * 10, 'date' => $d->toDateString(), 'note' => $notes[$key],
                ]);
            }
        }

        $this->wealth($owner, $accounts->first()->id, $cats);

        // Opening balances chosen so today's balances look like a real wallet.
        foreach ($accounts as $i => $account) {
            $account->update(['balance' => $targets[$i]]);
        }

        // Net-worth history for the trend chart: steady growth up to today's live value.
        $now = app(NetWorth::class)->snapshot($owner)->net_worth;
        for ($m = 6; $m >= 1; $m--) {
            $owner->snapshots()->updateOrCreate(
                ['month' => CarbonImmutable::today()->startOfMonth()->subMonths($m)->toDateString()],
                ['net_worth' => round($now * (1 - 0.018 * $m)), 'breakdown' => []],
            );
        }
    }

    private function wealth(User $owner, int $bankId, $cats): void
    {
        $owner->update(['gold_price_24k' => 4800, 'silver_price' => 60]);
        $today = CarbonImmutable::today();
        $debts = app(DebtService::class);
        $debts->create($owner, ['person' => 'Ahmed', 'direction' => 'owed_to_me', 'amount' => 5500, 'currency' => 'EGP', 'date' => $today->subMonth()->toDateString(), 'due_date' => $today->addDays(12)->toDateString(), 'phone' => '01000000000']);
        $debts->create($owner, ['person' => 'Khaled', 'direction' => 'i_owe', 'amount' => 2000, 'currency' => 'EGP', 'date' => $today->subWeeks(2)->toDateString(), 'due_date' => $today->addDays(9)->toDateString()]);

        foreach ([
            ['Electricity', 'bills', 640, 2, 'monthly'], ['Car installment', 'transport', 7800, 9, 'monthly'],
            ['Home internet', 'bills', 450, 14, 'monthly'], ['Gas', 'bills', 120, 21, 'monthly'],
            ['Car insurance', 'transport', 5400, 40, 'yearly'], ['Water', 'bills', 90, -3, 'monthly'],
        ] as [$name, $cat, $amount, $days, $cycle]) {
            $owner->bills()->create(['name' => $name, 'category_id' => $cats[$cat], 'amount' => $amount, 'currency' => 'EGP', 'due_date' => $today->addDays($days)->toDateString(), 'cycle' => $cycle, 'account_id' => $bankId]);
        }
        foreach ([
            ['Claude', 20, 'USD', 5, 'monthly', 'work'], ['Hostinger VPS', 620, 'EGP', 18, 'quarterly', 'work'],
            ['Netflix', 240, 'EGP', 25, 'monthly', 'personal'], ['Gym', 900, 'EGP', 11, 'monthly', 'personal'], ['iCloud', 50, 'EGP', 3, 'monthly', 'personal'],
        ] as [$name, $amount, $cur, $days, $cycle, $scope]) {
            $owner->subscriptions()->create(['name' => $name, 'category_id' => $cats[$scope === 'work' ? 'work' : 'entertainment'], 'amount' => $amount, 'currency' => $cur, 'renew_date' => $today->addDays($days)->toDateString(), 'start_date' => $today->subYear()->toDateString(), 'cycle' => $cycle, 'scope' => $scope, 'account_id' => $bankId]);
        }
        foreach ([
            ['NBE 3-year certificate', 'certificate', 'NBE', 200000, 200000, 27, 'monthly', 400],
            ['Azimut equity fund', 'fund', 'Azimut', 50000, 61800, null, null, null],
            ['EGX stocks', 'stocks', 'Thndr', 30000, 27400, null, null, null],
            ['Gold bars', 'gold', 'BTC', 60000, 79000, null, null, null],
        ] as [$name, $type, $platform, $invested, $current, $rate, $payout, $maturityDays]) {
            $inv = $owner->investments()->create(['name' => $name, 'type' => $type, 'platform' => $platform, 'invested_amount' => $invested, 'current_value' => $invested, 'currency' => 'EGP', 'start_date' => $today->subMonths(6)->toDateString(), 'maturity_date' => $maturityDays ? $today->addDays($maturityDays)->toDateString() : null, 'annual_return' => $rate, 'payout_cycle' => $payout]);
            for ($m = 5; $m >= 1; $m--) {
                $inv->values()->updateOrCreate(['date' => $today->subMonths($m)->toDateString()], ['value' => round($invested + ($current - $invested) * (6 - $m) / 6)]);
            }
            $inv->update(['current_value' => $current]);
        }
        foreach ([
            ['Apartment', 'building', 2500000, 3400000, null, null, 'Nasr City'], ['Car', 'car', 900000, 750000, null, null, null],
            ['Wedding gold', 'gold', null, null, 40, 21, 'Safe'], ['Silver', 'silver', null, null, 250, null, 'Safe'], ['Laptop', 'device', 60000, 40000, null, null, null],
        ] as [$name, $type, $price, $value, $grams, $karat, $location]) {
            $owner->assets()->create(['name' => $name, 'type' => $type, 'currency' => 'EGP', 'purchase_price' => $price, 'current_value' => $value, 'grams' => $grams, 'karat' => $karat, 'location' => $location]);
        }
    }
}
