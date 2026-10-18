import { createContext, useCallback, useContext, useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { useI18n } from '../i18n'
import { useApi } from '../hooks/useApi'
import { num } from '../hooks/useForm'
import { ApiError, post } from '../lib/api'
import { useSession } from '../lib/session'
import type { Account } from '../lib/types'
import { Icon } from './Icon'

/** What the server sends with `code: 'insufficient_funds'` (see InsufficientFunds.php). */
export interface Shortfall {
  account_id: number
  account: string
  currency: string
  available: number
  shortfall: number
}

export const shortfallOf = (err: unknown): Shortfall | null =>
  err instanceof ApiError && err.data.code === 'insufficient_funds' ? ((err.data as { funds?: Shortfall }).funds ?? null) : null

type Guard = <T>(fn: () => Promise<T>) => Promise<T>

const Ctx = createContext<Guard>((fn) => fn())

/**
 * Wrap a payment: if the account is short, ask the user to top it up (then the payment is
 * retried) or to choose another account (the original error is rethrown, so the form marks
 * its account field).
 */
export const useFunds = () => useContext(Ctx)

export function FundsProvider({ children }: { children: ReactNode }) {
  const [ask, setAsk] = useState<{ s: Shortfall; done: (toppedUp: boolean) => void } | null>(null)

  const guard = useCallback<Guard>(async (fn) => {
    for (;;) {
      try {
        return await fn()
      } catch (err) {
        const s = shortfallOf(err)
        if (!s) throw err
        const toppedUp = await new Promise<boolean>((done) => setAsk({ s, done }))
        setAsk(null)
        if (!toppedUp) throw err
      }
    }
  }, [])

  return (
    <Ctx.Provider value={guard}>
      {children}
      {ask && <FundsDialog s={ask.s} onDone={ask.done} />}
    </Ctx.Provider>
  )
}

const DEPOSIT = 'deposit'

function FundsDialog({ s, onDone }: { s: Shortfall; onDone: (toppedUp: boolean) => void }) {
  const i = useI18n()
  const { t } = i
  const { refresh } = useSession()
  const accounts = useApi<Account[]>('/accounts?active_only=1')
  const others = (accounts.data ?? []).filter((a) => a.id !== s.account_id)
  const [source, setSource] = useState<string>(DEPOSIT)
  const [amount, setAmount] = useState(String(s.shortfall))
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const amountRef = useRef<HTMLInputElement>(null)

  const from = others.find((a) => String(a.id) === source)
  // Amounts are typed in the paying side's currency; the server converts a cross-currency transfer.
  const currency = from?.currency ?? s.currency

  // Default to the other account holding the most of the same currency, if one can cover it.
  useEffect(() => {
    const best = others.filter((a) => a.currency === s.currency && a.type !== 'credit_card' && a.balance >= s.shortfall)
      .sort((a, b) => b.balance - a.balance)[0]
    if (best) setSource(String(best.id))
    // Only once the list arrives.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [accounts.data])

  useEffect(() => {
    // Capture phase: Escape closes only this dialog, not the form underneath.
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== 'Escape') return
      e.stopPropagation()
      onDone(false)
    }
    window.addEventListener('keydown', onKey, true)
    return () => window.removeEventListener('keydown', onKey, true)
  }, [onDone])

  const pick = (v: string) => {
    setSource(v)
    setError(null)
    const a = others.find((x) => String(x.id) === v)
    setAmount(!a || a.currency === s.currency ? String(s.shortfall) : '')
  }

  const topUp = async (e: FormEvent) => {
    e.preventDefault()
    e.stopPropagation()
    const value = num(amount)
    if (!value || value <= 0) { setError(t('amount')); amountRef.current?.focus(); return }
    setBusy(true)
    setError(null)
    try {
      await post('/transactions', from
        ? { type: 'transfer', account_id: from.id, to_account_id: s.account_id, amount: value, note: t('topUp') }
        : { type: 'income', account_id: s.account_id, amount: value, category_id: null, note: t('topUp') })
      refresh()
      onDone(true)
    } catch (err) {
      const short = shortfallOf(err)
      setError(short ? t('fundsSourceShort', { account: short.account, n: i.money(short.shortfall, short.currency) })
        : err instanceof ApiError ? Object.values(err.fieldErrors)[0] ?? err.message : t('error'))
    } finally {
      setBusy(false)
    }
  }

  return createPortal(
    <div className="overlay funds" onMouseDown={(e) => e.target === e.currentTarget && onDone(false)}>
      <form className="modal funds-modal" role="alertdialog" aria-modal="true" aria-labelledby="funds-title" aria-describedby="funds-text" onSubmit={topUp} noValidate>
        <div className="modal-h">
          <h2 id="funds-title">{t('fundsTitle')}</h2>
          <button type="button" className="icon-btn" onClick={() => onDone(false)} aria-label={t('close')}><Icon name="close" /></button>
        </div>
        <div className="modal-b">
          <p id="funds-text" className="funds-text full">
            {t('fundsText', { account: s.account, available: i.money(s.available, s.currency), short: i.money(s.shortfall, s.currency) })}
          </p>
          <div className="field">
            <label htmlFor="funds-source">{t('topUpFrom')}</label>
            <select id="funds-source" className="inp" value={source} onChange={(e) => pick(e.target.value)}>
              <option value={DEPOSIT}>{t('topUpDeposit')}</option>
              {others.map((a) => <option key={a.id} value={a.id}>{a.name} · {i.money(a.balance, a.currency)}</option>)}
            </select>
          </div>
          <div className="field">
            <label htmlFor="funds-amount">{t('amount')} ({currency})</label>
            <input id="funds-amount" ref={amountRef} className="inp num" inputMode="decimal" autoComplete="off" value={amount}
              onChange={(e) => { setAmount(e.target.value); setError(null) }} />
          </div>
          {error && <span className="err full" role="alert">{error}</span>}
        </div>
        <div className="modal-f">
          <button type="button" className="btn ghost" onClick={() => onDone(false)}>{t('chooseOtherAccount')}</button>
          <span className="grow" />
          <button type="submit" className="btn" disabled={busy}>{t('topUpAndPay')}</button>
        </div>
      </form>
    </div>,
    document.body,
  )
}
