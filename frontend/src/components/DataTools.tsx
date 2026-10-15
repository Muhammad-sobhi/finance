import { useMemo, useState } from 'react'
import { useApi } from '../hooks/useApi'
import { useForm } from '../hooks/useForm'
import { useI18n } from '../i18n'
import { ApiError, fetchBlob, post, saveBlob } from '../lib/api'
import { guessColumns, parseAmount, parseCsv, parseDate, type DateFormat } from '../lib/csv'
import { isoDate } from '../lib/dates'
import { useSession } from '../lib/session'
import type { Account } from '../lib/types'
import { select, text } from './fields'
import { FormModal, type Field } from './Modal'

/** Settings card: export, encrypted backup, bank statement import. */
export function DataTools({ className = '' }: { className?: string }) {
  const { t } = useI18n()
  const { readOnly, toast } = useSession()
  const [open, setOpen] = useState<'backup' | 'import' | null>(null)

  const exportCsv = () => fetchBlob('/export/transactions')
    .then((b) => saveBlob(b, `transactions-${isoDate()}.csv`))
    .catch(() => toast(t('error')))

  return (
    <div className={`card data-card ${className}`}>
      <h3>{t('dataTools')}</h3>
      <div className="data-acts">
        <button type="button" className="btn ghost" onClick={exportCsv}>{t('exportCsv')}</button>
        {!readOnly && <button type="button" className="btn ghost" onClick={() => setOpen('import')}>{t('importBank')}</button>}
        {!readOnly && <button type="button" className="btn ghost" onClick={() => setOpen('backup')}>{t('backupNow')}</button>}
      </div>
      <p className="muted hint">{t('backupHint')}</p>
      {open === 'backup' && <BackupForm onClose={() => setOpen(null)} />}
      {open === 'import' && <ImportForm onClose={() => setOpen(null)} />}
    </div>
  )
}

function BackupForm({ onClose }: { onClose: () => void }) {
  const { t } = useI18n()
  const { toast } = useSession()
  const form = useForm({ current_password: '', passphrase: '', passphrase2: '' }, onClose)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const submit = async () => {
    if (form.f.passphrase !== form.f.passphrase2) { setErrors({ passphrase2: t('passMismatch') }); return }
    try {
      const blob = await fetchBlob('/backup', { method: 'POST', body: { current_password: form.f.current_password, passphrase: form.f.passphrase } })
      saveBlob(blob, `treasury-backup-${isoDate()}.json`)
      toast(t('backupSaved'))
      onClose()
    } catch (err) {
      if (err instanceof ApiError && err.data.errors) setErrors(err.fieldErrors)
      else toast(t('error'))
    }
  }
  return (
    <FormModal title={t('backupNow')} errors={{ ...form.errors, ...errors }} busy={form.busy} onClose={onClose} onSubmit={submit} submitLabel={t('download')} fields={[
      text(form, 'current_password', t('currentPassword'), { type: 'password', full: true }),
      text(form, 'passphrase', t('passphrase'), { type: 'password', placeholder: t('passphraseHint') }),
      text(form, 'passphrase2', t('confirmPassphrase'), { type: 'password' }),
      { name: 'note', label: t('important'), full: true, control: <p className="muted hint" id="f-note">{t('passphraseWarn')}</p> },
    ]} />
  )
}

type Stage = 'file' | 'map' | 'confirm'
interface Summary { rows: number; new: number; duplicates: number; categorized: number; imported?: number }

/**
 * Bank statement import in three steps: pick the CSV and account → match the columns
 * (we guess them) → see what's new vs already recorded → import.
 */
function ImportForm({ onClose }: { onClose: () => void }) {
  const { t, num } = useI18n()
  const { toast, refresh } = useSession()
  const accounts = useApi<Account[]>('/accounts')
  const active = (accounts.data ?? []).filter((a) => a.is_active)
  const [stage, setStage] = useState<Stage>('file')
  const [table, setTable] = useState<string[][]>([])
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [summary, setSummary] = useState<Summary | null>(null)
  const [busy, setBusy] = useState(false)
  const form = useForm({
    account_id: '', date: '0', note: '-1', amount: '-1', debit: '-1', credit: '-1', mode: 'signed', fmt: 'dmy' as DateFormat,
  }, onClose)
  const f = form.f
  const header = table[0] ?? []
  const cols: [string, string][] = header.map((h, n) => [String(n), h || `#${n + 1}`])

  const readFile = async (file: File | undefined) => {
    if (!file) return
    const rows = parseCsv(await file.text())
    if (rows.length < 2) { setErrors({ file: t('csvEmpty') }); return }
    const g = guessColumns(rows[0])
    const firstDate = rows[1][g.date] ?? ''
    form.setF((prev) => ({
      ...prev, date: String(Math.max(0, g.date)), note: String(g.note), amount: String(g.amount), debit: String(g.debit), credit: String(g.credit),
      mode: g.debit > -1 && g.credit > -1 && g.debit !== g.credit ? 'split' : 'signed',
      fmt: /^\d{4}/.test(firstDate) ? 'ymd' : 'dmy',
    }))
    setTable(rows)
    setErrors({})
  }

  // Rows as the API wants them; unreadable lines are counted, not sent.
  const parsed = useMemo(() => {
    const out: { date: string; amount: number; note: string | null }[] = []
    let bad = 0
    for (const r of table.slice(1)) {
      const date = parseDate(r[Number(f.date)] ?? '', f.fmt as DateFormat)
      let amount: number | null
      if (f.mode === 'split') {
        const debit = parseAmount(r[Number(f.debit)] ?? '') ?? 0
        const credit = parseAmount(r[Number(f.credit)] ?? '') ?? 0
        amount = credit !== 0 ? Math.abs(credit) : debit !== 0 ? -Math.abs(debit) : null
      } else {
        const a = parseAmount(r[Number(f.amount)] ?? '')
        amount = a === null ? null : f.mode === 'inverted' ? -a : a
      }
      if (!date || date > isoDate() || !amount) { bad++; continue }
      out.push({ date, amount: Math.round(amount * 100) / 100, note: (r[Number(f.note)] ?? '').slice(0, 255) || null })
    }
    return { rows: out, bad }
  }, [table, f.date, f.fmt, f.mode, f.amount, f.debit, f.credit, f.note])

  const send = async (dryRun: boolean) => {
    setBusy(true)
    try {
      const res = await post<Summary>('/import/transactions', { account_id: Number(f.account_id), rows: parsed.rows.slice(0, 1000), dry_run: dryRun })
      setSummary(res)
      if (dryRun) setStage('confirm')
      else { toast(t('importedN', { n: res.imported ?? 0 })); refresh(); onClose() }
    } catch (err) {
      if (err instanceof ApiError && err.data.errors) setErrors({ rows: Object.values(err.fieldErrors)[0] ?? t('error') })
      else toast(t('error'))
    } finally {
      setBusy(false)
    }
  }

  const submit = () => {
    if (stage === 'file') {
      if (!table.length) { setErrors({ file: t('chooseCsv') }); return }
      if (!f.account_id) { setErrors({ account_id: t('required') }); return }
      setStage('map')
    } else if (stage === 'map') {
      if (!parsed.rows.length) { setErrors({ rows: t('noValidRows') }); return }
      send(true)
    } else send(false)
  }

  const first = parsed.rows[0]
  const fields: Field[] = stage === 'file' ? [
    { name: 'file', label: t('csvFile'), full: true, control: <input id="f-file" className="inp" type="file" accept=".csv,text/csv" onChange={(e) => readFile(e.target.files?.[0])} /> },
    select(form, 'account_id', t('importInto'), active.map((a) => [a.id, `${a.name} · ${a.currency}`]), { full: true, empty: '—' }),
    { name: 'hint', label: t('important'), full: true, control: <p className="muted hint" id="f-hint">{t('importHint')}</p> },
  ] : stage === 'map' ? [
    select(form, 'date', t('date'), cols),
    select(form, 'fmt', t('dateFormat'), [['dmy', 'DD/MM/YYYY'], ['mdy', 'MM/DD/YYYY'], ['ymd', 'YYYY-MM-DD']]),
    select(form, 'mode', t('amountColumns'), [['signed', t('signedAmount')], ['inverted', t('invertedAmount')], ['split', t('debitCredit')]], { full: true }),
    select(form, 'amount', t('amount'), cols, { hidden: f.mode === 'split' }),
    select(form, 'debit', t('debitCol'), cols, { hidden: f.mode !== 'split', empty: '—' }),
    select(form, 'credit', t('creditCol'), cols, { hidden: f.mode !== 'split', empty: '—' }),
    select(form, 'note', t('description'), cols, { empty: '—' }),
    {
      name: 'rows', label: t('preview'), full: true,
      control: <p className="hint" id="f-rows">{t('rowsReady', { n: parsed.rows.length, bad: parsed.bad })}{first ? ` — ${first.date} · ${num(first.amount, 2)} · ${first.note ?? ''}` : ''}</p>,
    },
  ] : [
    {
      name: 'summary', label: t('preview'), full: true,
      control: summary && (
        <ul className="hint import-sum" id="f-summary">
          <li>{t('importNew', { n: summary.new })}</li>
          <li>{t('importDupes', { n: summary.duplicates })}</li>
          <li>{t('importCats', { n: summary.categorized })}</li>
        </ul>
      ),
    },
  ]

  return (
    <FormModal key={stage} title={`${t('importBank')} — ${t('step', { a: stage === 'file' ? 1 : stage === 'map' ? 2 : 3, b: 3 })}`}
      errors={{ ...form.errors, ...errors }} busy={busy || (stage === 'confirm' && !summary?.new)} onClose={onClose} onSubmit={submit}
      submitLabel={stage === 'confirm' ? t('importNow') : t('next')}
      extra={stage !== 'file' ? <button type="button" className="btn ghost" onClick={() => setStage(stage === 'confirm' ? 'map' : 'file')}>{t('back')}</button> : undefined}
      fields={fields} />
  )
}
