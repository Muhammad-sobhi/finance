import { cloneElement, useCallback, useEffect, useLayoutEffect, useRef, useState, type ReactElement, type ReactNode } from 'react'
import { measureLines, observeSize, packPages } from '../lib/fit'
import { useI18n } from '../i18n'
import { useLayoutEpoch } from '../hooks/useLayoutEpoch'
import { useSwipe } from '../hooks/useSwipe'
import { Icon } from './Icon'

interface Props<T> {
  items: T[]
  renderItem: (item: T, index: number) => ReactElement<Record<string, unknown>>
  /** Wraps the rendered items (e.g. table + thead + tbody, or a card grid). */
  container: (children: ReactNode) => ReactNode
  /** Items that must not be the last thing on a page (group headings). */
  keepWithNext?: (item: T) => boolean
  /** Changing this resets to the first page (filters, month, mode). */
  resetKey?: string
  empty?: ReactNode
}

/**
 * A list that never scrolls: it measures every item at the real width, packs whole
 * lines into pages that fit the available height, and pages with buttons or swipe.
 * Re-measures on resize, orientation change, and data change.
 */
export function Paged<T>({ items, renderItem, container, keepWithNext, resetKey = '', empty }: Props<T>) {
  const { t } = useI18n()
  const bodyRef = useRef<HTMLDivElement>(null)
  const rootRef = useRef<HTMLDivElement>(null)
  const [pages, setPages] = useState<[number, number][] | null>(null)
  const [page, setPage] = useState(0)
  const [measureTick, setMeasureTick] = useState(0)
  const epoch = useLayoutEpoch()
  const retried = useRef(false)

  // New data, a resize, or new font metrics → measure again (render everything once, invisibly).
  useLayoutEffect(() => { setPages(null); retried.current = false }, [items, measureTick, epoch])

  // Safety net: if a packed page still overflows (metrics shifted after measuring), re-measure once.
  useLayoutEffect(() => {
    const body = bodyRef.current
    if (pages === null || !body || retried.current) return
    if (body.scrollHeight > body.clientHeight) {
      retried.current = true
      setPages(null)
    }
  })
  useEffect(() => { setPage(0) }, [resetKey])

  useLayoutEffect(() => {
    const body = bodyRef.current
    if (pages !== null || !body) return
    const lines = measureLines(body)
    if (!lines.length) { setPages([[0, 0]]); return }
    const wrap = body.firstElementChild as HTMLElement | null
    const wrapBottom = wrap ? Math.round(wrap.getBoundingClientRect().bottom - body.getBoundingClientRect().top) : lines[lines.length - 1].bottom
    const head = lines[0].top
    const tail = Math.max(0, wrapBottom - lines[lines.length - 1].bottom)
    setPages(packPages(lines, body.clientHeight, head, tail, keepWithNext ? (i) => keepWithNext(items[i]) : undefined))
  }, [pages, items, keepWithNext])

  useEffect(() => {
    const body = bodyRef.current
    return body ? observeSize(body, () => setMeasureTick((n) => n + 1)) : undefined
  }, [])

  const count = pages?.length ?? 1
  const current = Math.min(page, count - 1)
  const step = useCallback((d: 1 | -1) => setPage((p) => Math.max(0, Math.min(count - 1, Math.min(p, count - 1) + d))), [count])
  useSwipe(rootRef, step)

  const [from, to] = pages ? pages[current] : [0, items.length]
  const visible = items.slice(from, to).map((item, i) => cloneElement(renderItem(item, from + i), { 'data-fi': '' }))

  return (
    <div className="paged" ref={rootRef}>
      <div className={`list-body${pages === null ? ' measuring' : ''}`} ref={bodyRef}>
        {items.length === 0 && pages !== null ? empty : container(visible)}
      </div>
      <nav className="pager" aria-label={t('page', { a: current + 1, b: count })}>
        <button type="button" className="icon-btn" onClick={() => step(-1)} disabled={current === 0} aria-label={t('back')}>
          <Icon name="prev" className="flip" />
        </button>
        <span className="num">{t('page', { a: current + 1, b: count })}</span>
        <button type="button" className="icon-btn" onClick={() => step(1)} disabled={current >= count - 1} aria-label={t('next')}>
          <Icon name="next" className="flip" />
        </button>
      </nav>
    </div>
  )
}
