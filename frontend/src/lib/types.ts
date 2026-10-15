export type Theme = 'ledger' | 'swiss' | 'geo' | 'vault'
export type ListMode = 'table' | 'cards'

export interface User {
  id: number
  name: string
  email: string
  role: 'owner' | 'heir'
  read_only: boolean
  locale: 'ar' | 'en'
  theme: Theme
  list_mode: ListMode
  base_currency: string
  last_activity_at: string | null
  two_factor: boolean
}

export const ACCOUNT_TYPES = [
  'debit_card', 'credit_card', 'instapay', 'vodafone_cash', 'etisalat_cash',
  'orange_cash', 'we_pay', 'cash', 'bank', 'savings',
] as const
export type AccountType = (typeof ACCOUNT_TYPES)[number]

export interface Account {
  id: number
  name: string
  type: AccountType
  provider: string | null
  currency: string
  balance: number
  base_balance?: number
  credit_limit: number | null
  due_day: number | null
  last4: string | null
  account_number: string | null
  notes: string | null
  is_active: boolean
  sort: number
  updated_at: string | null
}

export type CategoryType = 'expense' | 'income'
export interface Category {
  id: number
  key: string | null
  name: string
  type: CategoryType
  icon: string
  color: string
  sort: number
  usage_count: number
}

export type TxType = 'expense' | 'income' | 'transfer'
export interface Transaction {
  id: number
  type: TxType
  account_id: number
  to_account_id: number | null
  category_id: number | null
  amount: number
  currency: string
  to_amount: number | null
  base_amount?: number
  date: string
  note: string | null
}

export interface TxMonth {
  data: Transaction[]
  meta: {
    month: string
    base_currency: string
    totals: { expense: number; income: number; net: number }
    days: Record<string, { expense: number; income: number }>
  }
}

export interface Upcoming {
  kind: string
  id: number
  name: string
  due_date: string
  days: number
  amount: number
  currency: string
}

export interface Dashboard {
  base_currency: string
  net_worth: number
  liquid: number
  owed_to_me: number
  i_owe: number
  balances: { id: number; name: string; type: AccountType; currency: string; balance: number; base_balance: number }[]
  spending: { month: string; expense: number; income: number }[]
  upcoming: Upcoming[]
}

export type Cycle = 'once' | 'weekly' | 'monthly' | 'quarterly' | 'yearly'
export const CYCLES: Cycle[] = ['once', 'weekly', 'monthly', 'quarterly', 'yearly']

export interface Debt {
  id: number
  person: string
  phone: string | null
  direction: 'owed_to_me' | 'i_owe'
  amount: number
  currency: string
  date: string
  due_date: string | null
  notes: string | null
  status: 'open' | 'settled'
  account_id: number | null
  remind_days: number | null
  paid: number
  remaining: number
  payments?: { id: number; amount: number; date: string; account_id: number | null; note: string | null }[]
}

interface RecurringBase {
  id: number
  name: string
  category_id: number | null
  amount: number
  currency: string
  cycle: Cycle
  remind_days: number | null
  auto_pay: boolean
  account_id: number | null
  notes: string | null
  is_active: boolean
  last_paid_at: string | null
  days: number
}
export interface Bill extends RecurringBase { due_date: string }
export interface Subscription extends RecurringBase {
  renew_date: string
  start_date: string | null
  scope: 'personal' | 'work'
  monthly_cost: number
}

export const INVESTMENT_TYPES = ['stocks', 'fund', 'certificate', 'deposit', 'real_estate', 'gold', 'silver', 'crypto', 'business', 'bonds', 'other'] as const
export interface Investment {
  id: number
  name: string
  type: (typeof INVESTMENT_TYPES)[number]
  platform: string | null
  invested_amount: number
  current_value: number
  currency: string
  base_value: number
  quantity: number | null
  unit: string | null
  start_date: string | null
  maturity_date: string | null
  annual_return: number | null
  payout_cycle: 'monthly' | 'quarterly' | 'yearly' | 'end' | null
  remind_days: number | null
  notes: string | null
  profit: number
  roi: number | null
  zakatable: boolean
  values?: { id: number; date: string; value: number }[]
}

export const ASSET_TYPES = ['building', 'land', 'car', 'device', 'gold', 'silver', 'jewelry', 'foreign_cash', 'other'] as const
export interface Asset {
  id: number
  name: string
  type: (typeof ASSET_TYPES)[number]
  currency: string
  purchase_price: number | null
  purchase_date: string | null
  current_value: number | null
  grams: number | null
  karat: number | null
  location: string | null
  notes: string | null
  value: number
  value_currency: string
  base_value: number
  auto_valued: boolean
  zakatable: boolean
}

export interface Settings {
  base_currency: string
  gold_price_24k: number
  silver_price: number
  alert_days: number
  email_alerts: boolean
  auto_fetch_rates: boolean
  rates: { id: number; currency: string; rate: number; auto_update: boolean; updated_at: string | null }[]
}

export interface Listed<T, M = Record<string, never>> { data: T[]; meta: M }

export interface Report {
  period: 'month' | 'year'
  value: string
  base_currency: string
  totals: { income: number; expense: number; savings: number; savings_rate: number | null }
  previous: { income: number; expense: number; savings: number; savings_rate: number | null }
  change: { income: number | null; expense: number | null; savings: number | null }
  growth: { basis: 'net_worth' | 'savings'; pct: number | null; growing: boolean; value: number; previous: number }
  by_category: { category_id: number | null; key: string | null; name: string | null; color: string | null; icon: string | null; amount: number; share: number; previous: number; change: number | null }[]
  series: { month: string; income: number; expense: number }[]
  net_worth_trend: { month: string; net_worth: number }[]
  advice: { code: string; level: 'danger' | 'warn' | 'info'; text: { ar: string; en: string } }[]
}

export interface IncomeSource extends RecurringBase { next_date: string; monthly: number }

export interface Project {
  id: number
  client: string
  name: string
  amount: number
  currency: string
  start_date: string | null
  due_date: string | null
  status: 'active' | 'done' | 'cancelled'
  category_id: number | null
  remind_days: number | null
  notes: string | null
  received: number
  outstanding: number
  days: number | null
  payments?: { id: number; amount: number; date: string; account_id: number | null; note: string | null }[]
}

export interface IncomeSummary {
  month: string
  base_currency: string
  received: { recurring: number; projects: number; other: number; total: number }
  expected: number
  outstanding: number
  series: { month: string; recurring: number; projects: number; other: number }[]
}

export interface Budget {
  id: number
  category_id: number
  amount: number
  spent: number
  remaining: number
  pct: number
  status: 'ok' | 'near' | 'over'
  projected: number | null
  per_day_left: number | null
}

export interface Goal {
  id: number
  name: string
  target_amount: number
  currency: string
  target_date: string | null
  account_id: number | null
  notes: string | null
  saved: number
  remaining: number
  pct: number
  achieved: boolean
  months_left: number | null
  monthly_needed: number | null
  on_track: boolean | null
  contributions?: { id: number; amount: number; date: string; note: string | null }[]
}

export interface Zakat {
  base_currency: string
  settings: { nisab: 'gold' | 'silver'; calendar: 'hijri' | 'gregorian'; date: string | null; deduct_debts: boolean }
  parts: { cash: number; metals: number; other_assets: number; investments: number; owed_to_me: number; i_owe: number; installments: number; overdrawn: number }
  gross: number
  deductions: number
  net: number
  nisab: number
  price_missing: boolean
  reached: boolean
  rate: number
  due: number
  days_left: number | null
  payments: { id: number; amount: number; date: string; zakatable_wealth: number; account_id: number | null; note: string | null }[]
}

export interface InstallmentPlan {
  id: number
  name: string
  provider: string | null
  currency: string
  installment_amount: number
  count: number
  cycle: 'weekly' | 'monthly' | 'quarterly'
  first_due_date: string
  down_payment: number | null
  account_id: number | null
  category_id: number | null
  remind_days: number | null
  notes: string | null
  paid_count: number
  remaining: number
  total: number
  next_due: string | null
  days: number | null
  last_due: string
  done: boolean
  payments?: { id: number; number: number; amount: number; date: string; account_id: number | null }[]
}

export interface Gameya {
  id: number
  name: string
  organizer: string | null
  members: number
  my_turn: number
  amount: number
  currency: string
  start_date: string
  cycle: 'weekly' | 'monthly'
  account_id: number | null
  remind_days: number | null
  notes: string | null
  payout: number
  paid_rounds: number
  received: boolean
  position: number
  next_round: number | null
  next_date: string | null
  days: number | null
  payout_date: string
  payout_days: number | null
  end_date: string
  done: boolean
  payments?: { id: number; kind: 'contribution' | 'payout'; round: number; amount: number; date: string; account_id: number | null }[]
}
