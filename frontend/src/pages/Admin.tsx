import { useMemo, useState } from 'react'
import { select, text } from '../components/fields'
import { Icon } from '../components/Icon'
import { ListHead } from '../components/ListHead'
import { FormModal } from '../components/Modal'
import { Paged } from '../components/Paged'
import { useApi } from '../hooks/useApi'
import { useForm } from '../hooks/useForm'
import { useI18n } from '../i18n'
import { api, ApiError, del, post } from '../lib/api'
import { useSession } from '../lib/session'

interface Member {
  id: number
  name: string
  email: string
  is_admin: boolean
  status: 'pending' | 'active'
  invited_at: string | null
  created_at: string | null
  last_activity_at: string | null
}

/** Admin only: invite clients and testers by email, see who joined. No financial data here. */
export function Admin() {
  const { t, date } = useI18n()
  const { user, refresh, toast } = useSession()
  const { data } = useApi<{ data: Member[] }>('/admin/users')
  const rows = useMemo(() => data?.data ?? [], [data])
  const [inviting, setInviting] = useState(false)
  const [deleting, setDeleting] = useState<Member | null>(null)
  const pending = rows.filter((r) => r.status === 'pending').length

  if (!user?.is_admin) return <div className="empty"><p>{t('error')}</p></div>

  const act = (fn: () => Promise<unknown>, done: string) => fn().then(() => { toast(done); refresh() })
    .catch((e) => toast(e instanceof ApiError && e.data.message ? e.data.message : t('error')))
  const day = (s: string | null) => (s ? date(s.slice(0, 10), { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

  return (
    <div className="list">
      <ListHead>
        <span className="count">{t('membersCount', { n: rows.length })} · {t('pendingCount', { n: pending })}</span>
        <span className="grow" />
        <button type="button" className="btn" onClick={() => setInviting(true)}><Icon name="plus" /><span className="hide-sm">{t('inviteUser')}</span></button>
      </ListHead>
      <Paged
        items={rows}
        empty={<div className="empty"><Icon name="heir" /><p>{t('empty')}</p></div>}
        container={(c) => <div className="rows member-list">{c}</div>}
        renderItem={(m) => (
          <div className="li member" key={m.id}>
            <span className="member-main">
              <b>{m.name}{m.is_admin && <small className="muted"> · {t('adminRole')}</small>}</b>
              <small className="muted" dir="ltr">{m.email}</small>
            </span>
            <span className="member-side">
              <small className="muted">{m.status === 'pending' ? `${t('invitedOn')} ${day(m.invited_at)}` : `${t('lastSeen')} ${day(m.last_activity_at ?? m.created_at)}`}</small>
              <span className={`tag${m.status === 'pending' ? '' : ' quiet'}`}>{t(m.status === 'pending' ? 'invitePending' : 'inviteActive')}</span>
              {m.status === 'pending' && (
                <>
                  <button type="button" className="icon-btn sm" aria-label={t('resendInvitation')} title={t('resendInvitation')}
                    onClick={() => act(() => post(`/admin/invites/${m.id}/resend`, {}), t('inviteSent'))}><Icon name="subscriptions" /></button>
                  <button type="button" className="icon-btn sm" aria-label={t('cancelInvite')} title={t('cancelInvite')}
                    onClick={() => act(() => del(`/admin/invites/${m.id}`), t('saved'))}><Icon name="close" /></button>
                </>
              )}
              {m.status === 'active' && !m.is_admin && (
                <button type="button" className="icon-btn sm danger" aria-label={t('deleteUser')} title={t('deleteUser')}
                  onClick={() => setDeleting(m)}><Icon name="trash" /></button>
              )}
            </span>
          </div>
        )}
      />
      <p className="muted hint admin-note">{t('adminPrivacy')}</p>
      {inviting && <InviteForm onClose={() => setInviting(false)} />}
      {deleting && <DeleteUserForm member={deleting} onClose={() => setDeleting(null)} />}
    </div>
  )
}

function InviteForm({ onClose }: { onClose: () => void }) {
  const { t } = useI18n()
  const { toast, refresh } = useSession()
  const form = useForm({ name: '', email: '', locale: 'ar' }, onClose)
  const submit = () => form.run(async () => {
    await post('/admin/invites', { name: form.f.name, email: String(form.f.email).trim(), locale: form.f.locale })
    toast(t('inviteSent'))
    refresh()
    onClose()
  }, {}, true)
  return (
    <FormModal title={t('inviteUser')} errors={form.errors} busy={form.busy} onClose={onClose} onSubmit={submit} submitLabel={t('sendInvite')} fields={[
      text(form, 'name', t('name'), { maxLength: 120, full: true }),
      text(form, 'email', t('email'), { type: 'email', dir: 'ltr', full: true }),
      select(form, 'locale', t('language'), [['ar', 'العربية'], ['en', 'English']], { full: true }),
      { name: 'hint', label: t('important'), full: true, control: <p className="muted hint" id="f-hint">{t('inviteHint')}</p> },
    ]} />
  )
}

/** Deleting an account is final: the admin types the user's email and their own password. */
function DeleteUserForm({ member, onClose }: { member: Member; onClose: () => void }) {
  const { t } = useI18n()
  const { toast, refresh } = useSession()
  const form = useForm({ confirm_email: '', current_password: '' }, onClose)
  const submit = () => form.run(async () => {
    await api(`/admin/users/${member.id}`, { method: 'DELETE', body: { confirm_email: String(form.f.confirm_email).trim(), current_password: form.f.current_password } })
    toast(t('userDeleted'))
    refresh()
    onClose()
  }, {}, true)
  return (
    <FormModal title={t('deleteUser')} errors={form.errors} busy={form.busy} onClose={onClose} onSubmit={submit} submitLabel={t('deleteForever')} fields={[
      { name: 'warning', label: member.name, full: true, control: <p className="hint neg" id="f-warning">{t('deleteUserWarning', { email: member.email })}</p> },
      text(form, 'confirm_email', t('typeEmailToConfirm'), { type: 'email', dir: 'ltr', full: true, placeholder: member.email }),
      text(form, 'current_password', t('yourPassword'), { type: 'password', dir: 'ltr', full: true }),
    ]} />
  )
}
