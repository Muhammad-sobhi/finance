import { cloneElement, useEffect, useLayoutEffect, useRef, useState, type ReactElement } from 'react'
import { measureLines, observeSize } from '../lib/fit'
import { useLayoutEpoch } from '../hooks/useLayoutEpoch'
import { useI18n } from '../i18n'

interface Props<T> {
  items: T[]
  renderItem: (item: T) => ReactElement<Record<string, unknown>>
  onMore: () => void
  className?: string
}

/** Shows as many items as fit in the box; the rest collapse into a "+N more" link. */
export function FitList<T>({ items, renderItem, onMore, className = 'fit' }: Props<T>) {
  const { t } = useI18n()
  const box = useRef<HTMLDivElement>(null)
  const moreRef = useRef<HTMLButtonElement>(null)
  const [shown, setShown] = useState<number | null>(null)
  const [tick, setTick] = useState(0)

  const epoch = useLayoutEpoch()
  useLayoutEffect(() => { setShown(null) }, [items, tick, epoch])

  useLayoutEffect(() => {
    const el = box.current
    if (shown !== null || !el) return
    const lines = measureLines(el)
    const H = el.clientHeight
    if (!lines.length || lines[lines.length - 1].bottom <= H) { setShown(items.length); return }
    const moreH = moreRef.current?.offsetHeight ?? 24
    let n = 0
    while (n < lines.length && lines[n].bottom + moreH <= H) n++
    setShown(lines[n - 1]?.last + 1 || 0)
  }, [shown, items])

  useEffect(() => {
    const el = box.current
    return el ? observeSize(el, () => setTick((n) => n + 1)) : undefined
  }, [])

  const n = shown ?? items.length
  return (
    <div className={className} ref={box}>
      {items.slice(0, n).map((it) => cloneElement(renderItem(it), { 'data-fi': '' }))}
      {(shown === null || n < items.length) && (
        <button type="button" className="more" ref={moreRef} onClick={onMore}>
          {t('plusMore', { n: items.length - n })}
        </button>
      )}
    </div>
  )
}
