import { useEffect, useState } from 'react'
import { NavLink } from 'react-router-dom'
import { useI18n } from '../i18n'
import { api } from '../lib/api'
import { useSession } from '../lib/session'
import { Icon } from './Icon'

/** Top-bar bell with an unread dot; polls every 5 minutes and after any write. */
export function Bell() {
  const { t } = useI18n()
  const { revision } = useSession()
  const [unread, setUnread] = useState(0)
  const [tick, setTick] = useState(0)

  useEffect(() => {
    const id = setInterval(() => setTick((n) => n + 1), 5 * 60 * 1000)
    const onFocus = () => setTick((n) => n + 1)
    window.addEventListener('focus', onFocus)
    return () => { clearInterval(id); window.removeEventListener('focus', onFocus) }
  }, [])

  useEffect(() => {
    api<{ unread: number }>('/notifications/unread').then((r) => setUnread(r.unread)).catch(() => undefined)
  }, [revision, tick])

  return (
    <NavLink to="/notifications" className="icon-btn" aria-label={unread ? `${t('notifications')} — ${t('unreadCount', { n: unread })}` : t('notifications')} title={t('notifications')}>
      <Icon name="bell" />
      {unread > 0 && <span className="dot" />}
    </NavLink>
  )
}
