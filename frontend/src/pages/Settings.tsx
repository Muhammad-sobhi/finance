import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { currency, money } from '../components/fields'
import { Icon } from '../components/Icon'
import { DeleteButton, FormModal } from '../components/Modal'
import { Paged } from '../components/Paged'
import { useApi } from '../hooks/useApi'
import { num, useForm } from '../hooks/useForm'
import { useI18n } from '../i18n'
import { ApiError, del, patch, post } from '../lib/api'
import { useSession } from '../lib/session'
import type { Settings as S } from '../lib/types'

type Rate = S['rates'][number]

export function Settings() {
  const { t, num: n, date: fmtDate } = useI18n()
  const { readOnly, refresh, toast } = useSession()
  const { data } = useApi<S>('/settings')
  const [tab, setTab] = useState<'general' | 'rates'>('general')
  const [f, setF] = useState<Record<string, string | boolean>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [rate, setRate] = useState<Rate | null | undefined>(undefined)
  const rates = useMemo(() => data?.rates ?? [], [data])

  useEffect(() => {
    if (data) setF({
      gold_price_24k: String(data.gold_price_24k), silver_price: String(data.silver_price), alert_days: String(data.alert_days),
      email_alerts: data.email_alerts, auto_fetch_rates: data.auto_fetch_rates,
    })
  }, [data])

  const save = async (e: FormEvent) => {
    e.preventDefault()
    try {
      await patch('/settings', {
        gold_price_24k: num(f.gold_price_24k) ?? 0, silver_price: num(f.silver_price) ?? 0, alert_days: num(f.alert_days) ?? 0,
        email_alerts: f.email_alerts, auto_fetch_rates: f.auto_fetch_rates,
      })
      setErrors({})
      toast(t('saved'))
      refresh()
    } catch (err) {
      if (err instanceof ApiError) setErrors(err.fieldErrors)
      else toast(t('error'))
    }
  }

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
      <div className="list-head show-sm">
        <div className="seg" role="radiogroup">
          {(['general', 'rates'] as const).map((k) => (
            <button type="button" key={k} role="radio" aria-checked={tab === k} className={tab === k ? 'on' : ''} onClick={() => setTab(k)}>{t(k)}</button>
          ))}
        </div>
      </div>
      <div className="split">
        <form className={`card${tab !== 'general' ? ' off-sm' : ''}`} onSubmit={save}>
          <h3>{t('general')}</h3>
          <div className="setting-row"><span>{t('baseCurrency')}</span><b className="num">{data?.base_currency}</b></div>
          {row('gold_price_24k', `${t('goldPrice')} (${data?.base_currency ?? ''})`)}
          {row('silver_price', `${t('silverPrice')} (${data?.base_currency ?? ''})`)}
          {row('alert_days', t('alertDays'), 'numeric')}
          {toggle('email_alerts', t('emailAlerts'))}
          {toggle('auto_fetch_rates', t('fetchRates'))}
          {!readOnly && <div className="actions"><button type="submit" className="btn">{t('save')}</button></div>}
        </form>
        <div className={`card${tab !== 'rates' ? ' off-sm' : ''}`}>
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
          {!readOnly && <div className="actions"><button type="button" className="btn" onClick={() => setRate(null)}><Icon name="plus" />{t('addRate')}</button></div>}
        </div>
      </div>
      {rate !== undefined && <RateForm rate={rate} onClose={() => setRate(undefined)} />}
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
