import { useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import { DueTag } from '../components/Due'
import { Icon } from '../components/Icon'
import { ListHead } from '../components/ListHead'
import { Paged } from '../components/Paged'
import { useApi } from '../hooks/useApi'
import { useI18n } from '../i18n'
import { dueSubject } from '../lib/alerts'
import { post } from '../lib/api'
import { daysUntil } from '../lib/dates'
import { useSession } from '../lib/session'

/** Notices that need attention get the strong tag. */
const NOTICE_LATE = new Set(['new_sign_in', 'password_reset', 'budget_over'])

interface Note {
  id: string
  read_at: string | null
  created_at: string
  data: {
    type?: 'heir' | 'info'; kind: string; path: string
    // due alerts
    name: string; stage: 'before' | 'due' | 'overdue'; days: number; amount: number; currency: string; due_date: string
    // heir-system messages and one-off notices (budget, new sign-in…) carry their text in both languages
    title?: { ar: string; en: string } | string; text?: { ar: string; en: string }
  }
}

export function Notifications() {
  const i = useI18n()
  const { t, num, date } = i
  const { refresh, readOnly } = useSession()
  const nav = useNavigate()
  const { data } = useApi<{ data: Note[]; meta: { unread: number } }>('/notifications')
  const rows = useMemo(() => data?.data ?? [], [data])
  const unread = data?.meta.unread ?? 0

  const open = async (n: Note) => {
    if (!n.read_at && !readOnly) await post(`/notifications/${n.id}/read`, {}).catch(() => undefined)
    refresh()
    nav(n.data.path || '/')
  }

  return (
    <div className="list">
      <ListHead>
        <span className="count">{t('unreadCount', { n: unread })}</span>
        <span className="grow" />
        {!readOnly && unread > 0 && (
          <button type="button" className="btn ghost" onClick={() => post('/notifications/read-all', {}).then(refresh)}>{t('markAllRead')}</button>
        )}
      </ListHead>
      <Paged
        items={rows}
        empty={<div className="empty"><Icon name="bell" /><p>{t('noAlerts')}</p></div>}
        container={(c) => <div className="rows note-list">{c}</div>}
        renderItem={(n) => (
          <button type="button" key={n.id} className={`li-btn note${n.read_at ? '' : ' unread'}`} onClick={() => open(n)}>
            {typeof n.data.title === 'object' ? (
              <span className="note-main">
                <b>{n.data.title[i.lang]}</b>
                <span>{n.data.text?.[i.lang]}</span>
              </span>
            ) : (
              <span className="note-main">
                <b>{t(`stage_${n.data.stage}`)}</b>
                <span>{dueSubject(i, n.data.kind, n.data.name)} — <span className="num">{num(n.data.amount)}</span> {n.data.currency}</span>
              </span>
            )}
            <span className="note-side">
              {n.data.type === 'heir' ? <span className="tag late">{t('heir')}</span>
                : n.data.type === 'info' ? <span className={`tag${NOTICE_LATE.has(n.data.kind) ? ' late' : ' quiet'}`}>{t(`notice_${n.data.kind}`)}</span>
                : <DueTag days={daysUntil(n.data.due_date)} />}
              <small className="muted">{date(n.created_at.slice(0, 10), { day: 'numeric', month: 'short' })}</small>
            </span>
          </button>
        )}
      />
    </div>
  )
}
