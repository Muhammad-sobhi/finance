import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useI18n } from '../i18n'

/**
 * Small SVG charts shared by every page. Thin bars capped at 24px with 4px rounded
 * tips anchored to one baseline, hairline gridlines with clean y ticks, a 2px line
 * with a light wash for trends, hover tooltips, and a legend only for 2+ series.
 * Colors come from per-theme chart tokens (--c1..--c3, validated for contrast and
 * color-blind separation); text always uses ink/muted tokens.
 * In RTL the time axis runs right-to-left, matching the rest of the app.
 */

export interface Series { key: string; label: string; color: 1 | 2 | 3 }
export interface Point { label: string; values: Record<string, number> }

const BAR_MAX = 24
const PAD_TOP = 14
const PAD_BOTTOM = 22

/** Track the element's size so the SVG is drawn at exactly the space it has. */
function useSize() {
  const ref = useRef<HTMLDivElement>(null)
  const [size, setSize] = useState({ w: 0, h: 0 })
  useEffect(() => {
    const el = ref.current
    if (!el) return
    const ro = new ResizeObserver(([e]) => setSize({ w: Math.floor(e.contentRect.width), h: Math.floor(e.contentRect.height) }))
    ro.observe(el)
    return () => ro.disconnect()
  }, [])
  return { ref, ...size }
}

/** 0-based "nice" ticks (1 / 2 / 2.5 / 5 × 10^k). */
function niceTicks(lo: number, hi: number, count = 4): number[] {
  if (hi <= lo) hi = lo + 1
  const raw = (hi - lo) / count
  const mag = 10 ** Math.floor(Math.log10(raw))
  const step = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((s) => s >= raw) ?? raw
  const start = Math.floor(lo / step) * step
  const ticks: number[] = []
  for (let v = start; v <= hi + step * 0.001; v += step) ticks.push(Number(v.toFixed(6)))
  if (ticks[ticks.length - 1] < hi) ticks.push(ticks[ticks.length - 1] + step)
  return ticks
}

function useCompact() {
  const { lang } = useI18n()
  return useMemo(() => {
    const f = new Intl.NumberFormat(lang === 'ar' ? 'ar-EG' : 'en-US', { notation: 'compact', maximumFractionDigits: 1 })
    return (n: number) => f.format(n)
  }, [lang])
}

/** Rounded data-end (top), square at the baseline. */
function barPath(x: number, y: number, w: number, h: number, r = 4) {
  if (h <= 0) return ''
  const rr = Math.min(r, h, w / 2)
  return `M${x},${y + h}V${y + rr}Q${x},${y} ${x + rr},${y}H${x + w - rr}Q${x + w},${y} ${x + w},${y + rr}V${y + h}Z`
}

function Legend({ series }: { series: Series[] }) {
  if (series.length < 2) return null
  return (
    <div className="legend">
      {series.map((s) => <span key={s.key}><i className="sw" style={{ background: `var(--c${s.color})` }} />{s.label}</span>)}
    </div>
  )
}

function Tooltip({ x, w, title, rows }: { x: number; w: number; title: string; rows: ReactNode[] }) {
  // Clamp inside the plot so it never overflows the card.
  const width = 170
  const left = Math.max(0, Math.min(w - width, x - width / 2))
  return (
    <div className="viz-tip" style={{ left, width }} role="status">
      <b>{title}</b>
      {rows}
    </div>
  )
}

interface BarProps {
  points: Point[]
  series: Series[]
  stacked?: boolean
  format: (n: number) => string
  /** Label the value on the last column (the current period). */
  labelLast?: boolean
  ariaLabel: string
}

export function BarChart({ points, series, stacked = false, format, labelLast = true, ariaLabel }: BarProps) {
  const { lang } = useI18n()
  const rtl = lang === 'ar'
  const compact = useCompact()
  const { ref, w, h } = useSize()
  const [hover, setHover] = useState<number | null>(null)

  const totals = points.map((p) => (stacked ? series.reduce((s, x) => s + Math.max(0, p.values[x.key] ?? 0), 0) : Math.max(0, ...series.map((x) => p.values[x.key] ?? 0))))
  const ticks = niceTicks(0, Math.max(1, ...totals))
  const top = ticks[ticks.length - 1]
  const axisW = Math.max(...ticks.map((v) => compact(v).length)) * 7 + 10
  const plotW = Math.max(0, w - axisW)
  const plotH = Math.max(0, h - PAD_TOP - PAD_BOTTOM)
  const n = Math.max(1, points.length)
  const slot = plotW / n
  const plotX0 = rtl ? 0 : axisW
  const xOf = (i: number) => plotX0 + (rtl ? n - 1 - i : i) * slot
  const yOf = (v: number) => PAD_TOP + plotH - (v / top) * plotH
  const groupN = stacked ? 1 : series.length
  const barW = Math.max(3, Math.min(BAR_MAX, (slot * 0.62 - (groupN - 1) * 2) / groupN))
  const groupW = groupN * barW + (groupN - 1) * 2
  const showEvery = slot < 30 ? 2 : 1

  return (
    <div className="viz">
      <div className="viz-plot" ref={ref} onMouseLeave={() => setHover(null)}>
      {w > 0 && h > 0 && (
        <svg width={w} height={h} role="img" aria-label={ariaLabel}>
          {ticks.map((v) => (
            <g key={v}>
              <line x1={plotX0} x2={plotX0 + plotW} y1={yOf(v)} y2={yOf(v)} className="viz-grid" />
              <text x={rtl ? w - 2 : axisW - 6} y={yOf(v)} dy="0.32em" textAnchor="end" className="viz-tick" direction="ltr">{compact(v)}</text>
            </g>
          ))}
          {points.map((p, i) => {
            const cx = xOf(i) + slot / 2
            let acc = 0
            return (
              <g key={p.label + i}>
                {hover === i && <rect x={xOf(i) + 1} y={PAD_TOP} width={slot - 2} height={plotH} className="viz-hover" />}
                {series.map((s, si) => {
                  const v = Math.max(0, p.values[s.key] ?? 0)
                  if (stacked) {
                    const y0 = yOf(acc)
                    acc += v
                    const y1 = yOf(acc)
                    const isTop = series.slice(si + 1).every((x) => (p.values[x.key] ?? 0) <= 0)
                    // 2px surface gap between stacked segments.
                    const hh = Math.max(0, y0 - y1 - (si > 0 ? 2 : 0))
                    return v > 0 && <path key={s.key} d={isTop ? barPath(cx - barW / 2, y1, barW, hh) : `M${cx - barW / 2},${y1}h${barW}v${hh}h${-barW}Z`} fill={`var(--c${s.color})`} />
                  }
                  const order = rtl ? groupN - 1 - si : si
                  const x = cx - groupW / 2 + order * (barW + 2)
                  return <path key={s.key} d={barPath(x, yOf(v), barW, PAD_TOP + plotH - yOf(v))} fill={`var(--c${s.color})`} />
                })}
                {(i % showEvery === 0 || i === points.length - 1) && <text x={cx} y={h - 6} textAnchor="middle" className="viz-x">{p.label}</text>}
                {labelLast && i === points.length - 1 && totals[i] > 0 && (
                  <text x={cx} y={Math.max(14, yOf(totals[i]) - 5)} textAnchor="middle" className="viz-val">{compact(totals[i])}</text>
                )}
                <rect x={xOf(i)} y={0} width={slot} height={h} fill="transparent" onMouseEnter={() => setHover(i)} onClick={() => setHover(hover === i ? null : i)} />
              </g>
            )
          })}
          <line x1={plotX0} x2={plotX0 + plotW} y1={PAD_TOP + plotH} y2={PAD_TOP + plotH} className="viz-axis" />
        </svg>
      )}
      {hover !== null && points[hover] && (
        <Tooltip x={xOf(hover) + slot / 2} w={w} title={points[hover].label} rows={series.map((s) => (
          <span key={s.key}><i className="sw" style={{ background: `var(--c${s.color})` }} />{s.label}<b className="num">{format(points[hover].values[s.key] ?? 0)}</b></span>
        ))} />
      )}
      </div>
      <Legend series={series} />
    </div>
  )
}

interface LineProps {
  points: { label: string; value: number; title?: string }[]
  format: (n: number) => string
  ariaLabel: string
}

/** One series over time: 2px line, ~10% wash, end dot with a surface ring and its value. */
export function LineChart({ points, format, ariaLabel }: LineProps) {
  const { lang } = useI18n()
  const rtl = lang === 'ar'
  const compact = useCompact()
  const { ref, w, h } = useSize()
  const [hover, setHover] = useState<number | null>(null)

  const vals = points.length ? points.map((p) => p.value) : [0]
  const min = Math.min(...vals)
  const max = Math.max(...vals, 1)
  const span = max - min || Math.abs(max) * 0.1 || 1
  const ticks = niceTicks(Math.max(0, min - span * 0.25), max + span * 0.1, 3)
  const lo = ticks[0]
  const hi = ticks[ticks.length - 1]
  const axisW = Math.max(...ticks.map((v) => compact(v).length)) * 7 + 10
  const plotW = Math.max(0, w - axisW - 16)
  const plotH = Math.max(0, h - PAD_TOP - PAD_BOTTOM)
  const plotX0 = rtl ? 16 : axisW
  const n = points.length
  const xOf = (i: number) => plotX0 + (n <= 1 ? plotW / 2 : ((rtl ? n - 1 - i : i) / (n - 1)) * plotW)
  const yOf = (v: number) => PAD_TOP + plotH - ((v - lo) / (hi - lo)) * plotH
  const line = points.map((p, i) => `${i ? 'L' : 'M'}${xOf(i)},${yOf(p.value)}`).join('')
  const area = n > 1 ? `${line}L${xOf(n - 1)},${PAD_TOP + plotH}L${xOf(0)},${PAD_TOP + plotH}Z` : ''
  const last = n - 1
  const showEvery = plotW / Math.max(1, n) < 34 ? 2 : 1

  const onMove = (e: React.MouseEvent<SVGRectElement>) => {
    const box = (e.currentTarget.ownerSVGElement as SVGSVGElement).getBoundingClientRect()
    const x = e.clientX - box.left
    let best = 0
    points.forEach((_, i) => { if (Math.abs(xOf(i) - x) < Math.abs(xOf(best) - x)) best = i })
    setHover(best)
  }

  return (
    <div className="viz">
      <div className="viz-plot" ref={ref} onMouseLeave={() => setHover(null)}>
      {w > 0 && h > 0 && n > 0 && (
        <svg width={w} height={h} role="img" aria-label={ariaLabel}>
          {ticks.map((v) => (
            <g key={v}>
              <line x1={plotX0} x2={plotX0 + plotW} y1={yOf(v)} y2={yOf(v)} className="viz-grid" />
              <text x={rtl ? w - 2 : axisW - 6} y={yOf(v)} dy="0.32em" textAnchor="end" className="viz-tick" direction="ltr">{compact(v)}</text>
            </g>
          ))}
          {area && <path d={area} fill="var(--c1)" opacity={0.1} />}
          <path d={line} fill="none" stroke="var(--c1)" strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />
          {points.map((p, i) => (i % showEvery === 0 || i === last) && (
            <text key={p.label + i} x={xOf(i)} y={h - 6} textAnchor="middle" className="viz-x">{p.label}</text>
          ))}
          {hover !== null && <line x1={xOf(hover)} x2={xOf(hover)} y1={PAD_TOP} y2={PAD_TOP + plotH} className="viz-cross" />}
          {[...(hover !== null && hover !== last ? [hover] : []), last].map((i) => (
            <circle key={i} cx={xOf(i)} cy={yOf(points[i].value)} r={4} fill="var(--c1)" stroke="var(--surface)" strokeWidth={2} />
          ))}
          <text x={xOf(last)} y={Math.max(14, yOf(points[last].value) - 9)} textAnchor={rtl ? 'start' : 'end'} className="viz-val">{compact(points[last].value)}</text>
          <rect x={0} y={0} width={w} height={h} fill="transparent" onMouseMove={onMove} onClick={onMove} />
        </svg>
      )}
      {hover !== null && points[hover] && (
        <Tooltip x={xOf(hover)} w={w} title={points[hover].title ?? points[hover].label}
          rows={[<span key="v"><b className="num">{format(points[hover].value)}</b></span>]} />
      )}
      </div>
    </div>
  )
}
