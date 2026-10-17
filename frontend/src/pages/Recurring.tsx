import { useMemo, useState } from 'react'
import { DueTag } from '../components/Due'
import { check, currency, date, money, select, text } from '../components/fields'
import { Icon } from '../components/Icon'
import { ListHead, ModeToggle, useListMode } from '../components/ListHead'
import { DeleteButton, FormModal } from '../components/Modal'
import { Paged } from '../components/Paged'
import { useApi } from '../hooks/useApi'
import { num, orNull, useForm } from '../hooks/useForm'
import { useI18n } from '../i18n'
import { del, patch, post } from '../lib/api'
import { isoDate } from '../lib/dates'
import { catName } from '../lib/labels'
import { useSession } from '../lib/session'
import { CYCLES, type Account, type Bill, type Category, type IncomeSource, type Listed, type Subscription } from '../lib/types'

type Kind = 'bills' | 'subscriptions' | 'income-sources'
type Item = Bill | Subscription | IncomeSource
type SubMeta = { monthly: { personal: number; work: number; total: number }; base_currency: string }

/** What differs between the three recurring kinds. */
const KIND = {
  bills: { due: 'due_date', dueLabel: 'dueDate', pay: 'markPaid', payPath: 'pay', catType: 'expense', account: 'fromAccount', auto: 'autoPay', new: 'newBill', edit: 'editBill', icon: 'bills' },
  subscriptions: { due: 'renew_date', dueLabel: 'renewDate', pay: 'markPaid', payPath: 'pay', catType: 'expense', account: 'fromAccount', auto: 'autoPay', new: 'newSub', edit: 'editSub', icon: 'subscriptions' },
  'income-sources': { due: 'next_date', dueLabel: 'nextDate', pay: 'markReceived', payPath: 'receive', catType: 'income', account: 'toAccountIn', auto: 'autoRecord', new: 'newIncome', edit: 'editIncome', icon: 'income' },
} as const

const dueOf = (it: Item) => ('due_date' in it ? it.due_date : 'renew_date' in it ? it.renew_date : it.next_date)

/** Bills, subscriptions and recurring income: same list, form and "mark paid" / "received". */
export function Recurring({ kind }: { kind: Kind }) {
  const i = useI18n()
  const { t, num: n, date: fmtDate } = i
  const { readOnly } = useSession()
  const mode = useListMode()
  const { data } = useApi<Listed<Item, SubMeta>>(`/${kind}`)
  const accounts = useApi<Account[]>('/accounts')
  const k = KIND[kind]
  const cats = useApi<Category[]>(`/categories?type=${k.catType}`)
  const rows = useMemo(() => data?.data ?? [], [data])
  const [edit, setEdit] = useState<Item | null | undefined>(undefined)
  const [paying, setPaying] = useState<Item | null>(null)
  const catById = useMemo(() => new Map((cats.data ?? []).map((c) => [c.id, c])), [cats.data])
  const subs = kind === 'subscriptions'
  const meta = data?.meta as SubMeta | undefined

  const payBtn = (it: Item) => !readOnly && it.is_active && (
    <button type="button" className="btn sm" onClick={(e) => { e.stopPropagation(); setPaying(it) }}>{t(k.pay)}</button>
  )
  const sub = (it: Item) => (subs ? t((it as Subscription).scope) : catName(i, catById.get(it.category_id ?? 0)))

  return (
    <div className="list">
      <ListHead>
        <ModeToggle />
        <span className="count">{rows.filter((r) => r.is_active).length} / {rows.length}</span>
        <span className="grow" />
        {!readOnly && <button type="button" className="btn" onClick={() => setEdit(null)}><Icon name="plus" /><span className="hide-sm">{t('add')}</span></button>}
      </ListHead>
      {subs && meta && (
        <div className="sumbar">
          <span>{t('monthlyCost')}:</span><b className="num">{n(meta.monthly.total)}</b>
          <span>{t('personal')}: <span className="num">{n(meta.monthly.personal)}</span></span>
          <span>{t('work')}: <span className="num">{n(meta.monthly.work)}</span></span>
          <span className="muted">{meta.base_currency}</span>
        </div>
      )}
      <Paged
        items={rows}
        resetKey={mode}
        empty={<div className="empty"><Icon name={k.icon} /><p>{t('empty')}</p></div>}
        container={(c) => mode === 'table' ? (
          <div className="tbl"><table>
            <thead><tr><th>{t('name')}</th><th>{subs ? t('scope') : t('category')}</th><th>{t('amount')}</th><th>{t('cycle')}</th><th>{t(k.dueLabel)}</th><th>{t('status')}</th><th /></tr></thead>
            <tbody>{c}</tbody>
          </table></div>
        ) : <div className="grid">{c}</div>}
        renderItem={(it) => mode === 'table' ? (
          <tr key={it.id} onClick={() => setEdit(it)} className={it.is_active ? '' : 'off'}>
            <td><button type="button" className="row-link" onClick={() => setEdit(it)}>{it.name}</button></td>
            <td>{sub(it)}</td>
            <td className="num">{n(it.amount, 2)} {it.currency}</td>
            <td>{t(it.cycle)}{it.auto_pay ? ` · ${t(k.auto)}` : ''}</td>
            <td>{fmtDate(dueOf(it))}</td>
            <td><DueTag days={it.days} off={!it.is_active} /></td>
            <td className="acts">{payBtn(it)}</td>
          </tr>
        ) : (
          <div key={it.id} className={`card ic${it.is_active ? '' : ' off'}`} onClick={() => setEdit(it)} role="button" tabIndex={0}
            onKeyDown={(e) => e.key === 'Enter' && setEdit(it)}>
            <div className="t"><span>{it.name}</span><DueTag days={it.days} off={!it.is_active} /></div>
            <div className="amt num">{n(it.amount, 2)} <small className="cur-sm">{it.currency}</small></div>
            <div className="meta"><span>{sub(it)} · {t(it.cycle)}</span><span>{fmtDate(dueOf(it), { day: 'numeric', month: 'short' })}</span></div>
            {payBtn(it) && <div className="acts">{payBtn(it)}</div>}
          </div>
        )}
      />
      {edit !== undefined && <RecurringForm kind={kind} item={edit} accounts={accounts.data ?? []} cats={cats.data ?? []} onClose={() => setEdit(undefined)} />}
      {paying && <PayForm kind={kind} item={paying} accounts={accounts.data ?? []} onClose={() => setPaying(null)} />}
    </div>
  )
}

function PayForm({ kind, item, accounts, onClose }: { kind: Kind; item: Item; accounts: Account[]; onClose: () => void }) {
  const { t } = useI18n()
  const form = useForm({ account_id: item.account_id ? String(item.account_id) : String(accounts[0]?.id ?? ''), amount: String(item.amount), date: isoDate() }, onClose)
  const k = KIND[kind]
  const submit = () => form.run(() => post(`/${kind}/${item.id}/${k.payPath}`, { account_id: num(form.f.account_id), amount: num(form.f.amount), date: form.f.date }))
  return (
    <FormModal title={`${t(k.pay)} — ${item.name}`} submitLabel={t(k.pay)} errors={form.errors} busy={form.busy} onClose={onClose} onSubmit={submit} fields={[
      select(form, 'account_id', t(k.account), accounts.filter((a) => a.is_active).map((a) => [a.id, `${a.name} · ${a.currency}`]), { full: true }),
      money(form, 'amount', `${t('amount')} (${item.currency})`),
      date(form, 'date', t('date')),
    ]} />
  )
}

function RecurringForm({ kind, item, accounts, cats, onClose }: { kind: Kind; item: Item | null; accounts: Account[]; cats: Category[]; onClose: () => void }) {
  const i = useI18n()
  const { t } = i
  const { readOnly } = useSession()
  const subs = kind === 'subscriptions'
  const k = KIND[kind]
  const s = item as Subscription | null
  const form = useForm({
    name: item?.name ?? '', category_id: item?.category_id ? String(item.category_id) : '', amount: item ? String(item.amount) : '',
    currency: item?.currency ?? 'EGP', [k.due]: item ? dueOf(item) : isoDate(), cycle: item?.cycle ?? 'monthly',
    remind_days: item?.remind_days != null ? String(item.remind_days) : '', auto_pay: item?.auto_pay ?? false,
    account_id: item?.account_id ? String(item.account_id) : '', notes: item?.notes ?? '', is_active: item?.is_active ?? true,
    scope: s?.scope ?? 'personal', start_date: s?.start_date ?? '',
  }, onClose)
  const f = form.f
  const submit = () => form.run(() => {
    const body: Record<string, unknown> = {
      name: f.name, category_id: num(f.category_id), amount: num(f.amount), currency: f.currency, cycle: f.cycle,
      remind_days: num(f.remind_days), auto_pay: f.auto_pay, account_id: num(f.account_id), notes: orNull(f.notes), is_active: f.is_active,
      [k.due]: f[k.due],
      ...(subs ? { scope: f.scope, start_date: orNull(f.start_date) } : {}),
    }
    return item ? patch(`/${kind}/${item.id}`, body) : post(`/${kind}`, body)
  })
  const title = item ? t(k.edit) : t(k.new)
  return (
    <FormModal title={title} errors={form.errors} busy={form.busy || readOnly} onClose={onClose} onSubmit={submit}
      extra={item && !readOnly ? <DeleteButton busy={form.busy} onConfirm={() => form.run(() => del(`/${kind}/${item.id}`))} /> : undefined}
      fields={[
        text(form, 'name', t('name'), { maxLength: 120 }),
        subs
          ? select(form, 'scope', t('scope'), [['personal', t('personal')], ['work', t('work')]])
          : select(form, 'category_id', t('category'), cats.map((c) => [c.id, catName(i, c)]), { empty: t('noCategory') }),
        money(form, 'amount', t('amount')),
        currency(form, 'currency', t('currency')),
        select(form, 'cycle', t('cycle'), CYCLES.map((c) => [c, t(c)])),
        date(form, k.due, t(k.dueLabel)),
        date(form, 'start_date', t('startDate'), { hidden: !subs }),
        select(form, 'category_id', t('category'), cats.map((c) => [c.id, catName(i, c)]), { empty: t('noCategory'), hidden: !subs }),
        select(form, 'account_id', t(k.account), accounts.filter((a) => a.is_active || a.id === item?.account_id).map((a) => [a.id, a.name]), { empty: '—' }),
        text(form, 'remind_days', t('remindDays'), { inputMode: 'numeric', num: true, placeholder: t('defaultHint') }),
        check(form, 'auto_pay', t(k.auto), t(k.auto)),
        check(form, 'is_active', t('status'), t('active')),
        text(form, 'notes', t('notes'), { full: true, maxLength: 2000 }),
      ]} />
  )
}
