<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Models\User;
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
        ])->map(fn ($a, $i) => $owner->accounts()->create([
            'name' => $a[0], 'type' => $a[1], 'provider' => $a[2], 'currency' => $a[3],
            'balance' => $a[4], 'credit_limit' => $a[5] ?? null, 'due_day' => $a[6] ?? null, 'sort' => $i,
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
    }
}
