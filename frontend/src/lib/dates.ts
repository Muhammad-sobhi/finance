const pad = (n: number) => String(n).padStart(2, '0')

/** Local calendar date as YYYY-MM-DD (not UTC — "today" is the user's today). */
export const isoDate = (d = new Date()) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`

export const thisMonth = () => isoDate().slice(0, 7)

export function shiftMonth(month: string, by: number): string {
  const [y, m] = month.split('-').map(Number)
  const d = new Date(y, m - 1 + by, 1)
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}`
}

export const monthIndex = (month: string) => Number(month.slice(5, 7)) - 1

/** Whole days from today to `date` (negative = past). */
export const daysUntil = (date: string | null | undefined): number | null =>
  date ? Math.round((new Date(date + 'T00:00:00').getTime() - new Date(isoDate() + 'T00:00:00').getTime()) / 86400000) : null
