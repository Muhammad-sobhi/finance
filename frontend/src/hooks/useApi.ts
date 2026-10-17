import { useEffect, useState } from 'react'
import { api } from '../lib/api'
import { isNetworkError, offlineCache } from '../lib/offline'
import { useSession } from '../lib/session'

/**
 * GET a resource; refetches when `path` changes or after any write (session revision).
 * With `offline`, a trimmed copy (what that function keeps) is saved on this device and used
 * when there is no connection — only for the lists the add forms need.
 */
export function useApi<T>(path: string | null, opts: { offline?: (data: T) => T } = {}) {
  const { revision, user } = useSession()
  const [state, setState] = useState<{ data: T | null; error: boolean; loading: boolean }>({ data: null, error: false, loading: true })
  const keep = opts.offline
  const userId = user?.id

  useEffect(() => {
    if (!path) return
    let live = true
    setState((s) => ({ ...s, loading: true }))
    api<T>(path)
      .then((data) => {
        if (keep && userId) offlineCache.set(userId, path, keep(data))
        if (live) setState({ data, error: false, loading: false })
      })
      .catch((err) => {
        const cached = keep && userId && isNetworkError(err) ? offlineCache.get<T>(userId, path) : null
        if (live) setState((s) => (cached ? { data: cached, error: false, loading: false } : { ...s, error: true, loading: false }))
      })
    return () => { live = false }
    // `keep` is a pure trim function; a new identity each render must not refetch.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [path, revision, userId])

  return state
}
