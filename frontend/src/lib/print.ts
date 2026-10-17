import type { I18n } from '../i18n'
import type { Report } from './types'

const esc = (s: string) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] as string)

/**
 * A plain, printable report (Print → Save as PDF) opened in a new tab. Built from data the
 * page already has; every value is escaped. Printing a separate document keeps the app's
 * no-scroll screens untouched.
 */
export function printReport(r: Report, i: I18n, label: string, catLabel: (c: Report['by_category'][number]) => string) {
  const { t, num, d, lang } = i
  const cur = r.base_currency
  const pct = (v: number | null) => (v === null ? '—' : `${v >= 0 ? '▲' : '▼'} ${num(Math.abs(v), 1)}%`)
  // Header row: first column is text, the rest are numbers (end-aligned like their cells).
  const head = (cells: string[]) => `<tr>${cells.map((c, n) => `<th${n ? ' class="n"' : ''}>${esc(c)}</th>`).join('')}</tr>`
  const html = `<!doctype html><html lang="${lang}" dir="${lang === 'ar' ? 'rtl' : 'ltr'}"><head><meta charset="utf-8">
<title>${esc(`${t('app')} — ${t('reports')} — ${label}`)}</title>
<style>
  body { font: 13px/1.5 system-ui, "Segoe UI", Tahoma, sans-serif; color: #111; margin: 24px; }
  h1 { font-size: 20px; margin: 0 0 4px; } h2 { font-size: 15px; margin: 22px 0 6px; border-bottom: 1px solid #999; padding-bottom: 3px; }
  .muted { color: #555; } table { width: 100%; border-collapse: collapse; } th, td { text-align: start; padding: 4px 6px; border-bottom: 1px solid #ddd; }
  td.n, th.n { text-align: end; font-variant-numeric: tabular-nums; } .kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
  .kpis div { border: 1px solid #ccc; padding: 8px; } .kpis b { display: block; font-size: 16px; } ul { padding-inline-start: 18px; }
  @page { margin: 14mm; } @media print { body { margin: 0; } }
</style></head><body>
<h1>${esc(t('reports'))} — ${esc(label)}</h1>
<div class="muted">${esc(`${r.from} → ${r.to} · ${cur}`)}</div>
<h2>${esc(t('overview'))}</h2>
<div class="kpis">
  <div>${esc(t('totalIncome'))}<b>${num(r.totals.income)}</b><span class="muted">${pct(r.change.income)}</span></div>
  <div>${esc(t('totalExpense'))}<b>${num(r.totals.expense)}</b><span class="muted">${pct(r.change.expense)}</span></div>
  <div>${esc(t('savings'))}<b>${num(r.totals.savings)}</b><span class="muted">${pct(r.change.savings)}</span></div>
  <div>${esc(t('savingsRate'))}<b>${r.totals.savings_rate !== null ? `${num(r.totals.savings_rate, 1)}%` : '—'}</b><span class="muted">${esc(r.growth.growing ? t('growing') : t('notGrowing'))}</span></div>
</div>
<h2>${esc(t('byCategory'))}</h2>
<table>${head([t('category'), t('amount'), '%', t('vsPrev')])}
${r.by_category.map((c) => `<tr><td>${esc(catLabel(c))}</td><td class="n">${num(c.amount)}</td><td class="n">${num(c.share)}%</td><td class="n">${pct(c.change)}</td></tr>`).join('')}</table>
<h2>${esc(t('incomeVsExpense'))}</h2>
<table>${head([t('month'), t('income'), t('expense'), t('savings')])}
${r.series.map((s) => `<tr><td>${esc(d.months[Number(s.month.slice(5, 7)) - 1])} ${s.month.slice(0, 4)}</td><td class="n">${num(s.income)}</td><td class="n">${num(s.expense)}</td><td class="n">${num(s.income - s.expense)}</td></tr>`).join('')}</table>
${r.advice.length ? `<h2>${esc(t('advice'))}</h2><ul>${r.advice.map((a) => `<li>${esc(a.text[lang])}</li>`).join('')}</ul>` : ''}
</body></html>`
  const url = URL.createObjectURL(new Blob([html], { type: 'text/html' }))
  const w = window.open(url, '_blank')
  if (w) w.addEventListener('load', () => { w.focus(); w.print() })
  setTimeout(() => URL.revokeObjectURL(url), 60000)
}
