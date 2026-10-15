import { useEffect, useMemo, useState } from 'react'
import { text } from '../components/fields'
import { Icon } from '../components/Icon'
import { DeleteButton, FormModal } from '../components/Modal'
import { Paged } from '../components/Paged'
import { useApi } from '../hooks/useApi'
import { num, orNull, useForm } from '../hooks/useForm'
import { useI18n } from '../i18n'
import { ApiError, del, patch, post } from '../lib/api'
import { useSession } from '../lib/session'

interface HeirP { id: number; name: string; relation: string | null; phone: string | null; email: string; national_id: string | null; address: string | null; notes: string | null; has_login: boolean; invited_at: string | null }
interface Contact { id: number; name: string; email: string; phone: string | null; relation: string | null }
interface Req { id: number; heir: string | null; status: 'pending' | 'confirmed' | 'cancelled' | 'activated'; contact: string | null; created_at: string; activates_at: string | null }
interface Status {
  heir_enabled: boolean; heir_inactivity_days: number; heir_grace_days: number; last_activity_at: string; inactive_days: number
  days_until_warning: number; grace_ends_at: string | null; activated_at: string | null; state: 'off' | 'armed' | 'warning' | 'triggered'
}
interface HeirData { settings: Status; heirs: HeirP[]; trusted: Contact[]; requests: Req[] }

type ListTab = 'heirs' | 'trusted' | 'requests'
type Tab = 'status' | ListTab

export function HeirPage() {
  const { t, date } = useI18n()
  const { readOnly, refresh, toast } = useSession()
  const { data } = useApi<HeirData>('/heir')
  const [tab, setTab] = useState<ListTab>('heirs')
  const [phoneTab, setPhoneTab] = useState<Tab>('status')
  const [cfg, setCfg] = useState({ heir_enabled: false, heir_inactivity_days: '60', heir_grace_days: '14' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [editHeir, setEditHeir] = useState<HeirP | null | undefined>(undefined)
  const [editContact, setEditContact] = useState<Contact | null | undefined>(undefined)
  const s = data?.settings
  const heirs = useMemo(() => data?.heirs ?? [], [data])
  const trusted = useMemo(() => data?.trusted ?? [], [data])
  const requests = useMemo(() => data?.requests ?? [], [data])
  // Desktop shows status + one list; phones show one of the four as a tab.
  const listTab: ListTab = phoneTab === 'status' ? tab : phoneTab

  useEffect(() => {
    if (s) setCfg({ heir_enabled: s.heir_enabled, heir_inactivity_days: String(s.heir_inactivity_days), heir_grace_days: String(s.heir_grace_days) })
  }, [s])

  const call = async (fn: () => Promise<unknown>) => {
    try {
      await fn()
      setErrors({})
      toast(t('saved'))
      refresh()
    } catch (err) {
      if (err instanceof ApiError && err.data.errors) setErrors(err.fieldErrors)
      else toast(err instanceof ApiError && err.data.message ? err.data.message : t('error'))
    }
  }
  const saveCfg = () => call(() => patch('/heir/settings', { heir_enabled: cfg.heir_enabled, heir_inactivity_days: num(cfg.heir_inactivity_days), heir_grace_days: num(cfg.heir_grace_days) }))

  const stateTag = s && <span className={`tag${s.state === 'triggered' || s.state === 'warning' ? ' late' : s.state === 'off' ? ' quiet' : ''}`}>{t(`state_${s.state}`)}</span>
  const reqTag = (r: Req) => <span className={`tag${r.status === 'confirmed' ? ' late' : r.status === 'pending' ? '' : ' quiet'}`}>{t(`req_${r.status}`)}</span>
  const tabs: ListTab[] = ['heirs', 'trusted', 'requests']
  const tabLabel = (k: Tab) => (k === 'status' ? t('switchStatus') : k === 'requests' ? t('pendingRequests') : t(k))

  return (
    <div className="list heir-page">
      <div className="list-head show-sm">
        <div className="seg seg-wrap" role="radiogroup">
          {(['status', ...tabs] as Tab[]).map((k) => (
            <button type="button" key={k} role="radio" aria-checked={phoneTab === k} className={phoneTab === k ? 'on' : ''} onClick={() => setPhoneTab(k)}>{tabLabel(k)}</button>
          ))}
        </div>
      </div>
      <div className="split">
        <div className={`card${phoneTab !== 'status' ? ' off-sm' : ''}`}>
          <h3>{t('switchStatus')}{stateTag}</h3>
          {s && (
            <>
              <div className="setting-row"><span>{t('lastActivity')}</span><b>{date(s.last_activity_at.slice(0, 10))}</b></div>
              <div className="setting-row">
                <span>{t('inactiveFor', { n: s.inactive_days })}</span>
                <b className="muted">{s.grace_ends_at ? t('graceEnds', { date: date(s.grace_ends_at.slice(0, 10)) }) : s.heir_enabled && s.state === 'armed' ? t('warnIn', { n: s.days_until_warning }) : ''}</b>
              </div>
              <div className="setting-row">
                <label htmlFor="h-enabled">{t('heirEnabled')}</label>
                <input id="h-enabled" type="checkbox" disabled={readOnly} checked={cfg.heir_enabled} onChange={(e) => setCfg({ ...cfg, heir_enabled: e.target.checked })} />
              </div>
              <div className="setting-row">
                <label htmlFor="h-days">{t('inactivityDays')}{errors.heir_inactivity_days && <span className="err"> — {errors.heir_inactivity_days}</span>}</label>
                <input id="h-days" className="inp num" inputMode="numeric" disabled={readOnly} value={cfg.heir_inactivity_days} onChange={(e) => setCfg({ ...cfg, heir_inactivity_days: e.target.value })} />
              </div>
              <div className="setting-row">
                <label htmlFor="h-grace">{t('graceDays')}{errors.heir_grace_days && <span className="err"> — {errors.heir_grace_days}</span>}</label>
                <input id="h-grace" className="inp num" inputMode="numeric" disabled={readOnly} value={cfg.heir_grace_days} onChange={(e) => setCfg({ ...cfg, heir_grace_days: e.target.value })} />
              </div>
              {!readOnly && (
                <div className="actions">
                  {s.state === 'triggered' && <button type="button" className="btn danger" onClick={() => call(() => post('/heir/revoke', {}))}>{t('revokeAccess')}</button>}
                  <button type="button" className="btn ghost" onClick={saveCfg}>{t('save')}</button>
                  <button type="button" className="btn" onClick={() => call(() => post('/heir/check-in', {}))}>{t('checkIn')}</button>
                </div>
              )}
            </>
          )}
        </div>

        <div className={`card${phoneTab === 'status' ? ' off-sm' : ''}`}>
          <div className="card-tabs hide-sm">
            <div className="seg" role="radiogroup">
              {tabs.map((k) => (
                <button type="button" key={k} role="radio" aria-checked={listTab === k} className={listTab === k ? 'on' : ''} onClick={() => { setTab(k); setPhoneTab(k) }}>{tabLabel(k)}</button>
              ))}
            </div>
          </div>
          {listTab === 'heirs' && (
            <Paged items={heirs} resetKey="heirs" empty={<p className="muted none">{t('empty')}</p>}
              container={(c) => <div className="rows">{c}</div>}
              renderItem={(h) => (
                <button type="button" key={h.id} className="li-btn" onClick={() => setEditHeir(h)}>
                  <span>{h.name}{h.relation ? ` · ${h.relation}` : ''} <small className="muted" dir="ltr">{h.email}</small></span>
                  {h.has_login ? <span className="tag quiet">{t('hasLogin')}</span> : <span />}
                </button>
              )} />
          )}
          {listTab === 'trusted' && (
            <Paged items={trusted} resetKey="trusted" empty={<p className="muted none">{t('empty')}</p>}
              container={(c) => <div className="rows">{c}</div>}
              renderItem={(c) => (
                <button type="button" key={c.id} className="li-btn" onClick={() => setEditContact(c)}>
                  <span>{c.name}{c.relation ? ` · ${c.relation}` : ''}</span>
                  <small className="muted" dir="ltr">{c.email}</small>
                </button>
              )} />
          )}
          {listTab === 'requests' && (
            <Paged items={requests} resetKey="requests" empty={<p className="muted none">{t('empty')}</p>}
              container={(c) => <div className="rows">{c}</div>}
              renderItem={(r) => (
                <div className="li" key={r.id}>
                  <span>{r.heir} · {date(r.created_at.slice(0, 10), { day: 'numeric', month: 'short' })}{r.activates_at && r.status === 'confirmed' ? ` · ${t('activatesOn', { date: date(r.activates_at.slice(0, 10), { day: 'numeric', month: 'short' }) })}` : ''}</span>
                  <span>
                    {reqTag(r)}
                    {!readOnly && (r.status === 'pending' || r.status === 'confirmed') && (
                      <button type="button" className="btn sm danger" onClick={() => call(() => post(`/heir-requests/${r.id}/cancel`, {}))}>{t('cancelRequest')}</button>
                    )}
                  </span>
                </div>
              )} />
          )}
          {!readOnly && listTab !== 'requests' && (
            <div className="actions">
              <button type="button" className="btn" onClick={() => (listTab === 'heirs' ? setEditHeir(null) : setEditContact(null))}>
                <Icon name="plus" />{listTab === 'heirs' ? t('newHeir') : t('newContact')}
              </button>
            </div>
          )}
        </div>
      </div>
      {editHeir !== undefined && <HeirForm heir={editHeir} active={s?.state === 'triggered'} onClose={() => setEditHeir(undefined)} />}
      {editContact !== undefined && <ContactForm contact={editContact} onClose={() => setEditContact(undefined)} />}
    </div>
  )
}

function HeirForm({ heir, active, onClose }: { heir: HeirP | null; active: boolean; onClose: () => void }) {
  const { t } = useI18n()
  const { readOnly, toast } = useSession()
  const form = useForm({
    name: heir?.name ?? '', relation: heir?.relation ?? '', phone: heir?.phone ?? '', email: heir?.email ?? '',
    national_id: heir?.national_id ?? '', address: heir?.address ?? '', notes: heir?.notes ?? '',
  }, onClose)
  const f = form.f
  const submit = () => form.run(() => {
    const body = { name: f.name, relation: orNull(f.relation), phone: orNull(f.phone), email: f.email, national_id: orNull(f.national_id), address: orNull(f.address), notes: orNull(f.notes) }
    return heir ? patch(`/heirs/${heir.id}`, body) : post('/heirs', body)
  })
  return (
    <FormModal title={heir ? t('editHeir') : t('newHeir')} errors={form.errors} busy={form.busy || readOnly} onClose={onClose} onSubmit={submit}
      extra={heir && !readOnly ? (
        <>
          <DeleteButton busy={form.busy} onConfirm={() => form.run(() => del(`/heirs/${heir.id}`))} />
          {active && <button type="button" className="btn ghost" onClick={() => post(`/heirs/${heir.id}/invite`, {}).then(() => toast(t('saved'))).catch(() => toast(t('error')))}>{t('resendInvite')}</button>}
        </>
      ) : undefined}
      fields={[
        text(form, 'name', t('name'), { maxLength: 120 }),
        text(form, 'relation', t('relation'), { maxLength: 60 }),
        text(form, 'email', t('email'), { type: 'email', dir: 'ltr', maxLength: 255 }),
        text(form, 'phone', t('phone'), { dir: 'ltr', inputMode: 'numeric', maxLength: 32 }),
        text(form, 'national_id', t('nationalId'), { dir: 'ltr', inputMode: 'numeric', maxLength: 32 }),
        text(form, 'address', t('address'), { maxLength: 500 }),
        text(form, 'notes', t('notes'), { full: true, maxLength: 2000 }),
      ]} />
  )
}

function ContactForm({ contact, onClose }: { contact: Contact | null; onClose: () => void }) {
  const { t } = useI18n()
  const { readOnly } = useSession()
  const form = useForm({ name: contact?.name ?? '', email: contact?.email ?? '', phone: contact?.phone ?? '', relation: contact?.relation ?? '' }, onClose)
  const f = form.f
  const submit = () => form.run(() => {
    const body = { name: f.name, email: f.email, phone: orNull(f.phone), relation: orNull(f.relation) }
    return contact ? patch(`/trusted-contacts/${contact.id}`, body) : post('/trusted-contacts', body)
  })
  return (
    <FormModal title={contact ? t('editContact') : t('newContact')} errors={form.errors} busy={form.busy || readOnly} onClose={onClose} onSubmit={submit}
      extra={contact && !readOnly ? <DeleteButton busy={form.busy} onConfirm={() => form.run(() => del(`/trusted-contacts/${contact.id}`))} /> : undefined}
      fields={[
        text(form, 'name', t('name'), { maxLength: 120 }),
        text(form, 'email', t('email'), { type: 'email', dir: 'ltr', maxLength: 255 }),
        text(form, 'phone', t('phone'), { dir: 'ltr', inputMode: 'numeric', maxLength: 32 }),
        text(form, 'relation', t('relation'), { maxLength: 60 }),
      ]} />
  )
}
