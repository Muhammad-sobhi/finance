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
