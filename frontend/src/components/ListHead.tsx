import type { ReactNode } from 'react'
import { useI18n } from '../i18n'
import { useIsPhone } from '../hooks/useMedia'
import { useSession } from '../lib/session'

/** Table ⇄ Cards toggle (desktop/tablet only; phones always get cards). */
export function ModeToggle() {
  const { t } = useI18n()
  const { listMode, setListMode } = useSession()
  const phone = useIsPhone()
  if (phone) return null
  return (
    <div className="seg" role="radiogroup" aria-label={`${t('tableV')} / ${t('cardV')}`}>
      <button type="button" role="radio" aria-checked={listMode === 'table'} className={listMode === 'table' ? 'on' : ''} onClick={() => setListMode('table')}>{t('tableV')}</button>
      <button type="button" role="radio" aria-checked={listMode === 'cards'} className={listMode === 'cards' ? 'on' : ''} onClick={() => setListMode('cards')}>{t('cardV')}</button>
    </div>
  )
}

export function useListMode() {
  const { listMode } = useSession()
  return useIsPhone() ? 'cards' : listMode
}

export function ListHead({ children }: { children: ReactNode }) {
  return <div className="list-head">{children}</div>
}
