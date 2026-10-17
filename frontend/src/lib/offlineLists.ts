import { api } from './api'
import { offlineCache } from './offline'
import type { Account, Category } from './types'

// What the add forms keep on the device for offline use: names, ids, currencies, colours.
// Balances, account numbers and notes are left out.
export const keepAccounts = (list: Account[]): Account[] => list.map((a) => ({
  id: a.id, name: a.name, type: a.type, currency: a.currency, is_active: a.is_active, sort: a.sort,
  provider: null, balance: 0, credit_limit: null, due_day: null, last4: null, account_number: null, notes: null, updated_at: null,
}))

export const keepCategories = (list: Category[]): Category[] => list.map((c) => ({
  id: c.id, key: c.key, name: c.name, type: c.type, icon: c.icon, color: c.color, sort: c.sort, usage_count: c.usage_count,
}))

/** The lists (path → trim) the quick-add sheet and the transaction form read through useApi. */
const LISTS: [string, (data: never) => unknown][] = [
  ['/accounts?active_only=1', keepAccounts],
  ['/accounts', keepAccounts],
  ['/categories', keepCategories],
  ['/categories?type=expense', keepCategories],
  ['/categories?type=income', keepCategories],
  ['/transactions/quick-meta', (m) => m],
]

/** Refresh the device copies once the app is idle, so adding works offline from day one. */
export function primeOfflineLists(userId: number) {
  const idle = window.requestIdleCallback ?? ((fn: () => void) => window.setTimeout(fn, 3000))
  idle(() => {
    for (const [path, keep] of LISTS) {
      api<never>(path).then((data) => offlineCache.set(userId, path, keep(data))).catch(() => undefined)
    }
  })
}
