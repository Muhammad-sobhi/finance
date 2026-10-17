import { useEffect, useState } from 'react'
import { useI18n } from '../i18n'
import { outbox, syncOutbox, useOnline, useOutbox, type OutboxItem } from '../lib/offline'
import { primeOfflineLists } from '../lib/offlineLists'
import { useSession } from '../lib/session'
import { DetailModal } from './DetailModal'
import { Icon } from './Icon'
import { Paged } from './Paged'

/**
 * Connection status under the header: "you're offline, what you add is kept and sent later",
 * "sending N…", or "N not saved" (opens the list). Also sends the waiting entries: when the
 * connection comes back, when the app is opened, and every 30 s while any are waiting.
 */
export function OfflineBar() {
  const { t } = useI18n()
  const { user, refresh, toast } = useSession()
  const online = useOnline()
  const items = useOutbox(user?.id)
  const waiting = items.filter((i) => !i.error)
  const failed = items.filter((i) => i.error)
  const [open, setOpen] = useState(false)

  const userId = user?.id
  const hasWaiting = waiting.length > 0
  useEffect(() => { if (userId && online) primeOfflineLists(userId) }, [userId, online])

  useEffect(() => {
    if (!userId || !online || !hasWaiting) return
    const run = () => syncOutbox(userId).then((n) => {
      if (n > 0) { toast(t('syncedN', { n })); refresh() }
    })
    run()
    const id = window.setInterval(run, 30000)
    const onShow = () => document.visibilityState === 'visible' && run()
    document.addEventListener('visibilitychange', onShow)
    return () => { window.clearInterval(id); document.removeEventListener('visibilitychange', onShow) }
  }, [userId, online, hasWaiting, refresh, toast, t])

  if (!online) {
    return (
      <div className="net-bar off" role="status">
        <Icon name="offline" />
        <span>{t('offlineNote')}{waiting.length > 0 && <b> — {t('waitingN', { n: waiting.length })}</b>}</span>
      </div>
    )
  }
  if (!items.length) return null
  return (
    <>
      <div className="net-bar" role="status">
        {waiting.length > 0 && <span>{t('sendingN', { n: waiting.length })}</span>}
        {failed.length > 0 && (
          <button type="button" className="more" onClick={() => setOpen(true)}>{t('notSavedN', { n: failed.length })}</button>
        )}
      </div>
      {open && <FailedList items={failed} onClose={() => setOpen(false)} />}
    </>
  )
}

/** Entries the server refused (e.g. the account was deleted meanwhile): send again or discard. */
function FailedList({ items, onClose }: { items: OutboxItem[]; onClose: () => void }) {
  const { t, money, date } = useI18n()
  useEffect(() => { if (!items.length) onClose() }, [items.length, onClose])
  return (
    <DetailModal title={t('notSavedTitle')} onClose={onClose}>
      <p className="muted">{t('notSavedHint')}</p>
      <Paged items={items}
        container={(c) => <div className="rows">{c}</div>}
        renderItem={(it) => (
          <div className="li" key={it.client_id}>
            <span>
              <b className="num">{money(it.label.amount, it.label.currency)}</b>
              {it.label.category ? ` · ${it.label.category}` : ''} · {date(String(it.body.date))}
              <br /><small className="neg">{it.error}</small>
            </span>
            <span>
              <button type="button" className="btn ghost sm" onClick={() => outbox.retry(it.client_id)}>{t('retry')}</button>
              <button type="button" className="icon-btn sm" aria-label={t('discard')} title={t('discard')}
                onClick={() => outbox.remove(it.client_id)}>
                <Icon name="close" />
              </button>
            </span>
          </div>
        )} />
    </DetailModal>
  )
}
