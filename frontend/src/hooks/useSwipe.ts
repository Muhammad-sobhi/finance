import { useEffect, type RefObject } from 'react'
import { useI18n } from '../i18n'

/**
 * Horizontal swipe on an element → page step. In RTL, swiping right goes forward
 * (content flows right-to-left), matching the prototype.
 */
export function useSwipe(ref: RefObject<HTMLElement | null>, step: (dir: 1 | -1) => void) {
  const { lang } = useI18n()
  useEffect(() => {
    const el = ref.current
    if (!el) return
    let sx: number | null = null
    let sy = 0
    const start = (e: TouchEvent) => { sx = e.touches[0].clientX; sy = e.touches[0].clientY }
    const end = (e: TouchEvent) => {
      if (sx === null) return
      const dx = e.changedTouches[0].clientX - sx
      const dy = e.changedTouches[0].clientY - sy
      sx = null
      if (Math.abs(dx) < 50 || Math.abs(dx) < Math.abs(dy)) return
      const forward = lang === 'ar' ? dx > 0 : dx < 0
      step(forward ? 1 : -1)
    }
    el.addEventListener('touchstart', start, { passive: true })
    el.addEventListener('touchend', end, { passive: true })
    return () => {
      el.removeEventListener('touchstart', start)
      el.removeEventListener('touchend', end)
    }
  }, [ref, step, lang])
}
