import { useEffect, useState } from 'react'
import { api } from '../lib/api'
import { useSession } from '../lib/session'

/** GET a resource; refetches when `path` changes or after any write (session revision). */
export function useApi<T>(path: string | null) {
  const { revision } = useSession()
  const [state, setState] = useState<{ data: T | null; error: boolean; loading: boolean }>({ data: null, error: false, loading: true })

  useEffect(() => {
    if (!path) return
    let live = true
    setState((s) => ({ ...s, loading: true }))
    api<T>(path)
      .then((data) => live && setState({ data, error: false, loading: false }))
      .catch(() => live && setState((s) => ({ ...s, error: true, loading: false })))
    return () => { live = false }
  }, [path, revision])

  return state
}
