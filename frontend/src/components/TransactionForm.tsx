import { useState } from 'react'
import { useI18n } from '../i18n'
import { useApi } from '../hooks/useApi'
import { ApiError, del, fetchBlob, patch, post, upload } from '../lib/api'
import { shrinkImage } from '../lib/image'
import { isNetworkError, newClientId, outbox } from '../lib/offline'
import { keepAccounts, keepCategories } from '../lib/offlineLists'
import { isoDate } from '../lib/dates'
import { catName } from '../lib/labels'
import { useSession } from '../lib/session'
import type { Account, Category, Transaction, TxType } from '../lib/types'
import { DetailModal } from './DetailModal'
import { DeleteButton, FormModal, type Field } from './Modal'

export function TransactionForm({ tx, onClose }: { tx?: Transaction | null; onClose: () => void }) {
  const i = useI18n()
  const { t } = i
  const { refresh, toast, readOnly, user } = useSession()
  // New entries work offline too, so these lists are kept on this device.
  const accounts = useApi<Account[]>('/accounts', { offline: keepAccounts })
  const cats = useApi<Category[]>('/categories', { offline: keepCategories })
  const [f, setF] = useState({
    type: (tx?.type ?? 'expense') as TxType,
    amount: tx ? String(tx.amount) : '',
    account_id: tx?.account_id ?? 0,
    to_account_id: tx?.to_account_id ?? 0,
    to_amount: tx?.to_amount != null ? String(tx.to_amount) : '',
    fee: tx?.fee != null ? String(tx.fee) : '',
    category_id: tx?.category_id ?? 0,
    date: tx?.date ?? isoDate(),
    note: tx?.note ?? '',
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)
  const [hasReceipt, setHasReceipt] = useState(!!tx?.has_receipt)
  const [receiptUrl, setReceiptUrl] = useState<string | null>(null)

  const attach = async (file: File | undefined) => {
    if (!file || !tx) return
    setBusy(true)
    try {
      const blob = await shrinkImage(file)
      await upload(`/transactions/${tx.id}/receipt`, blob, blob === file ? file.name : 'receipt.jpg')
      setHasReceipt(true)
      toast(t('saved'))
      refresh()
    } catch (err) {
      toast(err instanceof ApiError && err.data.errors?.file ? err.data.errors.file[0] : t('error'))
    } finally {
      setBusy(false)
    }
  }
  const viewReceipt = async () => {
    if (!tx) return
    try {
      const blob = await fetchBlob(`/transactions/${tx.id}/receipt`)
      const url = URL.createObjectURL(blob)
      if (blob.type === 'application/pdf') window.open(url, '_blank', 'noopener')
      else setReceiptUrl(url)
    } catch {
      toast(t('error'))
    }
  }
  const removeReceipt = async () => {
    if (!tx) return
    await del(`/transactions/${tx.id}/receipt`).then(() => { setHasReceipt(false); toast(t('saved')); refresh() }).catch(() => toast(t('error')))
  }
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value })

  const acc = accounts.data ?? []
  const from = acc.find((a) => a.id === Number(f.account_id)) ?? acc[0]
  const to = acc.find((a) => a.id === Number(f.to_account_id))
  const transfer = f.type === 'transfer'
  const crossCurrency = transfer && !!from && !!to && from.currency !== to.currency

  /** `offline`: what to do instead when there is no connection (new entries only). */
  const run = async (fn: () => Promise<unknown>, offline?: () => void) => {
    setBusy(true)
    try {
      await fn()
      toast(t('saved'))
      refresh()
      onClose()
    } catch (err) {
      if (isNetworkError(err)) {
        if (offline) offline()
        else toast(t('needsConnection'))
      } else if (err instanceof ApiError && err.data.code === 'managed_by_debt') toast(t('managedByDebt'))
      else if (err instanceof ApiError && err.data.code === 'managed_by_project') toast(t('managedByProject'))
      else if (err instanceof ApiError && err.data.code === 'managed_by_zakat') toast(t('managedByZakat'))
      else if (err instanceof ApiError && err.data.code === 'managed_by_installment') toast(t('managedByInstallment'))
      else if (err instanceof ApiError && err.data.code === 'managed_by_gameya') toast(t('managedByGameya'))
      else if (err instanceof ApiError && err.data.code === 'managed_by_transfer') toast(t('managedByTransfer'))
      else if (err instanceof ApiError) setErrors(err.fieldErrors)
      else toast(t('error'))
    } finally {
      setBusy(false)
    }
  }

  const submit = () => {
    const body = {
      type: f.type,
      amount: Number(f.amount),
      account_id: Number(f.account_id) || from?.id,
      to_account_id: transfer ? Number(f.to_account_id) || null : null,
      to_amount: crossCurrency && f.to_amount ? Number(f.to_amount) : null,
      fee: transfer && f.fee ? Number(f.fee) : null,
      category_id: transfer ? null : Number(f.category_id) || null,
      date: f.date,
      note: f.note || null,
    }
    if (tx) return run(() => patch(`/transactions/${tx.id}`, body))

    // A new entry without a connection waits on this device and is sent when it's back.
    const clientId = newClientId()
    const queue = () => {
      const category = cats.data?.find((c) => c.id === body.category_id)
      outbox.add({ client_id: clientId, user_id: user!.id, body, label: { amount: body.amount, currency: from?.currency ?? '', category: category ? catName(i, category) : null } })
      toast(t('savedOffline'))
      onClose()
    }
    if (!navigator.onLine) {
      // The server can't check it now; catch the obvious mistakes here.
      const missing = !(body.amount > 0) ? 'amount' : !body.account_id ? 'account_id' : transfer && !body.to_account_id ? 'to_account_id' : null
      if (missing) { setErrors({ [missing]: t(missing === 'amount' ? 'amount' : missing === 'account_id' ? 'account' : 'toAccount') }); return }
      queue()
      return
    }
    return run(() => post('/transactions', { ...body, client_id: clientId }), queue)
  }

  const accountOptions = acc.filter((a) => a.is_active || a.id === tx?.account_id || a.id === tx?.to_account_id)
    .map((a) => <option key={a.id} value={a.id}>{a.name} · {a.currency}</option>)

  const fields: Field[] = [
    {
      name: 'type', label: t('type'), full: true,
      control: (
        <div className="seg" id="f-type" role="radiogroup">
          {(['expense', 'income', 'transfer'] as const).map((k) => (
            <button type="button" key={k} role="radio" aria-checked={f.type === k} className={f.type === k ? 'on' : ''}
              onClick={() => setF({ ...f, type: k, category_id: 0 })}>{t(k)}</button>
          ))}
        </div>
      ),
    },
    { name: 'amount', label: `${t('amount')} ${from ? `(${from.currency})` : ''}`, control: <input id="f-amount" className="inp num" inputMode="decimal" value={f.amount} onChange={set('amount')} required /> },
    { name: 'date', label: t('date'), control: <input id="f-date" className="inp" type="date" value={f.date} onChange={set('date')} required /> },
    { name: 'account_id', label: t('account'), control: <select id="f-account_id" className="inp" value={f.account_id || from?.id || ''} onChange={set('account_id')}>{accountOptions}</select> },
    {
      name: 'to_account_id', label: t('toAccount'), hidden: !transfer,
      control: <select id="f-to_account_id" className="inp" value={f.to_account_id || ''} onChange={set('to_account_id')}><option value="">—</option>{accountOptions}</select>,
    },
    {
      name: 'to_amount', label: `${t('received')} ${to ? `(${to.currency})` : ''}`, hidden: !crossCurrency,
      control: <input id="f-to_amount" className="inp num" inputMode="decimal" value={f.to_amount} onChange={set('to_amount')} />,
    },
    {
      name: 'fee', label: `${t('transferFee')} ${from ? `(${from.currency})` : ''}`, hidden: !transfer,
      control: <input id="f-fee" className="inp num" inputMode="decimal" value={f.fee} onChange={set('fee')} placeholder="0" />,
    },
    {
      name: 'category_id', label: t('category'), hidden: transfer,
      control: (
        <select id="f-category_id" className="inp" value={f.category_id || ''} onChange={set('category_id')}>
          <option value="">{t('noCategory')}</option>
          {(cats.data ?? []).filter((c) => c.type === f.type).map((c) => <option key={c.id} value={c.id}>{catName(i, c)}</option>)}
        </select>
      ),
    },
    { name: 'note', label: t('note'), full: true, control: <input id="f-note" className="inp" value={f.note} onChange={set('note')} maxLength={500} /> },
    {
      name: 'receipt', label: t('receipt'), full: true, hidden: !tx || (readOnly && !hasReceipt),
      control: hasReceipt ? (
        <div className="receipt-ctl" id="f-receipt">
          <button type="button" className="btn ghost sm" onClick={viewReceipt}>{t('viewReceipt')}</button>
          {!readOnly && <button type="button" className="btn ghost sm" onClick={removeReceipt}>{t('removeReceipt')}</button>}
        </div>
      ) : (
        <input id="f-receipt" className="inp" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" disabled={busy}
          onChange={(e) => attach(e.target.files?.[0])} />
      ),
    },
  ]

  return (
    <>
      <FormModal
        title={tx ? t('editTransaction') : t('newTransaction')}
        fields={fields} errors={errors} busy={busy || readOnly} onClose={onClose} onSubmit={submit}
        extra={tx && !readOnly ? <DeleteButton busy={busy} onConfirm={() => run(() => del(`/transactions/${tx.id}`))} /> : undefined}
      />
      {receiptUrl && (
        <DetailModal title={t('receipt')} onClose={() => { URL.revokeObjectURL(receiptUrl); setReceiptUrl(null) }}>
          <img className="receipt-img" src={receiptUrl} alt={t('receipt')} />
        </DetailModal>
      )}
    </>
  )
}
