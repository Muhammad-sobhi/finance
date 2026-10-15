/**
 * The no-scroll engine. Items are rendered once off-page (same width, same styles),
 * measured, and packed into "lines" (elements sharing a top edge — a table row,
 * a row of grid cards, a wrapped row of chips). Pages are then whole lines that fit.
 */
export interface Line {
  top: number
  bottom: number
  first: number // index of the first item in this line
  last: number // index of the last item (inclusive)
}

export function measureLines(container: HTMLElement, selector = '[data-fi]'): Line[] {
  const origin = container.getBoundingClientRect().top + container.clientTop
  const els = Array.from(container.querySelectorAll<HTMLElement>(selector))
  const lines: Line[] = []
  els.forEach((el, i) => {
    const r = el.getBoundingClientRect()
    const top = Math.round(r.top - origin)
    const bottom = Math.round(r.bottom - origin)
    const cur = lines[lines.length - 1]
    if (cur && Math.abs(cur.top - top) <= 1) {
      cur.bottom = Math.max(cur.bottom, bottom)
      cur.last = i
    } else {
      lines.push({ top, bottom, first: i, last: i })
    }
  })
  return lines
}

/**
 * Pack lines into pages of height `avail`. `head` is space above the first line
 * (e.g. a table header), `tail` is space after the last (borders, padding).
 * `keepWithNext(i)` marks items (like day headers) that must not end a page.
 * Returns [start, end) item ranges.
 */
export function packPages(lines: Line[], avail: number, head: number, tail: number, keepWithNext?: (i: number) => boolean): [number, number][] {
  const pages: [number, number][] = []
  let start = 0
  while (start < lines.length) {
    let end = start + 1
    while (end < lines.length && lines[end].bottom - lines[start].top + head + tail <= avail) end++
    // Don't strand a heading at the bottom of a page.
    while (keepWithNext && end - start > 1 && end < lines.length && keepWithNext(lines[end - 1].last)) end--
    pages.push([lines[start].first, lines[end - 1].last + 1])
    start = end
  }
  return pages.length ? pages : [[0, 0]]
}

/** Observe an element's size; calls back (debounced to a frame) on any change. */
export function observeSize(el: Element, cb: () => void): () => void {
  let frame = 0
  let w = -1
  let h = -1
  const ro = new ResizeObserver((entries) => {
    const r = entries[0].contentRect
    if (Math.round(r.width) === w && Math.round(r.height) === h) return
    const first = w === -1
    w = Math.round(r.width)
    h = Math.round(r.height)
    if (first) return
    cancelAnimationFrame(frame)
    frame = requestAnimationFrame(cb)
  })
  ro.observe(el)
  return () => { cancelAnimationFrame(frame); ro.disconnect() }
}
