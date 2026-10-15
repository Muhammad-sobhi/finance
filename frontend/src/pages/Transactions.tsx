import { useMemo, useState } from 'react'
import { Icon } from '../components/Icon'
import { ListHead, ModeToggle, useListMode } from '../components/ListHead'
import { FormModal } from '../components/Modal'
import { Paged } from '../components/Paged'
import { TransactionForm } from '../components/TransactionForm'
import { useApi } from '../hooks/useApi'
import { useI18n } from '../i18n'
import { shiftMonth, thisMonth, monthIndex } from '../lib/dates'
import { catName } from '../lib/labels'
import { useSession } from '../lib/session'
import type { Account, Category, Transaction, TxMonth } from '../lib/types'
import { CatBadge } from './Categories'

type Item = { kind: 'day'; date: string; expense: number; income: number } | { kind: 'tx'; tx: Transaction }

export function Transactions() {
  const i = useI18n()
  const { t, num, date, d } = i
  const { readOnly, user } = useSession()
  const mode = useListMode()
  const [month, setMonth] = useState(thisMonth())
  const [filter, setFilter] = useState({ account_id: '', category_id: '' })
  const [filterOpen, setFilterOpen] = useState(false)
  const [edit, setEdit] = useState<Transaction | null | undefined>(undefined)

  const qs = new URLSearchParams({ month, ...Object.fromEntries(Object.entries(filter).filter(([, v]) => v)) }).toString()
  const { data } = useApi<TxMonth>(`/transactions?${qs}`)
  const accounts = useApi<Account[]>('/accounts')
  const cats = useApi<Category[]>('/categories')
  const accById = useMemo(() => new Map((accounts.data ?? []).map((a) => [a.id, a])), [accounts.data])
  const catById = useMemo(() => new Map((cats.data ?? []).map((c) => [c.id, c])), [cats.data])
  const base = data?.meta.base_currency ?? user?.base_currency ?? ''

  // Day headers interleaved with that day's transactions (already newest first).
  const items = useMemo<Item[]>(() => {
    const out: Item[] = []
    let day = ''
    for (const tx of data?.data ?? []) {
      if (tx.date !== day) {
        day = tx.date
        const totals = data!.meta.days[day] ?? { expense: 0, income: 0 }
        out.push({ kind: 'day', date: day, ...totals })
      }
      out.push({ kind: 'tx', tx })
    }
    return out
  }, [data])

  const filtered = !!(filter.account_id || filter.category_id)
  const [y, m] = [month.slice(0, 4), monthIndex(month)]
  const label = `${d.months[m]} ${i.num(Number(y)).replace(/[٬,]/g, '')}`

  const amount = (tx: Transaction) => {
    const sign = tx.type === 'expense' ? '−' : tx.type === 'income' ? '+' : ''
    const cls = tx.type === 'expense' ? 'neg' : tx.type === 'income' ? 'pos' : 'muted'
    return <span className={`num ${cls}`}>{sign}{num(tx.amount, 2)}{tx.currency !== base ? ` ${tx.currency}` : ''}</span>
  }
  const title = (tx: Transaction) => tx.type === 'transfer'
    ? `${accById.get(tx.account_id)?.name ?? ''} ${i.lang === 'ar' ? '←' : '→'} ${accById.get(tx.to_account_id ?? 0)?.name ?? ''}`
    : catName(i, catById.get(tx.category_id ?? 0))
  const badge = (tx: Transaction) => tx.type === 'transfer'
    ? <span className="cat-ic" style={{ background: 'var(--muted)' }}><Icon name="debts" /></span>
    : <CatBadge c={catById.get(tx.category_id ?? 0)} />
  const dayLabel = (s: string) => date(s, { weekday: 'short', day: 'numeric', month: 'short' })
  const dayTotal = (it: Extract<Item, { kind: 'day' }>) => (
    <span className="num">
      {it.expense > 0 && <span className="neg">−{num(it.expense)}</span>}
      {it.expense > 0 && it.income > 0 && ' · '}
      {it.income > 0 && <span className="pos">+{num(it.income)}</span>}
    </span>
  )

  const accountSelect = (id: string) => (
    <select id={id} className="inp" value={filter.account_id} onChange={(e) => setFilter({ ...filter, account_id: e.target.value })} aria-label={t('account')}>
      <option value="">{t('allAccounts')}</option>
      {(accounts.data ?? []).map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
    </select>
  )
  const categorySelect = (id: string) => (
    <select id={id} className="inp" value={filter.category_id} onChange={(e) => setFilter({ ...filter, category_id: e.target.value })} aria-label={t('category')}>
      <option value="">{t('allCategories')}</option>
      {(cats.data ?? []).map((c) => <option key={c.id} value={c.id}>{catName(i, c)}</option>)}
    </select>
  )

  return (
    <div className="list">
      <ListHead>
        <div className="month-nav">
          <button type="button" className="icon-btn" onClick={() => setMonth(shiftMonth(month, -1))} aria-label={t('prevMonth')}><Icon name="prev" className="flip" /></button>
          <span className="month-lbl">{label}</span>
          <button type="button" className="icon-btn" onClick={() => setMonth(shiftMonth(month, 1))} aria-label={t('nextMonth')} disabled={month >= thisMonth()}><Icon name="next" className="flip" /></button>
        </div>
        <div className="inline-filters hide-md">{accountSelect('flt-account')}{categorySelect('flt-category')}</div>
        <button type="button" className="icon-btn show-md" onClick={() => setFilterOpen(true)} aria-label={t('filters')}>
          <Icon name="filter" />{filtered && <span className="dot" />}
        </button>
        <span className="grow" />
        <ModeToggle />
        {!readOnly && <button type="button" className="btn" onClick={() => setEdit(null)}><Icon name="plus" /><span className="hide-sm">{t('add')}</span></button>}
      </ListHead>

      <div className="sumbar" aria-live="polite">
        <span>{t('monthTotal')}:</span>
        <b className="num neg">−{num(data?.meta.totals.expense ?? 0)}</b>
        <b className="num pos">+{num(data?.meta.totals.income ?? 0)}</b>
        <span className="muted">{t('net')}: <span className="num">{num(data?.meta.totals.net ?? 0)}</span> {base}</span>
      </div>

      <Paged
        items={items}
        resetKey={`${qs}|${mode}`}
        keepWithNext={(it) => it.kind === 'day'}
        empty={<div className="empty"><Icon name="empty" /><p>{t('empty')}</p></div>}
        container={(children) => mode === 'table' ? (
          <div className="tbl">
            <table className="tx-table">
              <thead><tr><th>{t('category')}</th><th>{t('note')}</th><th>{t('account')}</th><th>{t('amount')}</th></tr></thead>
              <tbody>{children}</tbody>
            </table>
          </div>
        ) : <div className="grid tx-grid">{children}</div>}
        renderItem={(it) => {
          if (it.kind === 'day') {
            return mode === 'table'
              ? <tr key={`d${it.date}`} className="day"><td colSpan={4}><div className="day-row"><span>{dayLabel(it.date)}</span>{dayTotal(it)}</div></td></tr>
              : <div key={`d${it.date}`} className="day-h"><span>{dayLabel(it.date)}</span>{dayTotal(it)}</div>
          }
          const tx = it.tx
          return mode === 'table' ? (
            <tr key={tx.id} onClick={() => setEdit(tx)}>
              <td><button type="button" className="row-link cat-name" onClick={() => setEdit(tx)}>{badge(tx)}{title(tx)}</button></td>
              <td className="muted">{tx.note ?? ''}</td>
              <td>{accById.get(tx.account_id)?.name ?? ''}</td>
              <td>{amount(tx)}</td>
            </tr>
          ) : (
            <button type="button" key={tx.id} className="card tx" onClick={() => setEdit(tx)}>
              {badge(tx)}
              <span className="tx-main"><span className="tx-t">{title(tx)}</span><span className="tx-m">{[tx.note, accById.get(tx.account_id)?.name].filter(Boolean).join(' · ')}</span></span>
              {amount(tx)}
            </button>
          )
        }}
      />

      {edit !== undefined && <TransactionForm tx={edit} onClose={() => setEdit(undefined)} />}
      {filterOpen && (
        <FormModal title={t('filters')} onClose={() => setFilterOpen(false)} onSubmit={() => setFilterOpen(false)} submitLabel={t('done')}
          extra={<button type="button" className="btn ghost" onClick={() => { setFilter({ account_id: '', category_id: '' }); setFilterOpen(false) }}>{t('clear')}</button>}
          fields={[
            { name: 'account', label: t('account'), full: true, control: accountSelect('f-account') },
            { name: 'category', label: t('category'), full: true, control: categorySelect('f-category') },
          ]} />
      )}
    </div>
  )
}
