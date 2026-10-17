import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { currency, money, text } from '../components/fields'
import { DataTools } from '../components/DataTools'
import { DetailModal } from '../components/DetailModal'
import { Icon } from '../components/Icon'
import { AppLockRow } from '../components/AppLockSettings'
import { TwoFactorRow } from '../components/TwoFactorSettings'
import { DeleteButton, FormModal } from '../components/Modal'
import { Paged } from '../components/Paged'
import { useApi } from '../hooks/useApi'
import { num, useForm } from '../hooks/useForm'
import { useI18n } from '../i18n'
import { ApiError, del, patch, post, put } from '../lib/api'
import { disablePush, enablePush, pushState, type PushState } from '../lib/push'
import { useSession } from '../lib/session'
import type { Settings as S } from '../lib/types'

type Rate = S['rates'][number]

export function Settings() {
  const { t, num: n, date: fmtDate } = useI18n()
  const { readOnly, refresh, toast } = useSession()
  const { data } = useApi<S>('/settings')
  const [tab, setTab] = useState<'general' | 'rates' | 'security' | 'data'>('general')
  const [f, setF] = useState<Record<string, string | boolean>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [rate, setRate] = useState<Rate | null | undefined>(undefined)
  const rates = useMemo(() => data?.rates ?? [], [data])

  useEffect(() => {
    if (data) setF({
      gold_price_24k: String(data.gold_price_24k), silver_price: String(data.silver_price), alert_days: String(data.alert_days),
      email_alerts: data.email_alerts, auto_fetch_rates: data.auto_fetch_rates, auto_metal_prices: data.auto_metal_prices,
    })
  }, [data])

  const save = async (e: FormEvent) => {
    e.preventDefault()
    try {
      await patch('/settings', {
        gold_price_24k: num(f.gold_price_24k) ?? 0, silver_price: num(f.silver_price) ?? 0, alert_days: num(f.alert_days) ?? 0,
        email_alerts: f.email_alerts, auto_metal_prices: f.auto_metal_prices,
      })
      setErrors({})
      toast(t('saved'))
      refresh()
    } catch (err) {
      if (err instanceof ApiError) setErrors(err.fieldErrors)
      else toast(t('error'))
    }
  }

  const refreshMetals = () => post('/settings/metal-prices', {}).then(() => { toast(t('saved')); refresh() }).catch((err) => {
    const code = err instanceof ApiError ? err.data.code : null
    toast(code === 'usd_rate_missing' ? t('needUsdRate') : code === 'metals_unavailable' ? t('metalsDown') : t('error'))
  })

  const row = (k: string, label: string, mode: 'decimal' | 'numeric' = 'decimal') => (
    <div className="setting-row">
      <label htmlFor={`s-${k}`}>{label}{errors[k] && <span className="err"> — {errors[k]}</span>}</label>
      <input id={`s-${k}`} className="inp num" inputMode={mode} disabled={readOnly} value={String(f[k] ?? '')} onChange={(e) => setF({ ...f, [k]: e.target.value })} />
    </div>
  )
  const toggle = (k: string, label: string) => (
    <div className="setting-row">
      <label htmlFor={`s-${k}`}>{label}</label>
      <input id={`s-${k}`} type="checkbox" disabled={readOnly} checked={!!f[k]} onChange={(e) => setF({ ...f, [k]: e.target.checked })} />
    </div>
  )

  return (
    <div className="list">
      <div className="list-head show-md">
        <div className="seg" role="radiogroup">
          {(['general', 'rates', 'security', 'data'] as const).map((k) => (
            <button type="button" key={k} role="radio" aria-checked={tab === k} className={tab === k ? 'on' : ''} onClick={() => setTab(k)}>{t(k === 'data' ? 'dataTab' : k === 'security' ? 'securityTab' : k)}</button>
          ))}
        </div>
      </div>
      <div className="split tabs-md">
        <form className={`card${tab !== 'general' ? ' off-sm' : ''}`} onSubmit={save}>
          <h3>{t('general')}</h3>
          <div className="setting-row"><span>{t('baseCurrency')}</span><b className="num">{data?.base_currency}</b></div>
          {row('gold_price_24k', `${t('goldPrice')} (${data?.base_currency ?? ''})`)}
          {row('silver_price', `${t('silverPrice')} (${data?.base_currency ?? ''})`)}
          {row('alert_days', t('alertDays'), 'numeric')}
          {toggle('email_alerts', t('emailAlerts'))}
          <div className="setting-row">
            <label htmlFor="s-auto_metal_prices">{t('autoMetals')}{data?.metal_prices_updated_at && <small className="muted"> · {fmtDate(data.metal_prices_updated_at.slice(0, 10), { day: 'numeric', month: 'short' })}</small>}</label>
            <span className="row-ctl">
              {!readOnly && <button type="button" className="icon-btn sm" aria-label={t('updateNow')} title={t('updateNow')} onClick={refreshMetals}><Icon name="subscriptions" /></button>}
              <input id="s-auto_metal_prices" type="checkbox" disabled={readOnly} checked={!!f.auto_metal_prices} onChange={(e) => setF({ ...f, auto_metal_prices: e.target.checked })} />
            </span>
          </div>
          <PushRow />
          {!readOnly && <div className="actions"><button type="submit" className="btn">{t('save')}</button></div>}
        </form>
        <div className="col">
        <div className={`card rates-card${tab !== 'rates' ? ' off-sm' : ''}`}>
          <h3>{t('rates')}<small>1 X = ? {data?.base_currency}</small></h3>
          <Paged items={rates}
            empty={<p className="muted none">{t('empty')}</p>}
            container={(c) => <div className="rows">{c}</div>}
            renderItem={(r) => (
              <div className="li" key={r.id}>
                <span><b>{r.currency}</b> <small className="muted">{r.updated_at ? fmtDate(r.updated_at.slice(0, 10), { day: 'numeric', month: 'short' }) : ''}</small></span>
                <span>
                  <b className="num">{n(r.rate, 4)}</b>
                  {!readOnly && <button type="button" className="icon-btn sm" aria-label={t('edit')} onClick={() => setRate(r)}><Icon name="settings" /></button>}
                </span>
              </div>
            )} />
          <div className="setting-row">
            <label htmlFor="s-auto_fetch_rates">{t('fetchRates')}</label>
            <input id="s-auto_fetch_rates" type="checkbox" disabled={readOnly} checked={!!data?.auto_fetch_rates}
              onChange={(e) => patch('/settings', { auto_fetch_rates: e.target.checked }).then(() => { toast(t('saved')); refresh() }).catch(() => toast(t('error')))} />
          </div>
          {!readOnly && <div className="actions"><button type="button" className="btn" onClick={() => setRate(null)}><Icon name="plus" />{t('addRate')}</button></div>}
        </div>
        {/* Sign-in and this device: account, password, other devices, 2FA, app lock. */}
        <div className={`card sec-card${tab !== 'security' ? ' off-sm' : ''}`}>
          <h3>{t('securityTab')}</h3>
          <AccountRows />
        </div>
        <DataTools className={tab !== 'data' ? 'off-sm' : ''} />
        </div>
      </div>
      {rate !== undefined && <RateForm rate={rate} onClose={() => setRate(undefined)} />}
    </div>
  )
}

/** Name / email (owner), password and other devices (owner and heir). */
function AccountRows() {
  const { t } = useI18n()
  const { user, readOnly, toast } = useSession()
  const [open, setOpen] = useState<'profile' | 'password' | 'activity' | null>(null)
  const others = () => post<{ revoked: number }>('/auth/logout-others', {}).then((r) => toast(t('loggedOutOthers', { n: r.revoked }))).catch(() => toast(t('error')))
  return (
    <>
      <div className="setting-row">
        <span>{t('account_')}</span>
        <span className="row-ctl">
          <b className="muted acct-email" dir="ltr">{user?.email}</b>
          <button type="button" className="btn sm ghost" onClick={() => setOpen('activity')}>{t('signInActivity')}</button>
        </span>
      </div>
      <div className="setting-row acct-actions">
        {!readOnly && <button type="button" className="btn sm ghost" onClick={() => setOpen('profile')}>{t('editProfile')}</button>}
        <button type="button" className="btn sm ghost" onClick={() => setOpen('password')}>{t('changePassword')}</button>
        <button type="button" className="btn sm ghost" onClick={others}>{t('logoutOthers')}</button>
      </div>
      <TwoFactorRow />
      <AppLockRow />
      {open === 'profile' && <ProfileForm onClose={() => setOpen(null)} />}
      {open === 'password' && <PasswordForm onClose={() => setOpen(null)} />}
      {open === 'activity' && <ActivityList onClose={() => setOpen(null)} />}
    </>
  )
}

function ProfileForm({ onClose }: { onClose: () => void }) {
  const { t } = useI18n()
  const { user, reloadUser } = useSession()
  const form = useForm({ name: user?.name ?? '', email: user?.email ?? '', current_password: '' }, onClose)
  const emailChanged = String(form.f.email).trim().toLowerCase() !== user?.email
  const submit = () => form.run(async () => {
    await patch('/auth/profile', { name: form.f.name, email: String(form.f.email).trim(), ...(emailChanged ? { current_password: form.f.current_password } : {}) })
    await reloadUser()
  })
  return (
    <FormModal title={t('editProfile')} errors={form.errors} busy={form.busy} onClose={onClose} onSubmit={submit} fields={[
      text(form, 'name', t('name'), { maxLength: 120, full: true }),
      text(form, 'email', t('email'), { type: 'email', dir: 'ltr', maxLength: 255, full: true }),
      text(form, 'current_password', t('currentPassword'), { type: 'password', dir: 'ltr', full: true, hidden: !emailChanged }),
    ]} />
  )
}

function PasswordForm({ onClose }: { onClose: () => void }) {
  const { t } = useI18n()
  const { toast } = useSession()
  const form = useForm({ current_password: '', password: '', password_confirmation: '' }, () => { toast(t('passwordChanged')); onClose() })
  const submit = () => form.run(() => put('/auth/password', form.f))
  return (
    <FormModal title={t('changePassword')} errors={form.errors} busy={form.busy} onClose={onClose} onSubmit={submit} fields={[
      text(form, 'current_password', t('currentPassword'), { type: 'password', dir: 'ltr', full: true }),
      text(form, 'password', `${t('newPassword')} (${t('passwordHint')})`, { type: 'password', dir: 'ltr', full: true }),
      text(form, 'password_confirmation', t('passwordConfirm'), { type: 'password', dir: 'ltr', full: true }),
    ]} />
  )
}

/** Browser push for this device: enable / disable, or explain why it can't. */
function PushRow() {
  const { t } = useI18n()
  const { readOnly, toast } = useSession()
  const [state, setState] = useState<PushState | null>(null)
  useEffect(() => { pushState().then(setState) }, [])
  const act = async () => {
    try {
      setState(state === 'on' ? await disablePush() : await enablePush())
    } catch {
      toast(t('error'))
    }
  }
  const label = state === 'unsupported' ? t('pushNo') : state === 'denied' ? t('pushDenied') : state === 'on' ? t('pushOk') : t('notifications')
  return (
    <div className="setting-row">
      <span>{label}</span>
      {!readOnly && (state === 'on' || state === 'off') && (
        <button type="button" className={`btn sm${state === 'on' ? ' ghost' : ''}`} onClick={act}>{state === 'on' ? t('pushOff') : t('pushOn')}</button>
      )}
    </div>
  )
}

function RateForm({ rate, onClose }: { rate: Rate | null; onClose: () => void }) {
  const { t } = useI18n()
  const form = useForm({ currency: rate?.currency ?? 'USD', rate: rate ? String(rate.rate) : '' }, onClose)
  const submit = () => form.run(() => (rate
    ? patch(`/exchange-rates/${rate.id}`, { rate: num(form.f.rate) })
    : post('/exchange-rates', { currency: form.f.currency, rate: num(form.f.rate) })))
  return (
    <FormModal title={rate ? rate.currency : t('addRate')} errors={form.errors} busy={form.busy} onClose={onClose} onSubmit={submit}
      extra={rate ? <DeleteButton busy={form.busy} onConfirm={() => form.run(() => del(`/exchange-rates/${rate.id}`))} /> : undefined}
      fields={[
        { ...currency(form, 'currency', t('currency')), hidden: !!rate },
        money(form, 'rate', t('rate')),
      ]} />
  )
}

interface SignIn { id: number; success: boolean; method: string; device: string; ip: string | null; at: string }

/** Recent sign-ins and failed attempts on this account. */
function ActivityList({ onClose }: { onClose: () => void }) {
  const { t, date } = useI18n()
  const { data } = useApi<{ data: SignIn[] }>('/auth/activity')
  const rows = useMemo(() => data?.data ?? [], [data])
  return (
    <DetailModal title={t('signInActivity')} onClose={onClose}>
      <p className="muted hint">{t('activityHint')}</p>
      <Paged items={rows}
        empty={<p className="muted none">{t('empty')}</p>}
        container={(c) => <div className="rows">{c}</div>}
        renderItem={(e) => (
          <div className="li" key={e.id}>
            <span><b>{e.device}</b> <small className="muted" dir="ltr">{e.ip}</small></span>
            <span>
              <small className="muted">{date(e.at, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</small>
              <span className={`tag${e.success ? ' quiet' : ' late'}`}>{e.success ? t(`via_${e.method}`) : t('failedAttempt')}</span>
            </span>
          </div>
        )} />
    </DetailModal>
  )
}
