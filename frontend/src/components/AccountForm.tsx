import { useState } from 'react'
import { useI18n } from '../i18n'
import { ApiError, del, patch, post } from '../lib/api'
import { useSession } from '../lib/session'
import { ACCOUNT_TYPES, type Account } from '../lib/types'
import { DeleteButton, FormModal, type Field } from './Modal'

const CURRENCIES = ['EGP', 'USD', 'EUR', 'SAR', 'AED', 'KWD', 'GBP']

export function AccountForm({ account, onClose }: { account?: Account | null; onClose: () => void }) {
  const i = useI18n()
  const { t } = i
  const { refresh, toast, readOnly } = useSession()
  const [f, setF] = useState({
    name: account?.name ?? '',
    type: account?.type ?? 'debit_card',
    provider: account?.provider ?? '',
    currency: account?.currency ?? 'EGP',
    balance: account ? String(account.balance) : '0',
    credit_limit: account?.credit_limit != null ? String(account.credit_limit) : '',
    due_day: account?.due_day != null ? String(account.due_day) : '',
    last4: account?.last4 ?? '',
    account_number: account?.account_number ?? '',
    notes: account?.notes ?? '',
    is_active: account?.is_active ?? true,
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value })
  const credit = f.type === 'credit_card'

  const run = async (fn: () => Promise<unknown>) => {
    setBusy(true)
    try {
      await fn()
      toast(t('saved'))
      refresh()
      onClose()
    } catch (err) {
      if (err instanceof ApiError && err.data.code === 'has_transactions') toast(t('hasTransactions'))
      else if (err instanceof ApiError) setErrors(err.fieldErrors)
      else toast(t('error'))
    } finally {
      setBusy(false)
    }
  }

  const submit = () => run(() => {
    const body = {
      ...f,
      balance: Number(f.balance) || 0,
      provider: f.provider || null,
      credit_limit: credit && f.credit_limit ? Number(f.credit_limit) : null,
      due_day: credit && f.due_day ? Number(f.due_day) : null,
      last4: f.last4 || null,
      account_number: f.account_number || null,
      notes: f.notes || null,
    }
    return account ? patch(`/accounts/${account.id}`, body) : post('/accounts', body)
  })

  const fields: Field[] = [
    { name: 'name', label: t('name'), control: <input id="f-name" className="inp" value={f.name} onChange={set('name')} required maxLength={120} /> },
    {
      name: 'type', label: t('type'),
      control: <select id="f-type" className="inp" value={f.type} onChange={set('type')}>{ACCOUNT_TYPES.map((k) => <option key={k} value={k}>{i.e(k)}</option>)}</select>,
    },
    { name: 'provider', label: t('provider'), control: <input id="f-provider" className="inp" value={f.provider} onChange={set('provider')} maxLength={120} /> },
    {
      name: 'currency', label: t('currency'),
      control: (
        <>
          <input id="f-currency" className="inp" list="currencies" value={f.currency} maxLength={3} dir="ltr"
            onChange={(e) => setF({ ...f, currency: e.target.value.toUpperCase() })} />
          <datalist id="currencies">{CURRENCIES.map((c) => <option key={c} value={c} />)}</datalist>
        </>
      ),
    },
    { name: 'balance', label: t('balance'), control: <input id="f-balance" className="inp num" inputMode="decimal" value={f.balance} onChange={set('balance')} /> },
    { name: 'credit_limit', label: t('creditLimit'), hidden: !credit, control: <input id="f-credit_limit" className="inp num" inputMode="decimal" value={f.credit_limit} onChange={set('credit_limit')} /> },
    { name: 'due_day', label: t('dueDay'), hidden: !credit, control: <input id="f-due_day" className="inp num" type="number" min={1} max={31} value={f.due_day} onChange={set('due_day')} /> },
    { name: 'last4', label: t('last4'), control: <input id="f-last4" className="inp num" inputMode="numeric" maxLength={4} dir="ltr" value={f.last4} onChange={set('last4')} /> },
    { name: 'account_number', label: t('accountNumber'), control: <input id="f-account_number" className="inp num" dir="ltr" autoComplete="off" value={f.account_number} onChange={set('account_number')} maxLength={64} /> },
    { name: 'notes', label: t('notes'), full: true, control: <input id="f-notes" className="inp" value={f.notes} onChange={set('notes')} maxLength={2000} /> },
    {
      name: 'is_active', label: t('status'),
      control: (
        <label className="check" htmlFor="f-is_active">
          <input id="f-is_active" type="checkbox" checked={f.is_active} onChange={(e) => setF({ ...f, is_active: e.target.checked })} />
          {t('active')}
        </label>
      ),
    },
  ]

  return (
    <FormModal title={account ? t('editAccount') : t('newAccount')} fields={fields} errors={errors} busy={busy || readOnly}
      onClose={onClose} onSubmit={submit}
      extra={account && !readOnly ? <DeleteButton busy={busy} onConfirm={() => run(() => del(`/accounts/${account.id}`))} /> : undefined} />
  )
}
