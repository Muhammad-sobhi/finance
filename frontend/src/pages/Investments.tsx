import { useMemo, useState } from 'react'
import { LineChart } from '../components/Charts'
import { DetailModal, Facts } from '../components/DetailModal'
import { currency, date, money, select, text } from '../components/fields'
import { Icon } from '../components/Icon'
import { ListHead, ModeToggle, useListMode } from '../components/ListHead'
import { DeleteButton, FormModal } from '../components/Modal'
import { Paged } from '../components/Paged'
import { useApi } from '../hooks/useApi'
import { num, orNull, useForm } from '../hooks/useForm'
import { useI18n } from '../i18n'
import { del, patch, post } from '../lib/api'
import { isoDate, monthIndex } from '../lib/dates'
import { useSession } from '../lib/session'
import { INVESTMENT_TYPES, type Investment, type Listed } from '../lib/types'

type Meta = { base_currency: string; invested: number; current: number; profit: number; roi: number | null }

export function Investments() {
  const i = useI18n()
  const { t, num: n } = i
  const { readOnly } = useSession()
  const mode = useListMode()
  const { data } = useApi<Listed<Investment, Meta>>('/investments')
  const rows = useMemo(() => data?.data ?? [], [data])
  const [edit, setEdit] = useState<Investment | null | undefined>(undefined)
  const [view, setView] = useState<number | null>(null)
  const m = data?.meta

  const roi = (r: number | null) => (r === null ? '—' : <span className={`num ${r >= 0 ? 'pos' : 'neg'}`}>{r >= 0 ? '▲' : '▼'} {n(Math.abs(r), 1)}%</span>)
  const profit = (v: number) => <span className={`num ${v >= 0 ? 'pos' : 'neg'}`}>{v >= 0 ? '+' : ''}{n(v)}</span>

  return (
    <div className="list">
      <ListHead>
        <ModeToggle />
        <span className="grow" />
        {!readOnly && <button type="button" className="btn" onClick={() => setEdit(null)}><Icon name="plus" /><span className="hide-sm">{t('add')}</span></button>}
      </ListHead>
      {m && (
        <div className="sumbar">
          <span>{t('currentValue')}:</span><b className="num">{n(m.current)}</b>
          <span>{t('profit')}: {profit(m.profit)}</span>
          <span>{t('roi')}: {roi(m.roi)}</span>
          <span className="muted">{m.base_currency}</span>
        </div>
      )}
      <Paged
        items={rows}
        resetKey={mode}
        empty={<div className="empty"><Icon name="investments" /><p>{t('empty')}</p></div>}
        container={(c) => mode === 'table' ? (
          <div className="tbl"><table>
            <thead><tr><th>{t('name')}</th><th>{t('type')}</th><th>{t('platform')}</th><th>{t('invested')}</th><th>{t('currentValue')}</th><th>{t('profit')}</th><th>{t('roi')}</th></tr></thead>
            <tbody>{c}</tbody>
          </table></div>
        ) : <div className="grid">{c}</div>}
        renderItem={(v) => mode === 'table' ? (
          <tr key={v.id} onClick={() => setView(v.id)}>
            <td><button type="button" className="row-link" onClick={() => setView(v.id)}>{v.name}</button></td>
            <td>{i.e(v.type)}</td>
            <td>{v.platform ?? '—'}</td>
            <td className="num">{n(v.invested_amount)}</td>
            <td className="num">{n(v.current_value)} {v.currency}</td>
            <td>{profit(v.profit)}</td>
            <td>{roi(v.roi)}</td>
          </tr>
        ) : (
          <button type="button" key={v.id} className="card ic" onClick={() => setView(v.id)}>
            <div className="t"><span>{v.name}</span><span className="tag quiet">{i.e(v.type)}</span></div>
            <div className="amt num">{n(v.current_value)} <small className="cur-sm">{v.currency}</small></div>
            <div className="meta"><span>{v.platform ?? '—'}</span><span>{profit(v.profit)} · {roi(v.roi)}</span></div>
          </button>
        )}
      />
      {view !== null && <InvestmentDetail id={view} onClose={() => setView(null)} onEdit={(v) => { setView(null); setEdit(v) }} />}
      {edit !== undefined && <InvestmentForm inv={edit} onClose={() => setEdit(undefined)} />}
    </div>
  )
}

function InvestmentDetail({ id, onClose, onEdit }: { id: number; onClose: () => void; onEdit: (v: Investment) => void }) {
  const i = useI18n()
  const { t, num: n, date: fmtDate, d: dict } = i
  const { readOnly, refresh, toast } = useSession()
  const { data: v } = useApi<Investment>(`/investments/${id}`)
  const [adding, setAdding] = useState(false)
  const values = useMemo(() => [...(v?.values ?? [])].reverse(), [v])
  const chart = (v?.values ?? []).slice(-12)

  return (
    <DetailModal title={v?.name ?? t('loading')} onClose={onClose}
      footer={!readOnly && v ? (
        <>
          <button type="button" className="btn ghost" onClick={() => onEdit(v)}>{t('edit')}</button>
          <span className="grow" />
          <button type="button" className="btn" onClick={() => setAdding(true)}>{t('addValue')}</button>
        </>
      ) : undefined}>
      {v && (
        <>
          <Facts items={[
            [t('invested'), <span className="num">{n(v.invested_amount)} {v.currency}</span>],
            [t('currentValue'), <span className="num">{n(v.current_value)}</span>],
            [t('profit'), <span className={`num ${v.profit >= 0 ? 'pos' : 'neg'}`}>{n(v.profit)}</span>],
            [t('roi'), v.roi === null ? '—' : <span className="num">{n(v.roi, 1)}%</span>],
            [t('returnRate'), v.annual_return === null ? '—' : <span className="num">{n(v.annual_return, 2)}%</span>],
            [t('maturityDate'), v.maturity_date ? fmtDate(v.maturity_date) : '—'],
          ]} />
          <div className="mini-chart">
            <LineChart
              points={chart.map((p) => ({ label: dict.months[monthIndex(p.date.slice(0, 7))], value: p.value, title: fmtDate(p.date) }))}
              format={(x) => `${n(x)} ${v.currency}`}
              ariaLabel={`${t('valueHistory')}: ${chart.map((p) => n(p.value)).join(', ')}`}
            />
          </div>
          <Paged items={values} resetKey={String(id)}
            container={(c) => <div className="rows">{c}</div>}
            renderItem={(p) => (
              <div className="li" key={p.id}>
                <span>{fmtDate(p.date)}</span>
                <span>
                  <b className="num">{n(p.value, 2)}</b>
                  {!readOnly && values.length > 1 && (
                    <button type="button" className="icon-btn sm" aria-label={t('delete')}
                      onClick={() => del(`/investments/${id}/values/${p.id}`).then(() => { toast(t('saved')); refresh() }).catch(() => toast(t('error')))}>
                      <Icon name="close" />
                    </button>
                  )}
                </span>
              </div>
            )} />
        </>
      )}
      {adding && v && <ValueForm inv={v} onClose={() => setAdding(false)} />}
    </DetailModal>
  )
}

function ValueForm({ inv, onClose }: { inv: Investment; onClose: () => void }) {
  const { t } = useI18n()
  const form = useForm({ date: isoDate(), value: String(inv.current_value) }, onClose)
  return (
    <FormModal title={`${t('addValue')} — ${inv.name}`} errors={form.errors} busy={form.busy} onClose={onClose}
      onSubmit={() => form.run(() => post(`/investments/${inv.id}/values`, { date: form.f.date, value: num(form.f.value) }))}
      fields={[date(form, 'date', t('date')), money(form, 'value', `${t('currentValue')} (${inv.currency})`)]} />
  )
}

function InvestmentForm({ inv, onClose }: { inv: Investment | null; onClose: () => void }) {
  const i = useI18n()
  const { t } = i
  const { readOnly } = useSession()
  const s = (v: number | null | undefined) => (v === null || v === undefined ? '' : String(v))
  const form = useForm({
    name: inv?.name ?? '', type: inv?.type ?? 'certificate', platform: inv?.platform ?? '', invested_amount: s(inv?.invested_amount),
    current_value: s(inv?.current_value), currency: inv?.currency ?? 'EGP', quantity: s(inv?.quantity), unit: inv?.unit ?? '',
    start_date: inv?.start_date ?? isoDate(), maturity_date: inv?.maturity_date ?? '', annual_return: s(inv?.annual_return),
    payout_cycle: inv?.payout_cycle ?? '', remind_days: s(inv?.remind_days), notes: inv?.notes ?? '',
  }, onClose)
  const f = form.f
  const submit = () => form.run(() => {
    const body = {
      name: f.name, type: f.type, platform: orNull(f.platform), invested_amount: num(f.invested_amount),
      ...(f.current_value !== '' ? { current_value: num(f.current_value) } : {}), currency: f.currency,
      quantity: num(f.quantity), unit: orNull(f.unit), start_date: orNull(f.start_date), maturity_date: orNull(f.maturity_date),
      annual_return: num(f.annual_return), payout_cycle: orNull(f.payout_cycle), remind_days: num(f.remind_days), notes: orNull(f.notes),
    }
    return inv ? patch(`/investments/${inv.id}`, body) : post('/investments', body)
  })
  return (
    <FormModal title={inv ? t('editInvestment') : t('newInvestment')} errors={form.errors} busy={form.busy || readOnly} onClose={onClose} onSubmit={submit}
      extra={inv && !readOnly ? <DeleteButton busy={form.busy} onConfirm={() => form.run(() => del(`/investments/${inv.id}`))} /> : undefined}
      fields={[
        text(form, 'name', t('name'), { maxLength: 120 }),
        select(form, 'type', t('type'), INVESTMENT_TYPES.map((k) => [k, i.e(k)])),
        text(form, 'platform', t('platform'), { maxLength: 120 }),
        currency(form, 'currency', t('currency')),
        money(form, 'invested_amount', t('invested')),
        money(form, 'current_value', t('currentValue'), { placeholder: t('invested') }),
        money(form, 'quantity', t('quantity')),
        text(form, 'unit', t('unit'), { maxLength: 24 }),
        date(form, 'start_date', t('startDate')),
        date(form, 'maturity_date', t('maturityDate')),
        money(form, 'annual_return', t('returnRate')),
        select(form, 'payout_cycle', t('payout'), [['monthly', t('monthly')], ['quarterly', t('quarterly')], ['yearly', t('yearly')], ['end', i.e('end')]], { empty: '—' }),
        text(form, 'remind_days', t('remindDays'), { inputMode: 'numeric', num: true, placeholder: t('defaultHint') }),
        text(form, 'notes', t('notes'), { full: true, maxLength: 2000 }),
      ]} />
  )
}
