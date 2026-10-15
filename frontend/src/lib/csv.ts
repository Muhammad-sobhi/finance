/** Minimal RFC-4180 CSV reader: quotes, escaped quotes, CRLF, byte-order mark; delimiter auto-detected (, ; tab). */
export function parseCsv(text: string): string[][] {
  const src = text.replace(/^\uFEFF/, '')
  const firstLine = src.slice(0, src.indexOf('\n') === -1 ? undefined : src.indexOf('\n'))
  const delim = [',', ';', '\t'].map((d) => [d, firstLine.split(d).length] as const).sort((a, b) => b[1] - a[1])[0][0]
  const rows: string[][] = []
  let row: string[] = []
  let cell = ''
  let quoted = false
  for (let i = 0; i < src.length; i++) {
    const c = src[i]
    if (quoted) {
      if (c === '"' && src[i + 1] === '"') { cell += '"'; i++ }
      else if (c === '"') quoted = false
      else cell += c
    } else if (c === '"') quoted = true
    else if (c === delim) { row.push(cell); cell = '' }
    else if (c === '\n' || c === '\r') {
      if (c === '\r' && src[i + 1] === '\n') i++
      row.push(cell); cell = ''
      if (row.some((x) => x.trim() !== '')) rows.push(row.map((x) => x.trim()))
      row = []
    } else cell += c
  }
  row.push(cell)
  if (row.some((x) => x.trim() !== '')) rows.push(row.map((x) => x.trim()))
  return rows
}

const AR_DIGITS = '٠١٢٣٤٥٦٧٨٩'

/** "1,234.50", "1.234,50", "(300)", "300-", "300 DR", Arabic digits → number (null if not a number). */
export function parseAmount(raw: string): number | null {
  let s = raw.replace(/[٠-٩]/g, (d) => String(AR_DIGITS.indexOf(d))).replace(/٫/g, '.').replace(/٬/g, ',').trim()
  let neg = /^\(.*\)$/.test(s) || /-$/.test(s) || /\bDR\b/i.test(s) || /^[^\d]*-/.test(s)
  if (/\bCR\b/i.test(s)) neg = false
  s = s.replace(/[^\d.,]/g, '')
  if (!s) return null
  const lastDot = s.lastIndexOf('.')
  const lastComma = s.lastIndexOf(',')
  if (lastDot > -1 && lastComma > -1) {
    const dec = lastDot > lastComma ? '.' : ','
    s = s.replace(dec === '.' ? /,/g : /\./g, '').replace(',', '.')
  } else if (lastComma > -1) {
    s = s.length - lastComma - 1 === 2 && s.split(',').length === 2 ? s.replace(',', '.') : s.replace(/,/g, '')
  } else if (s.split('.').length > 2) {
    s = s.replace(/\./g, '')
  }
  const n = Number(s)
  return Number.isFinite(n) ? (neg ? -n : n) : null
}

export type DateFormat = 'ymd' | 'dmy' | 'mdy'

/** Parse a statement date in the chosen order; returns YYYY-MM-DD or null. */
export function parseDate(raw: string, fmt: DateFormat): string | null {
  const parts = raw.replace(/[٠-٩]/g, (d) => String(AR_DIGITS.indexOf(d))).trim().split(/[^\d]+/).filter(Boolean).slice(0, 3).map(Number)
  if (parts.length < 3) return null
  const [yy, m, d] = fmt === 'ymd' ? parts : fmt === 'dmy' ? [parts[2], parts[1], parts[0]] : [parts[2], parts[0], parts[1]]
  const y = yy < 100 ? yy + 2000 : yy
  const dt = new Date(y, m - 1, d)
  if (dt.getFullYear() !== y || dt.getMonth() !== m - 1 || dt.getDate() !== d) return null
  return `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`
}

/** Guess which header is which, in English or Arabic. */
export function guessColumns(header: string[]) {
  const find = (re: RegExp) => header.findIndex((h) => re.test(h))
  return {
    date: find(/date|تاريخ/i),
    note: find(/desc|detail|narr|memo|particular|بيان|وصف|تفاصيل/i),
    amount: find(/^amount$|^المبلغ$|amount|مبلغ/i),
    debit: find(/debit|withdraw|مدين|سحب|خصم/i),
    credit: find(/credit|deposit|دائن|إيداع|ايداع/i),
  }
}
