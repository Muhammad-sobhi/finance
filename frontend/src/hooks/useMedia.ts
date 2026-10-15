import { useSyncExternalStore } from 'react'

const subscribe = (cb: () => void) => {
  window.addEventListener('resize', cb)
  window.addEventListener('orientationchange', cb)
  return () => {
    window.removeEventListener('resize', cb)
    window.removeEventListener('orientationchange', cb)
  }
}

/** Phone layout (bottom nav, cards only) — matches the 720px breakpoint in theme.css. */
export const useIsPhone = () => useSyncExternalStore(subscribe, () => window.innerWidth <= 720)
