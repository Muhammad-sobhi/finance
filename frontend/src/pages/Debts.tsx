import { useMemo, useState } from 'react'
import { DetailModal, Facts } from '../components/DetailModal'
import { DueTag } from '../components/Due'
import { check, currency, date, money, select, text } from '../components/fields'
import { Icon } from '../components/Icon'
import { ListHead, ModeToggle, useListMode } from '../components/ListHead'
import { DeleteButton, FormModal } from '../components/Modal'
import { Paged } from '../components/Paged'
import { useApi } from '../hooks/useApi'
import { num, orNull, useForm } from '../hooks/useForm'
import { useI18n } from '../i18n'
import { ApiError, del, patch, post } from '../lib/api'
import { daysUntil, isoDate } from '../lib/dates'
import { useSession } from '../lib/session'
import type { Account, Debt, Listed } from '../lib/types'
import { Gameyas, Installments } from './Commitments'

type Meta = { totals: { owed_to_me: number; i_owe: number }; base_currency: string }

type Tab = 'debts' | 'installments' | 'gameyas'

type Kind = 'payment' | 'advance'

/** A line in a debt's history; `id: null` is the original loan itself. */
type Move = { id: number | null; kind: Kind; amount: number; date: string; account_id: number | null; note: string | null }

/** Debts hub: personal debts, installment plans (taqseet) and gameyas. */
export function Debts() {
  const { t } = useI18n()
  // Notifications about gameyas link to /debts?tab=gameyas.
  const [tab, setTab] = useState<Tab>(() => (new URLSearchParams(window.location.search).get('tab') === 'gameyas' ? 'gameyas' : 'debts'))
  return (
    <div className="list debts-page">
      <div className="list-head">
        <div className="seg" role="tablist" aria-label={t('debts')}>
          {(['debts', 'installments', 'gameyas'] as const).map((k) => (
            <button type="button" key={k} role="tab" aria-selected={tab === k} className={tab === k ? 'on' : ''} onClick={() => setTab(k)}>{t(k === 'debts' ? 'peopleDebts' : k)}</button>
          ))}
        </div>
      </div>
      <div className="tab-body">
        {tab === 'debts' && <DebtList />}
        {tab === 'installments' && <Installments />}
        {tab === 'gameyas' && <Gameyas />}
      </div>
    </div>
  )
}

function DebtList() {
  const i = useI18n()
  const { t, num: n, date: fmtDate } = i
  const { readOnly } = useSession()
  const mode = useListMode()
  const [filter, setFilter] = useState<'open' | 'settled' | ''>('open')
  const { data } = useApi<Listed<Debt, Meta>>(`/debts${filter ? `?status=${filter}` : ''}`)
  const accounts = useApi<Account[]>('/accounts')
  const rows = useMemo(() => data?.data ?? [], [data])
  const [edit, setEdit] = useState<Debt | null | undefined>(undefined)
  const [view, setView] = useState<number | null>(null)
  const base = data?.meta.base_currency ?? ''

  const amount = (d: Debt) => (
    <span className={`num ${d.direction === 'owed_to_me' ? 'pos' : 'neg'}`}>{n(d.remaining, 2)}{d.currency !== base ? ` ${d.currency}` : ''}</span>
  )
  const status = (d: Debt) => (d.status === 'settled' ? <span className="tag quiet">{t('settled')}</span> : <DueTag days={daysUntil(d.due_date)} />)

  return (
    <div className="list">
      <ListHead>
        <div className="seg" role="radiogroup" aria-label={t('status')}>
          {(['open', 'settled', ''] as const).map((k) => (
            <button type="button" key={k || 'all'} role="radio" aria-checked={filter === k} className={filter === k ? 'on' : ''} onClick={() => setFilter(k)}>{t(k || 'all')}</button>
          ))}
        </div>
        <ModeToggle />
        <span className="grow" />
        {!readOnly && <button type="button" className="btn" onClick={() => setEdit(null)}><Icon name="plus" /><span className="hide-sm">{t('add')}</span></button>}
      </ListHead>
      <div className="sumbar">
        <span>{t('owedToMe')}:</span><b className="num pos">{n(data?.meta.totals.owed_to_me ?? 0)}</b>
        <span>{t('iOwe')}:</span><b className="num neg">{n(data?.meta.totals.i_owe ?? 0)}</b>
        <span className="muted">{base}</span>
      </div>
      <Paged
        items={rows}
        resetKey={`${filter}|${mode}`}
        empty={<div className="empty"><Icon name="debts" /><p>{t('empty')}</p></div>}
        container={(c) => mode === 'table' ? (
          <div className="tbl"><table>
            <thead><tr><th>{t('person')}</th><th>{t('direction')}</th><th>{t('amount')}</th><th>{t('remaining')}</th><th>{t('dueDate')}</th><th>{t('status')}</th></tr></thead>
            <tbody>{c}</tbody>
          </table></div>
        ) : <div className="grid">{c}</div>}
        renderItem={(d) => mode === 'table' ? (
          <tr key={d.id} onClick={() => setView(d.id)} className={d.status === 'settled' ? 'off' : ''}>
            <td><button type="button" className="row-link" onClick={() => setView(d.id)}>{d.person}</button></td>
            <td>{t(d.direction)}</td>
            <td className="num">{n(d.total, 2)}</td>
            <td>{amount(d)}</td>
            <td>{d.due_date ? fmtDate(d.due_date) : '—'}</td>
            <td>{status(d)}</td>
          </tr>
        ) : (
          <button type="button" key={d.id} className={`card ic${d.status === 'settled' ? ' off' : ''}`} onClick={() => setView(d.id)}>
            <div className="t"><span>{d.person}</span>{status(d)}</div>
            <div className="amt">{amount(d)}</div>
            <div className="meta"><span>{t(d.direction)}</span><span>{t('paid')}: <span className="num">{n(d.paid)}</span> / <span className="num">{n(d.total)}</span></span></div>
          </button>
        )}
      />
      {view !== null && <DebtDetail id={view} accounts={accounts.data ?? []} onClose={() => setView(null)} onEdit={(d) => { setView(null); setEdit(d) }} />}
      {edit !== undefined && <DebtForm debt={edit} accounts={accounts.data ?? []} onClose={() => setEdit(undefined)} />}
    </div>
  )
}

function DebtDetail({ id, accounts, onClose, onEdit }: { id: number; accounts: Account[]; onClose: () => void; onEdit: (d: Debt) => void }) {
  const { t, num: n, date: fmtDate } = useI18n()
  const { readOnly, refresh, toast } = useSession()
  const { data: d } = useApi<Debt>(`/debts/${id}`)
  const [adding, setAdding] = useState<Kind | null>(null)
  // Newest first; the original loan is the last (oldest) line and can only be changed by editing the debt.
  const moves = useMemo<Move[]>(() => (d ? [
    ...(d.payments ?? []),
    { id: null, kind: 'advance', amount: d.amount, date: d.date, account_id: d.account_id, note: null },
  ] : []), [d])
  const accName = (aid: number | null) => accounts.find((a) => a.id === aid)?.name ?? '—'

  return (
    <DetailModal title={d ? d.person : t('loading')} onClose={onClose}
      footer={!readOnly && d ? (
        <>
          <button type="button" className="btn ghost" onClick={() => onEdit(d)}>{t('edit')}</button>
          <span className="grow" />
          <button type="button" className="btn ghost" onClick={() => setAdding('advance')}>{t('addAdvance')}</button>
          {d.status === 'open' && <button type="button" className="btn" onClick={() => setAdding('payment')}>{t('addPayment')}</button>}
        </>
      ) : undefined}>
      {d && (
        <>
          <Facts items={[
            [t('direction'), t(d.direction)],
            [t('totalLent'), <span className="num">{n(d.total, 2)} {d.currency}</span>],
            [t('paid'), <span className="num">{n(d.paid, 2)}</span>],
            [t('remaining'), <span className={`num ${d.direction === 'owed_to_me' ? 'pos' : 'neg'}`}>{n(d.remaining, 2)}</span>],
            [t('dueDate'), d.due_date ? fmtDate(d.due_date) : '—'],
            [t('status'), t(d.status)],
          ]} />
          <h3 className="sub-h">{t('movements')}</h3>
          <Paged items={moves} resetKey={String(id)}
            empty={<p className="muted none">{t('empty')}</p>}
            container={(c) => <div className="rows">{c}</div>}
            renderItem={(p) => (
              <div className="li" key={p.id ?? 'opening'}>
                <span><span className="tag quiet">{t(p.kind === 'advance' ? 'advance' : 'repayment')}</span> {fmtDate(p.date)} · {accName(p.account_id)}{p.note ? ` · ${p.note}` : ''}</span>
                <span>
                  <b className={`num ${(p.kind === 'advance') === (d.direction === 'owed_to_me') ? 'neg' : 'pos'}`}>{n(p.amount, 2)}</b>
                  {!readOnly && p.id !== null && (
                    <button type="button" className="icon-btn sm" aria-label={t('delete')}
                      onClick={() => del(`/debts/${id}/payments/${p.id}`).then(() => { toast(t('saved')); refresh() }).catch((e: unknown) => toast(e instanceof ApiError && e.status === 422 ? t('advanceRepaid') : t('error')))}>
                      <Icon name="close" />
                    </button>
                  )}
                </span>
              </div>
            )} />
        </>
      )}
      {adding && d && <PaymentForm debt={d} kind={adding} accounts={accounts} onClose={() => setAdding(null)} />}
    </DetailModal>
  )
}

function PaymentForm({ debt, kind, accounts, onClose }: { debt: Debt; kind: Kind; accounts: Account[]; onClose: () => void }) {
  const { t } = useI18n()
  const form = useForm({ amount: kind === 'payment' ? String(debt.remaining) : '', date: isoDate(), account_id: '', note: '' }, onClose)
  const submit = () => form.run(() => post(`/debts/${debt.id}/payments`, {
    kind, amount: num(form.f.amount), date: form.f.date, account_id: num(form.f.account_id), note: orNull(form.f.note),
  }))
  return (
    <FormModal title={`${t(kind === 'payment' ? 'addPayment' : 'addAdvance')} — ${debt.person}`} errors={form.errors} busy={form.busy} onClose={onClose} onSubmit={submit} fields={[
      money(form, 'amount', `${t('amount')} (${debt.currency})`),
      date(form, 'date', t('date')),
      select(form, 'account_id', t('moneyAccount'), accounts.filter((a) => a.is_active).map((a) => [a.id, a.name]), { empty: t('noAccount'), full: true }),
      text(form, 'note', t('note'), { full: true, maxLength: 500 }),
    ]} />
  )
}

function DebtForm({ debt, accounts, onClose }: { debt: Debt | null; accounts: Account[]; onClose: () => void }) {
  const { t } = useI18n()
  const { readOnly } = useSession()
  const form = useForm({
    person: debt?.person ?? '', phone: debt?.phone ?? '', direction: debt?.direction ?? 'owed_to_me',
    amount: debt ? String(debt.amount) : '', currency: debt?.currency ?? 'EGP', date: debt?.date ?? isoDate(),
    due_date: debt?.due_date ?? '', account_id: debt?.account_id ? String(debt.account_id) : '', notes: debt?.notes ?? '',
    remind_days: debt?.remind_days != null ? String(debt.remind_days) : '', settled: debt?.status === 'settled',
  }, onClose)
  const f = form.f
  const submit = () => form.run(() => {
    const body = {
      person: f.person, phone: orNull(f.phone), direction: f.direction, amount: num(f.amount), currency: f.currency, date: f.date,
      due_date: orNull(f.due_date), account_id: num(f.account_id), notes: orNull(f.notes), remind_days: num(f.remind_days),
      ...(debt ? { status: f.settled ? 'settled' : 'open' } : {}),
    }
    return debt ? patch(`/debts/${debt.id}`, body) : post('/debts', body)
  })
  return (
    <FormModal title={debt ? t('editDebt') : t('newDebt')} errors={form.errors} busy={form.busy || readOnly} onClose={onClose} onSubmit={submit}
      extra={debt && !readOnly ? <DeleteButton busy={form.busy} onConfirm={() => form.run(() => del(`/debts/${debt.id}`))} /> : undefined}
      fields={[
        text(form, 'person', t('person'), { maxLength: 120 }),
        select(form, 'direction', t('direction'), [['owed_to_me', t('owed_to_me')], ['i_owe', t('i_owe')]]),
        money(form, 'amount', t('amount')),
        currency(form, 'currency', t('currency')),
        date(form, 'date', t('date')),
        date(form, 'due_date', t('dueDate')),
        select(form, 'account_id', t('moneyAccount'), accounts.filter((a) => a.is_active || a.id === debt?.account_id).map((a) => [a.id, a.name]), { empty: t('noAccount') }),
        text(form, 'phone', t('phone'), { dir: 'ltr', inputMode: 'numeric', maxLength: 32 }),
        text(form, 'remind_days', t('remindDays'), { inputMode: 'numeric', num: true, placeholder: t('defaultHint') }),
        check(form, 'settled', t('status'), t('settled'), { hidden: !debt }),
        text(form, 'notes', t('notes'), { full: true, maxLength: 2000 }),
      ]} />
  )
}
