import { useState } from 'react'
import { useI18n } from '../i18n'
import { useFunds, shortfallOf } from '../components/FundsPrompt'
import { ApiError } from '../lib/api'
import { useSession } from '../lib/session'

/** Form state + a submit runner that toasts, refreshes all views and maps 422 errors to fields. */
export function useForm<T extends Record<string, unknown>>(initial: T, onDone: () => void) {
  const i = useI18n()
  const { t } = i
  const { refresh, toast } = useSession()
  const funds = useFunds()
  const [f, setF] = useState<T>(initial)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)

  const put = (k: keyof T, v: unknown) => setF((prev) => ({ ...prev, [k]: v }))
  const set = (k: keyof T) => (e: { target: { value: string } }) => put(k, e.target.value)

  /**
   * `quiet`: a step inside a flow — map errors, but no toast/refresh/close on success.
   * A payment from a short account first asks to top it up (then retries) or to pick another.
   */
  const run = async (fn: () => Promise<unknown>, codes: Record<string, string> = {}, quiet = false) => {
    setBusy(true)
    try {
      await funds(fn)
      if (!quiet) {
        toast(t('saved'))
        refresh()
        onDone()
      }
    } catch (err) {
      const short = shortfallOf(err)
      if (short) setErrors({ account_id: t('fundsField', { n: i.money(short.shortfall, short.currency) }) })
      else if (err instanceof ApiError && err.data.code && codes[err.data.code]) toast(codes[err.data.code])
      else if (err instanceof ApiError && err.data.errors) setErrors(err.fieldErrors)
      else toast(err instanceof ApiError && err.data.message ? err.data.message : t('error'))
    } finally {
      setBusy(false)
    }
  }

  return { f, setF, set, put, errors, busy, run }
}

/** '' → null, else a number (accepts Arabic-Indic digits and comma decimals). */
export function num(v: unknown): number | null {
  const s = String(v ?? '').trim().replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d))).replace(/[٫,]/g, '.')
  return s === '' ? null : Number(s)
}

export const orNull = (v: unknown) => (v === '' || v === undefined ? null : v)
