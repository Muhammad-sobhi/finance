import { useEffect, useState } from 'react'
import { useSession } from '../lib/session'

/**
 * Changes whenever text metrics may have changed without the container resizing:
 * web fonts finishing loading, theme switch (different fonts), language switch.
 * Fit/paging components re-measure when it changes.
 */
export function useLayoutEpoch(): string {
  const { theme, lang } = useSession()
  const [fonts, setFonts] = useState(0)
  useEffect(() => {
    const bump = () => setFonts((n) => n + 1)
    document.fonts?.addEventListener?.('loadingdone', bump)
    document.fonts?.ready.then(bump)
    return () => document.fonts?.removeEventListener?.('loadingdone', bump)
  }, [])
  return `${theme}|${lang}|${fonts}`
}
