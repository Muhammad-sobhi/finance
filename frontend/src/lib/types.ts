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
