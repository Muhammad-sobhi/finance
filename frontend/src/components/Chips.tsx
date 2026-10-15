import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react'
import { useLayoutEpoch } from '../hooks/useLayoutEpoch'
import { measureLines, observeSize } from '../lib/fit'

interface Chip { id: number; label: ReactNode; color?: string }

/**
 * Wrapping chips in a fixed-height box. Whatever doesn't fit moves behind a "…" chip
 * that cycles to the next set — the box itself never scrolls.
 */
export function FitChips({ chips, value, onChange, label }: { chips: Chip[]; value: number | null; onChange: (id: number) => void; label: string }) {
  const box = useRef<HTMLDivElement>(null)
  const [offset, setOffset] = useState(0)
  const [count, setCount] = useState<number | null>(null)
  const [tick, setTick] = useState(0)
  const rest = chips.slice(offset)

  const epoch = useLayoutEpoch()
  useLayoutEffect(() => { setCount(null) }, [chips, offset, tick, epoch])
  useLayoutEffect(() => {
    const el = box.current
    if (count !== null || !el) return
    const lines = measureLines(el)
    const H = el.clientHeight
    const fit = lines.filter((l) => l.bottom <= H)
    let n = fit.length ? fit[fit.length - 1].last + 1 : 1
    if (n < rest.length || offset > 0) n = Math.max(1, n - 1) // room for the "…" chip
    setCount(Math.min(n, rest.length))
  }, [count, rest.length, offset])
  useEffect(() => {
    const el = box.current
    return el ? observeSize(el, () => setTick((n) => n + 1)) : undefined
  }, [])

  const shown = count === null ? rest : rest.slice(0, count)
  const paged = count !== null && (count < rest.length || offset > 0)
  return (
    <div className="chips" ref={box} role="radiogroup" aria-label={label}>
      {shown.map((c) => (
        <button type="button" key={c.id} data-fi="" role="radio" aria-checked={value === c.id}
          className={`chip${value === c.id ? ' on' : ''}`} onClick={() => onChange(c.id)}>
          {c.color && <i className="chip-dot" style={{ background: c.color }} />}
          {c.label}
        </button>
      ))}
      {paged && (
        <button type="button" className="chip" aria-label="…"
          onClick={() => setOffset(offset + (count ?? 0) >= chips.length ? 0 : offset + (count ?? 0))}>…</button>
      )}
    </div>
  )
}
