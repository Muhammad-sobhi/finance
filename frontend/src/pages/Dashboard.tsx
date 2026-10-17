import { useCallback, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { BarChart } from '../components/Charts'
import { DueTag } from '../components/Due'
import { FitList } from '../components/FitList'
import { useApi } from '../hooks/useApi'
import { useIsPhone } from '../hooks/useMedia'
import { useSwipe } from '../hooks/useSwipe'
import { useI18n } from '../i18n'
import { monthIndex } from '../lib/dates'
import { DUE_ROUTE, dueSubject } from '../lib/alerts'
import type { Dashboard as Dash } from '../lib/types'

const PANELS = 4

export function Dashboard() {
  const i = useI18n()
  const { t, num, d } = i
  const nav = useNavigate()
  const phone = useIsPhone()
  const { data } = useApi<Dash>('/dashboard')
  const [panel, setPanel] = useState(0)
  const root = useRef<HTMLDivElement>(null)
  const step = useCallback((dir: 1 | -1) => setPanel((p) => Math.max(0, Math.min(PANELS - 1, p + dir))), [])
  useSwipe(root, step)

  const balances = useMemo(() => data?.balances ?? [], [data])
  const upcoming = useMemo(() => data?.upcoming ?? [], [data])
  const spending = data?.spending ?? []
  const cur = spending[spending.length - 1]
  const monthNet = cur ? cur.income - cur.expense : 0
  const base = data?.base_currency ?? ''
  const fc = data?.forecast

  const cls = (n: number) => `panel${panel === n ? ' on' : ''}`

  return (
    <div className="dash" ref={root}>
      <div className={cls(0)}>
        <div className="kpis">
          <div className="card kpi hero">
            <div className="lbl">{t('worth')}</div>
            <div className="v num">{data ? num(data.net_worth) : '…'} <small className="cur-sm">{base}</small></div>
            <div className={`d ${monthNet >= 0 ? 'pos' : 'neg'}`}>{monthNet >= 0 ? '▲' : '▼'} <span className="num">{num(Math.abs(monthNet))}</span> {t('thisMonth')}</div>
            {fc && fc.days_left > 0 && (
              <div className="d fc muted" title={t('forecastLine', { spend: num(fc.projected_spend), save: '' })}>
                {t('forecastLine', {
                  spend: num(fc.projected_spend),
                  save: fc.projected_savings >= 0 ? t('forecastSave', { n: num(fc.projected_savings) }) : t('forecastShort', { n: num(-fc.projected_savings) }),
                })}
              </div>
            )}
          </div>
          <div className="card kpi"><div className="lbl">{t('liquid')}</div><div className="v num">{data ? num(data.liquid) : '…'}</div></div>
          <div className="card kpi"><div className="lbl">{t('owedToMe')}</div><div className="v num pos">{data ? num(data.owed_to_me) : '…'}</div></div>
          <div className="card kpi"><div className="lbl">{t('iOwe')}</div><div className="v num neg">{data ? num(data.i_owe) : '…'}</div></div>
        </div>
      </div>

      <div className={cls(1)}>
        <div className="card c-bal">
          <h3>{t('balances')}<small>{t('accountsCount', { n: balances.length })}</small></h3>
          {(!phone || panel === 1) && (
            <FitList items={balances} onMore={() => nav('/accounts')} renderItem={(a) => (
              <div className="li" key={a.id}>
                <span>{a.name}</span>
                <span className={`num${a.balance < 0 ? ' neg' : ''}`}>{num(a.balance)}{a.currency !== base ? ` ${a.currency}` : ''}</span>
              </div>
            )} />
          )}
        </div>
      </div>

      <div className={cls(2)}>
        <div className="card c-chart">
          <h3>{t('spending')}<small>{base}</small></h3>
          <BarChart
            points={spending.map((x) => ({ label: d.months[monthIndex(x.month)], values: { expense: x.expense } }))}
            series={[{ key: 'expense', label: t('expense'), color: 1 }]}
            format={(v) => `${num(v)} ${base}`}
            ariaLabel={spending.map((x) => `${d.months[monthIndex(x.month)]}: ${num(x.expense)}`).join(', ')}
          />
        </div>
      </div>

      <div className={cls(3)}>
        <div className="card c-alerts">
          <h3>{t('upcoming')}</h3>
          {upcoming.length === 0 ? <p className="muted none">{t('nothingSoon')}</p> : (!phone || panel === 3) && (
            <FitList items={upcoming} onMore={() => nav('/bills')} renderItem={(u) => (
              <button type="button" className="li li-btn" key={`${u.kind}-${u.id}`} onClick={() => nav(DUE_ROUTE[u.kind] ?? '/')}>
                <span>{dueSubject(i, u.kind, u.name)} — <span className="num">{num(u.amount)}</span></span>
                <DueTag days={u.days} />
              </button>
            )} />
          )}
        </div>
      </div>

      <div className="dots" role="tablist">
        {Array.from({ length: PANELS }, (_, n) => (
          <button type="button" key={n} role="tab" aria-selected={panel === n} aria-label={`${n + 1}`} className={panel === n ? 'on' : ''} onClick={() => setPanel(n)} />
        ))}
      </div>
    </div>
  )
}
