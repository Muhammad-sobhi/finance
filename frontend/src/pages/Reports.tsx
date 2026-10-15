import { useCallback, useMemo, useRef, useState } from 'react'
import { BarChart, LineChart } from '../components/Charts'
import { DetailModal } from '../components/DetailModal'
import { FitList } from '../components/FitList'
import { Icon } from '../components/Icon'
import { Paged } from '../components/Paged'
import { useApi } from '../hooks/useApi'
import { useSwipe } from '../hooks/useSwipe'
import { useI18n } from '../i18n'
import { monthIndex, shiftMonth, thisMonth } from '../lib/dates'
import type { Report } from '../lib/types'

const PANELS = 5
type Cat = Report['by_category'][number]
type Advice = Report['advice'][number]

export function Reports() {
  const i = useI18n()
  const { t, num, d, lang } = i
  const [period, setPeriod] = useState<'month' | 'year'>('month')
  const [month, setMonth] = useState(thisMonth())
  const [year, setYear] = useState(new Date().getFullYear())
  const { data: r } = useApi<Report>(period === 'month' ? `/reports?period=month&month=${month}` : `/reports?period=year&year=${year}`)
  const [panel, setPanel] = useState(0)
  const [more, setMore] = useState<'cats' | 'advice' | null>(null)
  const root = useRef<HTMLDivElement>(null)
  const step = useCallback((dir: 1 | -1) => setPanel((p) => Math.max(0, Math.min(PANELS - 1, p + dir))), [])
  useSwipe(root, step)

  const cats = useMemo(() => r?.by_category ?? [], [r])
  const advice = useMemo(() => r?.advice ?? [], [r])
  const isCurrent = period === 'month' ? month >= thisMonth() : year >= new Date().getFullYear()
  const label = period === 'month' ? `${d.months[monthIndex(month)]} ${num(Number(month.slice(0, 4))).replace(/[٬,]/g, '')}` : num(year).replace(/[٬,]/g, '')
  const shift = (by: 1 | -1) => (period === 'month' ? setMonth(shiftMonth(month, by)) : setYear(year + by))

  const change = (v: number | null | undefined, goodWhenUp = true) => {
    if (v === null || v === undefined) return <span className="d muted">—</span>
    const good = goodWhenUp ? v >= 0 : v <= 0
    return <div className={`d ${good ? 'pos' : 'neg'}`}>{v >= 0 ? '▲' : '▼'} <span className="num">{num(Math.abs(v), 1)}%</span> {t('vsPrev')}</div>
  }
  const catLabel = (c: Cat) => (c.key ? i.e(c.key === 'uncategorized' ? 'noCategory' : c.key) : c.name ?? '—')
  const trend = r?.net_worth_trend ?? []
  const cls = (n: number) => `panel${panel === n ? ' on' : ''}`
  const maxCat = Math.max(1, ...cats.map((c) => c.amount))

  const catRow = (c: Cat) => (
    <div className="hbar" key={c.category_id ?? 'none'}>
      <span>{catLabel(c)}</span>
      <span className="track"><span className="fill" style={{ width: `${(c.amount / maxCat) * 100}%`, background: c.color ?? undefined, display: 'block' }} /></span>
      <span className="num">{num(c.amount)} <small className="muted">{num(c.share)}%</small></span>
    </div>
  )
  const adviceRow = (a: Advice, n: number) => (
    <div className={`advice lvl-${a.level}`} key={`${a.code}-${n}`}>
      <b className={`tag${a.level === 'danger' ? ' late' : a.level === 'info' ? ' quiet' : ''}`}>{t(`lvl_${a.level}`)}</b>
      <span>{a.text[lang]}</span>
    </div>
  )

  return (
    <div className="list reports">
      <div className="list-head">
        <div className="seg" role="radiogroup" aria-label={t('type')}>
          {(['month', 'year'] as const).map((k) => (
            <button type="button" key={k} role="radio" aria-checked={period === k} className={period === k ? 'on' : ''} onClick={() => setPeriod(k)}>{t(k)}</button>
          ))}
        </div>
        <div className="month-nav">
          <button type="button" className="icon-btn" onClick={() => shift(-1)} aria-label={t('prevPeriod')}><Icon name="prev" className="flip" /></button>
          <span className="month-lbl">{label}</span>
          <button type="button" className="icon-btn" onClick={() => shift(1)} disabled={isCurrent} aria-label={t('nextPeriod')}><Icon name="next" className="flip" /></button>
        </div>
        <span className="grow" />
        {r && (
          <span className={`tag ${r.growth.growing ? 'quiet grow-tag' : 'late'}`}>
            {r.growth.growing ? t('growing') : t('notGrowing')}{r.growth.pct !== null ? ` ${r.growth.pct >= 0 ? '▲' : '▼'} ${num(Math.abs(r.growth.pct), 1)}%` : ''}
          </span>
        )}
      </div>

      <div className="rep" ref={root}>
        <div className={cls(0)}>
          <div className="card kpi"><div className="lbl">{t('totalIncome')}</div><div className="v num pos">{r ? num(r.totals.income) : '…'}</div>{change(r?.change.income)}</div>
          <div className="card kpi"><div className="lbl">{t('totalExpense')}</div><div className="v num neg">{r ? num(r.totals.expense) : '…'}</div>{change(r?.change.expense, false)}</div>
          <div className="card kpi"><div className="lbl">{t('savings')}</div><div className={`v num ${r && r.totals.savings < 0 ? 'neg' : ''}`}>{r ? num(r.totals.savings) : '…'}</div>{change(r?.change.savings)}</div>
          <div className="card kpi">
            <div className="lbl">{t('savingsRate')}</div>
            <div className="v num">{r?.totals.savings_rate != null ? `${num(r.totals.savings_rate, 1)}%` : '—'}</div>
            <div className="d muted">{t('growth')}: {r?.growth.basis === 'net_worth' ? t('worth') : t('savings')}</div>
          </div>
        </div>

        <div className={cls(1)}>
          <div className="card">
            <h3>{t('incomeVsExpense')}<small>{r?.base_currency}</small></h3>
            <BarChart
              points={(r?.series ?? []).map((x) => ({ label: d.months[monthIndex(x.month)], values: { income: x.income, expense: x.expense } }))}
              series={[{ key: 'income', label: t('income'), color: 1 }, { key: 'expense', label: t('expense'), color: 2 }]}
              format={(v) => `${num(v)} ${r?.base_currency ?? ''}`}
              labelLast={false}
              ariaLabel={t('incomeVsExpense')}
            />
          </div>
        </div>

        <div className={cls(2)}>
          <div className="card">
            <h3>{t('netWorthTrend')}<small>{r?.base_currency}</small></h3>
            <LineChart
              points={trend.map((x) => ({ label: d.months[monthIndex(x.month)], value: x.net_worth }))}
              format={(v) => `${num(v)} ${r?.base_currency ?? ''}`}
              ariaLabel={`${t('netWorthTrend')}: ${trend.map((x) => num(x.net_worth)).join(', ')}`}
            />
          </div>
        </div>

        <div className={cls(3)}>
          <div className="card">
            <h3>{t('byCategory')}<small className="num">{r ? num(r.totals.expense) : ''}</small></h3>
            {cats.length === 0 ? <p className="muted none">{t('empty')}</p> : <FitList items={cats} onMore={() => setMore('cats')} renderItem={catRow} />}
          </div>
        </div>

        <div className={cls(4)}>
          <div className="card">
            <h3>{t('advice')}</h3>
            {advice.length === 0 ? <p className="muted none">{t('noAdvice')}</p> : <FitList items={advice} onMore={() => setMore('advice')} renderItem={(a) => adviceRow(a, advice.indexOf(a))} />}
          </div>
        </div>

        <div className="dots" role="tablist">
          {Array.from({ length: PANELS }, (_, n) => (
            <button type="button" key={n} role="tab" aria-selected={panel === n} aria-label={`${n + 1}`} className={panel === n ? 'on' : ''} onClick={() => setPanel(n)} />
          ))}
        </div>
      </div>

      {more === 'cats' && (
        <DetailModal title={t('byCategory')} onClose={() => setMore(null)}>
          <Paged items={cats} container={(c) => <div className="rows">{c}</div>} renderItem={catRow} />
        </DetailModal>
      )}
      {more === 'advice' && (
        <DetailModal title={t('advice')} onClose={() => setMore(null)}>
          <Paged items={advice} container={(c) => <div className="rows">{c}</div>} renderItem={(a) => adviceRow(a, advice.indexOf(a))} />
        </DetailModal>
      )}
    </div>
  )
}
