import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { useI18n } from '../i18n'
import { useApi } from '../hooks/useApi'
import { ApiError, post } from '../lib/api'
import { isNetworkError, newClientId, outbox } from '../lib/offline'
import { keepAccounts, keepCategories } from '../lib/offlineLists'
import { isoDate } from '../lib/dates'
import { catName } from '../lib/labels'
import { useSession } from '../lib/session'
import type { Account, Category } from '../lib/types'
import { FitChips } from './Chips'
import { shortfallOf, useFunds } from './FundsPrompt'
import { Icon } from './Icon'

/**
 * The < 5 second expense logger: amount (focused), one tap on a category chip
 * (most-used first), account preselected to the last one used, save.
 */
export function QuickAdd({ onClose, onDetails }: { onClose: () => void; onDetails: () => void }) {
  const i = useI18n()
  const { t } = i
  const { refresh, toast, user } = useSession()
  const funds = useFunds()
  const [type, setType] = useState<'expense' | 'income'>('expense')
  // Kept on this device too, so the sheet works offline.
  const cats = useApi<Category[]>(`/categories?type=${type}`, { offline: keepCategories })
  const accounts = useApi<Account[]>('/accounts?active_only=1', { offline: keepAccounts })
  const meta = useApi<{ last_account_id: number | null; last_category_id: number | null }>('/transactions/quick-meta', { offline: (m) => m })
  const [amount, setAmount] = useState('')
  const [categoryId, setCategoryId] = useState<number | null>(null)
  const [accountId, setAccountId] = useState<number | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const amountRef = useRef<HTMLInputElement>(null)

  useEffect(() => { amountRef.current?.focus() }, [])
  useEffect(() => { setCategoryId(cats.data?.[0]?.id ?? null) }, [cats.data])
  useEffect(() => {
    if (accountId !== null || !accounts.data?.length) return
    const last = meta.data?.last_account_id
    setAccountId(accounts.data.some((a) => a.id === last) ? last! : accounts.data[0].id)
  }, [accounts.data, meta.data, accountId])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  const chips = useMemo(() => (cats.data ?? []).map((c) => ({ id: c.id, label: catName(i, c), color: c.color })), [cats.data, i])
  const currency = accounts.data?.find((a) => a.id === accountId)?.currency ?? ''

  const save = async (e: FormEvent) => {
    e.preventDefault()
    const value = Number(amount.replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d))).replace(/[٫,]/g, '.'))
    if (!value || value <= 0) { setError(t('amount')); amountRef.current?.focus(); return }
    if (!accountId) { setError(t('account')); return }
    setBusy(true)
    const body = { type, amount: value, account_id: accountId, category_id: categoryId, date: isoDate() }
    const clientId = newClientId()
    // No connection: keep it on this device; it is sent when the connection is back.
    const queue = () => {
      outbox.add({ client_id: clientId, user_id: user!.id, body, label: { amount: value, currency, category: chips.find((c) => c.id === categoryId)?.label ?? null } })
      toast(t('savedOffline'))
      onClose()
    }
    try {
      if (!navigator.onLine) { queue(); return }
      await funds(() => post('/transactions', { ...body, client_id: clientId }))
      toast(t('saved'))
      refresh()
      onClose()
    } catch (err) {
      const short = shortfallOf(err)
      if (isNetworkError(err)) queue()
      else if (short) {
        setError(t('fundsField', { n: i.money(short.shortfall, short.currency) }))
        document.getElementById('quick-account')?.focus()
      }
      else setError(err instanceof ApiError ? Object.values(err.fieldErrors)[0] ?? t('error') : t('error'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="overlay" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <form className="modal quick" role="dialog" aria-modal="true" aria-labelledby="quick-title" onSubmit={save} noValidate>
        <div className="modal-h">
          <h2 id="quick-title">{t('quickExpense')}</h2>
          <div className="seg" role="radiogroup" aria-label={t('type')}>
            {(['expense', 'income'] as const).map((k) => (
              <button type="button" key={k} role="radio" aria-checked={type === k} className={type === k ? 'on' : ''} onClick={() => setType(k)}>{t(k)}</button>
            ))}
          </div>
          <button type="button" className="icon-btn" onClick={onClose} aria-label={t('close')}><Icon name="close" /></button>
        </div>
        <div className="quick-b">
          <div className="amount-row">
            <input id="quick-amount" aria-label={t('amount')} ref={amountRef} className="inp amount num" inputMode="decimal" autoComplete="off"
              placeholder="0" value={amount} onChange={(e) => { setAmount(e.target.value); setError(null) }} />
            <span className="cur">{currency}</span>
          </div>
          <FitChips chips={chips} value={categoryId} onChange={setCategoryId} label={t('category')} />
          <div className="field">
            <label htmlFor="quick-account">{t('account')}</label>
            <select id="quick-account" className="inp" value={accountId ?? ''} onChange={(e) => { setAccountId(Number(e.target.value)); setError(null) }}>
              {accounts.data?.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
            </select>
          </div>
          {error && <span className="err" role="alert">{error}</span>}
        </div>
        <div className="modal-f">
          <button type="button" className="btn ghost" onClick={onDetails}>{t('newTransaction')}</button>
          <span className="grow" />
          <button type="submit" className="btn" disabled={busy}>{t('save')}</button>
        </div>
      </form>
    </div>
  )
}
