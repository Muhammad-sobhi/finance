import { useMemo, useState, type ReactNode } from 'react'
import { DetailModal, Facts } from '../components/DetailModal'
import { DueTag } from '../components/Due'
import { check, currency, date, money, select, text } from '../components/fields'
import { FitList } from '../components/FitList'
import { Icon } from '../components/Icon'
import { ListHead } from '../components/ListHead'
import { DeleteButton, FormModal } from '../components/Modal'
import { Paged } from '../components/Paged'
import { useApi } from '../hooks/useApi'
import { num, orNull, useForm } from '../hooks/useForm'
import { useI18n } from '../i18n'
import { del, patch, post } from '../lib/api'
import { isoDate, monthIndex, shiftMonth, thisMonth } from '../lib/dates'
import { catName } from '../lib/labels'
import { useSession } from '../lib/session'
import type { Account, Budget, Category, Goal, Listed, Zakat } from '../lib/types'
import { CatBadge } from './Categories'

type Tab = 'budgets' | 'goals' | 'zakat'

/** Planning: monthly budgets per category, savings goals and the yearly zakat. */
export function Planning() {
  const { t } = useI18n()
  const [tab, setTab] = useState<Tab>('budgets')
  return (
    <div className="list planning">
      <div className="list-head">
        <div className="seg" role="tablist" aria-label={t('planning')}>
          {(['budgets', 'goals', 'zakat'] as const).map((k) => (
            <button type="button" key={k} role="tab" aria-selected={tab === k} className={tab === k ? 'on' : ''} onClick={() => setTab(k)}>
              {k === 'zakat' ? t('zakatTab') : t(k)}
            </button>
          ))}
        </div>
      </div>
      <div className="tab-body">
        {tab === 'budgets' && <Budgets />}
        {tab === 'goals' && <Goals />}
        {tab === 'zakat' && <ZakatView />}
      </div>
    </div>
  )
}

/** A filled bar; `tone` follows the status (ok / near / over). */
function Meter({ pct, tone = 'ok' }: { pct: number; tone?: string }) {
  return (
    <div className={`meter ${tone}`} role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(Math.min(100, pct))}>
      <i style={{ width: `${Math.min(100, Math.max(0, pct))}%` }} />
    </div>
  )
}

function Budgets() {
  const i = useI18n()
  const { t, num: n, d } = i
  const { readOnly } = useSession()
  const [month, setMonth] = useState(thisMonth())
  const { data } = useApi<Listed<Budget, { base_currency: string; budgeted: number; spent: number; remaining: number }>>(`/budgets?month=${month}`)
  const cats = useApi<Category[]>('/categories?type=expense')
  const rows = useMemo(() => data?.data ?? [], [data])
  const [edit, setEdit] = useState<Budget | null | undefined>(undefined)
  const cat = (id: number) => cats.data?.find((c) => c.id === id)
  const base = data?.meta.base_currency ?? ''
  const [y] = month.split('-')
  const free = (cats.data ?? []).filter((c) => !rows.some((b) => b.category_id === c.id))

  return (
    <div className="list">
      <ListHead>
        <div className="month-nav">
          <button type="button" className="icon-btn" onClick={() => setMonth(shiftMonth(month, -1))} aria-label={t('prevMonth')}><Icon name="prev" className="flip" /></button>
          <span className="month-lbl">{`${d.months[monthIndex(month)]} ${n(Number(y)).replace(/[٬,]/g, '')}`}</span>
          <button type="button" className="icon-btn" onClick={() => setMonth(shiftMonth(month, 1))} aria-label={t('nextMonth')} disabled={month >= thisMonth()}><Icon name="next" className="flip" /></button>
        </div>
        <span className="count">
          {t('spent')}: <span className="num">{n(data?.meta.spent ?? 0)}</span> / <span className="num">{n(data?.meta.budgeted ?? 0)}</span> {base}
        </span>
        <span className="grow" />
        {!readOnly && free.length > 0 && <button type="button" className="btn" onClick={() => setEdit(null)}><Icon name="plus" /><span className="hide-sm">{t('add')}</span></button>}
      </ListHead>
      <Paged
        items={rows}
        resetKey={month}
        empty={<div className="empty"><Icon name="planning" /><p>{t('noBudgets')}</p></div>}
        container={(c) => <div className="grid">{c}</div>}
        renderItem={(b) => (
          <button type="button" key={b.id} className="card ic budget" onClick={() => !readOnly && setEdit(b)} disabled={readOnly}>
            <div className="t"><span className="cat-name"><CatBadge c={cat(b.category_id)} />{catName(i, cat(b.category_id))}</span><span className={`tag${b.status === 'ok' ? ' quiet' : b.status === 'over' ? ' late' : ''}`}>{t(`b_${b.status}`)}</span></div>
            <div className="amt"><span className={`num${b.status === 'over' ? ' neg' : ''}`}>{n(b.spent)}</span> <small className="cur-sm">/ {n(b.amount)} {base}</small></div>
            <Meter pct={b.pct} tone={b.status} />
            <div className="meta">
              <span>{t('remaining')}: <span className={`num${b.remaining < 0 ? ' neg' : ''}`}>{n(b.remaining)}</span></span>
              {b.per_day_left !== null && <span>{t('perDayLeft')}: <span className="num">{n(b.per_day_left)}</span></span>}
              {b.projected !== null && <span>{t('projectedEnd')}: <span className={`num${b.projected > b.amount ? ' neg' : ''}`}>{n(b.projected)}</span></span>}
            </div>
          </button>
        )}
      />
      {edit !== undefined && <BudgetForm budget={edit} cats={edit ? (cats.data ?? []) : free} onClose={() => setEdit(undefined)} />}
    </div>
  )
}

function BudgetForm({ budget, cats, onClose }: { budget: Budget | null; cats: Category[]; onClose: () => void }) {
  const i = useI18n()
  const { t } = i
  const form = useForm({ category_id: String(budget?.category_id ?? cats[0]?.id ?? ''), amount: budget ? String(budget.amount) : '' }, onClose)
  const submit = () => form.run(() => budget
    ? patch(`/budgets/${budget.id}`, { amount: num(form.f.amount) })
    : post('/budgets', { category_id: num(form.f.category_id), amount: num(form.f.amount) }))
  return (
    <FormModal title={budget ? t('editBudget') : t('newBudget')} errors={form.errors} busy={form.busy} onClose={onClose} onSubmit={submit}
      extra={budget ? <DeleteButton busy={form.busy} onConfirm={() => form.run(() => del(`/budgets/${budget.id}`))} /> : undefined}
      fields={[
        budget
          ? { name: 'category_id', label: t('category'), control: <input id="f-category_id" className="inp" value={catName(i, cats.find((c) => c.id === budget.category_id))} readOnly /> }
          : select(form, 'category_id', t('category'), cats.map((c) => [c.id, catName(i, c)])),
        money(form, 'amount', t('monthlyLimit')),
      ]} />
  )
}

function Goals() {
  const { t, num: n, date: fmtDate } = useI18n()
  const { readOnly } = useSession()
  const { data } = useApi<Listed<Goal, { base_currency: string }>>('/goals')
  const accounts = useApi<Account[]>('/accounts')
  const rows = useMemo(() => data?.data ?? [], [data])
  const [edit, setEdit] = useState<Goal | null | undefined>(undefined)
  const [view, setView] = useState<number | null>(null)

  const state = (g: Goal) => g.achieved
    ? <span className="tag">{t('achieved')}</span>
    : g.on_track === null ? null : <span className={`tag${g.on_track ? ' quiet' : ' late'}`}>{t(g.on_track ? 'onTrack' : 'behindPlan')}</span>

  return (
    <div className="list">
      <ListHead>
        <span className="count">{t('goals')}: <span className="num">{n(rows.length)}</span></span>
        <span className="grow" />
        {!readOnly && <button type="button" className="btn" onClick={() => setEdit(null)}><Icon name="plus" /><span className="hide-sm">{t('add')}</span></button>}
      </ListHead>
      <Paged
        items={rows}
        empty={<div className="empty"><Icon name="planning" /><p>{t('noGoals')}</p></div>}
        container={(c) => <div className="grid">{c}</div>}
        renderItem={(g) => (
          <button type="button" key={g.id} className={`card ic goal${g.achieved ? ' done' : ''}`} onClick={() => setView(g.id)}>
            <div className="t"><span>{g.name}</span>{state(g)}</div>
            <div className="amt"><span className="num">{n(g.saved)}</span> <small className="cur-sm">/ {n(g.target_amount)} {g.currency}</small></div>
            <Meter pct={g.pct} tone={g.achieved ? 'ok' : g.on_track === false ? 'near' : 'ok'} />
            <div className="meta">
              <span className="num">{n(g.pct, 1)}%</span>
              {g.monthly_needed !== null && <span>{t('monthlyNeeded')}: <span className="num">{n(g.monthly_needed)}</span></span>}
              {g.target_date && <span>{fmtDate(g.target_date)}</span>}
            </div>
          </button>
        )}
      />
      {view !== null && <GoalDetail id={view} accounts={accounts.data ?? []} onClose={() => setView(null)} onEdit={(g) => { setView(null); setEdit(g) }} />}
      {edit !== undefined && <GoalForm goal={edit} accounts={accounts.data ?? []} onClose={() => setEdit(undefined)} />}
    </div>
  )
}

function GoalDetail({ id, accounts, onClose, onEdit }: { id: number; accounts: Account[]; onClose: () => void; onEdit: (g: Goal) => void }) {
  const { t, num: n, date: fmtDate } = useI18n()
  const { readOnly, refresh, toast } = useSession()
  const { data: g } = useApi<Goal>(`/goals/${id}`)
  const [adding, setAdding] = useState(false)
  const list = useMemo(() => g?.contributions ?? [], [g])
  const account = accounts.find((a) => a.id === g?.account_id)

  return (
    <DetailModal title={g?.name ?? t('loading')} onClose={onClose}
      footer={!readOnly && g ? (
        <>
          <button type="button" className="btn ghost" onClick={() => onEdit(g)}>{t('edit')}</button>
          <span className="grow" />
          {!g.account_id && <button type="button" className="btn" onClick={() => setAdding(true)}>{t('addContribution')}</button>}
        </>
      ) : undefined}>
      {g && (
        <>
          <Facts items={[
            [t('target'), <span className="num">{n(g.target_amount, 2)} {g.currency}</span>],
            [t('savedSoFar'), <span className="num pos">{n(g.saved, 2)}</span>],
            [t('remaining'), <span className="num">{n(g.remaining, 2)}</span>],
            [t('targetDate'), g.target_date ? fmtDate(g.target_date) : '—'],
            [t('monthlyNeeded'), g.monthly_needed !== null ? <span className="num">{n(g.monthly_needed, 2)}</span> : '—'],
            [t('goalAccount'), account ? account.name : t('manualTracking')],
          ]} />
          {g.account_id ? <p className="muted none">{t('followsAccount')}</p> : (
            <>
              <h3 className="sub-h">{t('contributions')}</h3>
              <Paged items={list} resetKey={String(id)}
                empty={<p className="muted none">{t('empty')}</p>}
                container={(c) => <div className="rows">{c}</div>}
                renderItem={(x) => (
                  <div className="li" key={x.id}>
                    <span>{fmtDate(x.date)}{x.note ? ` · ${x.note}` : ''}</span>
                    <span>
                      <b className={`num${x.amount < 0 ? ' neg' : ''}`}>{n(x.amount, 2)}</b>
                      {!readOnly && (
                        <button type="button" className="icon-btn sm" aria-label={t('delete')}
                          onClick={() => del(`/goals/${id}/contributions/${x.id}`).then(() => { toast(t('saved')); refresh() }).catch(() => toast(t('error')))}>
                          <Icon name="close" />
                        </button>
                      )}
                    </span>
                  </div>
                )} />
            </>
          )}
        </>
      )}
      {adding && g && <ContributionForm goal={g} onClose={() => setAdding(false)} />}
    </DetailModal>
  )
}

function ContributionForm({ goal, onClose }: { goal: Goal; onClose: () => void }) {
  const { t } = useI18n()
  const form = useForm({ amount: goal.monthly_needed ? String(goal.monthly_needed) : '', date: isoDate(), note: '' }, onClose)
  const submit = () => form.run(() => post(`/goals/${goal.id}/contributions`, { amount: num(form.f.amount), date: form.f.date, note: orNull(form.f.note) }))
  return (
    <FormModal title={`${t('addContribution')} — ${goal.name}`} errors={form.errors} busy={form.busy} onClose={onClose} onSubmit={submit} fields={[
      money(form, 'amount', `${t('amount')} (${goal.currency})`, { placeholder: t('withdrawHint') }),
      date(form, 'date', t('date')),
      text(form, 'note', t('note'), { full: true, maxLength: 300 }),
    ]} />
  )
}

function GoalForm({ goal, accounts, onClose }: { goal: Goal | null; accounts: Account[]; onClose: () => void }) {
  const { t } = useI18n()
  const form = useForm({
    name: goal?.name ?? '', target_amount: goal ? String(goal.target_amount) : '', currency: goal?.currency ?? 'EGP',
    target_date: goal?.target_date ?? '', account_id: goal?.account_id ? String(goal.account_id) : '', notes: goal?.notes ?? '',
  }, onClose)
  const f = form.f
  const submit = () => form.run(() => {
    const body = { name: f.name, target_amount: num(f.target_amount), currency: f.currency, target_date: orNull(f.target_date), account_id: num(f.account_id), notes: orNull(f.notes) }
    return goal ? patch(`/goals/${goal.id}`, body) : post('/goals', body)
  })
  return (
    <FormModal title={goal ? t('editGoal') : t('newGoal')} errors={form.errors} busy={form.busy} onClose={onClose} onSubmit={submit}
      extra={goal ? <DeleteButton busy={form.busy} onConfirm={() => form.run(() => del(`/goals/${goal.id}`))} /> : undefined}
      fields={[
        text(form, 'name', t('name'), { maxLength: 120 }),
        money(form, 'target_amount', t('target')),
        currency(form, 'currency', t('currency')),
        date(form, 'target_date', t('targetDate')),
        select(form, 'account_id', t('goalAccount'), accounts.filter((a) => a.is_active).map((a) => [a.id, `${a.name} · ${a.currency}`]), { empty: t('manualTracking'), full: true }),
        text(form, 'notes', t('notes'), { full: true, maxLength: 2000 }),
      ]} />
  )
}

const PARTS = ['cash', 'metals', 'other_assets', 'investments', 'owed_to_me', 'i_owe', 'installments', 'overdrawn'] as const
const MINUS = new Set(['i_owe', 'installments', 'overdrawn'])

function ZakatView() {
  const i = useI18n()
  const { t, num: n, lang } = i
  const { readOnly, refresh, toast } = useSession()
  const { data: z } = useApi<Zakat>('/zakat')
  const accounts = useApi<Account[]>('/accounts')
  const [form, setForm] = useState<'pay' | 'settings' | null>(null)
  const [all, setAll] = useState(false)
  const base = z?.base_currency ?? ''
  const parts = useMemo(() => (z ? PARTS.filter((k) => z.parts[k] > 0) : []), [z])
  // The zakat day in both calendars: the hijri date is what most people know it by.
  const hijri = (s: string) => new Intl.DateTimeFormat(lang === 'ar' ? 'ar-SA-u-ca-islamic-umalqura' : 'en-u-ca-islamic-umalqura', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(s + 'T00:00:00'))

  return (
    <div className="zakat">
      <div className="card kpi z-due">
        <div className="lbl">{t('zakatDue')}</div>
        <div className={`v num${z && z.due > 0 ? ' pos' : ''}`}>{z ? n(z.due, 2) : '…'}</div>
        <div className="d muted">{base} · {z ? `${n(z.rate * 100, 3)}%` : ''}</div>
        {!readOnly && <div className="acts">
          <button type="button" className="btn ghost" onClick={() => setForm('settings')}>{t('zakatSettings')}</button>
          <button type="button" className="btn" onClick={() => setForm('pay')} disabled={!z}>{t('payZakat')}</button>
        </div>}
      </div>
      <div className="card kpi">
        <div className="lbl">{t('nisab')} · {z ? t(z.settings.nisab === 'gold' ? 'nisabGold' : 'nisabSilver') : ''}</div>
        <div className="v num">{z ? n(z.nisab) : '…'}</div>
        <div className="d">{z && (z.price_missing ? <span className="neg">{t('priceMissing')}</span> : <span className={`tag${z.reached ? '' : ' quiet'}`}>{t(z.reached ? 'reached' : 'notReached')}</span>)}</div>
      </div>
      <div className="card kpi">
        <div className="lbl">{t('zakatDate')}</div>
        {z?.settings.date ? (
          <>
            <div className="v z-date">{hijri(z.settings.date)}</div>
            <div className="d"><span className="muted">{i.date(z.settings.date)}</span> <DueTag days={z.days_left} /></div>
          </>
        ) : <div className="d muted">{t('noZakatDate')}</div>}
      </div>
      <div className="card z-parts">
        <h3>{t('zakatBase')}<small>{base}</small><button type="button" className="more" onClick={() => setAll(true)}>{t('lastPayments')}</button></h3>
        <FitList items={parts} onMore={() => setAll(true)} className="fit rows"
          renderItem={(k) => (
            <div className="li" key={k}><span>{t(`z_${k}`)}</span><b className={`num${MINUS.has(k) ? ' neg' : ''}`}>{MINUS.has(k) ? '−' : ''}{n(z!.parts[k])}</b></div>
          )} />
        <div className="li total"><span>{t('zakatBase')}</span><b className="num">{z ? n(z.net) : '…'}</b></div>
        <p className="muted z-note">{t('zakatNote')}</p>
      </div>
      {all && z && (
        <DetailModal title={t('zakatBase')} onClose={() => setAll(false)}>
          <Facts items={[...parts.map((k) => [t(`z_${k}`), <span className={`num${MINUS.has(k) ? ' neg' : ''}`}>{n(z.parts[k])}</span>] as [string, ReactNode]), [t('zakatBase'), <b className="num">{n(z.net)}</b>]]} />
          <h3 className="sub-h">{t('lastPayments')}</h3>
          <Paged items={z.payments} empty={<p className="muted none">{t('empty')}</p>} container={(c) => <div className="rows">{c}</div>}
            renderItem={(p) => (
              <div className="li" key={p.id}>
                <span>{i.date(p.date)}{p.note ? ` · ${p.note}` : ''}</span>
                <span>
                  <b className="num">{n(p.amount, 2)}</b>
                  {!readOnly && (
                    <button type="button" className="icon-btn sm" aria-label={t('delete')}
                      onClick={() => del(`/zakat/payments/${p.id}`).then(() => { toast(t('saved')); refresh() }).catch(() => toast(t('error')))}>
                      <Icon name="close" />
                    </button>
                  )}
                </span>
              </div>
            )} />
        </DetailModal>
      )}
      {form === 'pay' && z && <ZakatPayForm z={z} accounts={accounts.data ?? []} onClose={() => setForm(null)} />}
      {form === 'settings' && z && <ZakatSettingsForm z={z} onClose={() => setForm(null)} />}
    </div>
  )
}

function ZakatPayForm({ z, accounts, onClose }: { z: Zakat; accounts: Account[]; onClose: () => void }) {
  const { t } = useI18n()
  const active = accounts.filter((a) => a.is_active)
  const form = useForm({ amount: z.due > 0 ? String(z.due) : '', date: isoDate(), account_id: String(active[0]?.id ?? ''), note: '', complete: true }, onClose)
  const submit = () => form.run(() => post('/zakat/pay', {
    amount: num(form.f.amount), date: form.f.date, account_id: num(form.f.account_id), note: orNull(form.f.note), complete: form.f.complete,
  }))
  return (
    <FormModal title={t('payZakat')} errors={form.errors} busy={form.busy} onClose={onClose} onSubmit={submit} fields={[
      money(form, 'amount', `${t('amount')} (${z.base_currency})`),
      date(form, 'date', t('date')),
      select(form, 'account_id', t('account'), active.map((a) => [a.id, `${a.name} · ${a.currency}`]), { full: true }),
      text(form, 'note', t('note'), { full: true, maxLength: 300 }),
      check(form, 'complete', t('zakatDate'), t('zakatComplete'), { full: true }),
    ]} />
  )
}

function ZakatSettingsForm({ z, onClose }: { z: Zakat; onClose: () => void }) {
  const { t } = useI18n()
  const s = z.settings
  const form = useForm({ nisab: s.nisab, calendar: s.calendar, date: s.date ?? '', deduct_debts: s.deduct_debts }, onClose)
  const submit = () => form.run(() => patch('/zakat/settings', { nisab: form.f.nisab, calendar: form.f.calendar, date: orNull(form.f.date), deduct_debts: form.f.deduct_debts }))
  return (
    <FormModal title={t('zakatSettings')} errors={form.errors} busy={form.busy} onClose={onClose} onSubmit={submit} fields={[
      select(form, 'nisab', t('nisabBasis'), [['gold', t('nisabGold')], ['silver', t('nisabSilver')]]),
      select(form, 'calendar', t('calendarType'), [['hijri', t('hijri')], ['gregorian', t('gregorian')]]),
      date(form, 'date', t('zakatDate')),
      check(form, 'deduct_debts', t('deductDebts'), t('deductDebtsHint')),
    ]} />
  )
}
