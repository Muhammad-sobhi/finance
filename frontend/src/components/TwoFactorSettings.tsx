import { useEffect, useState, type FormEvent } from 'react'
import QRCode from 'qrcode'
import { useApi } from '../hooks/useApi'
import { useForm } from '../hooks/useForm'
import { useI18n } from '../i18n'
import { ApiError, post } from '../lib/api'
import { useSession } from '../lib/session'
import { DetailModal } from './DetailModal'
import { text } from './fields'
import { FormModal } from './Modal'

type Mode = 'enable' | 'disable' | 'codes' | null

/** Settings row: status + turn on / new recovery codes / turn off. */
export function TwoFactorRow() {
  const { t } = useI18n()
  const { data } = useApi<{ enabled: boolean; recovery_codes_left: number }>('/auth/two-factor')
  const [mode, setMode] = useState<Mode>(null)
  const on = !!data?.enabled
  return (
    <>
      <div className="setting-row">
        <span>{t('twoFactor')} — <b className={on ? 'pos' : 'neg'}>{on ? t('twoFactorOn') : t('twoFactorOff')}</b>
          {on && <small className="muted"> · {t('codesLeft', { n: data!.recovery_codes_left })}</small>}</span>
        <span className="acct-actions">
          {on && <button type="button" className="btn sm ghost" onClick={() => setMode('codes')}>{t('newCodes')}</button>}
          <button type="button" className={`btn sm${on ? ' danger' : ''}`} onClick={() => setMode(on ? 'disable' : 'enable')}>{on ? t('disable2fa') : t('enable2fa')}</button>
        </span>
      </div>
      {mode === 'enable' && <EnableFlow onClose={() => setMode(null)} />}
      {mode === 'disable' && <DisableForm onClose={() => setMode(null)} />}
      {mode === 'codes' && <NewCodesFlow onClose={() => setMode(null)} />}
    </>
  )
}

/** Password → QR + first code → recovery codes. */
function EnableFlow({ onClose }: { onClose: () => void }) {
  const { t } = useI18n()
  const { refresh, toast, reloadUser } = useSession()
  const [setup, setSetup] = useState<{ secret: string; otpauth_url: string } | null>(null)
  const [codes, setCodes] = useState<string[] | null>(null)
  const pw = useForm({ current_password: '' }, () => undefined)

  if (codes) return <CodesView codes={codes} onClose={() => { toast(t('twoFactorOnMsg')); refresh(); reloadUser(); onClose() }} />
  if (setup) return <ScanStep setup={setup} onClose={onClose} onDone={setCodes} />
  return (
    <FormModal title={t('twoFactor')} errors={pw.errors} busy={pw.busy} onClose={onClose} submitLabel={t('next')}
      onSubmit={() => pw.run(() => post<{ secret: string; otpauth_url: string }>('/auth/two-factor/setup', pw.f).then(setSetup), {}, true)}
      fields={[text(pw, 'current_password', t('currentPassword'), { type: 'password', dir: 'ltr', full: true })]} />
  )
}

function ScanStep({ setup, onClose, onDone }: { setup: { secret: string; otpauth_url: string }; onClose: () => void; onDone: (codes: string[]) => void }) {
  const { t } = useI18n()
  const [svg, setSvg] = useState('')
  const [code, setCode] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  // QR is drawn locally from our own otpauth URL; nothing is sent to a third party.
  useEffect(() => { QRCode.toString(setup.otpauth_url, { type: 'svg', margin: 1, width: 168 }).then(setSvg) }, [setup.otpauth_url])

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    e.stopPropagation() // see FormModal: don't reach the settings page's form
    setBusy(true)
    try {
      onDone((await post<{ recovery_codes: string[] }>('/auth/two-factor/confirm', { code })).recovery_codes)
    } catch (err) {
      setError(err instanceof ApiError && err.status === 422 ? t('invalidCode') : t('error'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <DetailModal title={t('twoFactor')} onClose={onClose}>
      <form className="tfa" onSubmit={submit}>
        <p className="muted">{t('scanQr')}</p>
        <div className="qr" aria-label="QR" dangerouslySetInnerHTML={{ __html: svg }} />
        <p className="muted small">{t('manualKey')}</p>
        <code className="secret" dir="ltr">{setup.secret.replace(/(.{4})/g, '$1 ').trim()}</code>
        <div className="field">
          <label htmlFor="tfa-code">{t('authCode')}</label>
          <input id="tfa-code" className="inp num code-inp" dir="ltr" inputMode="numeric" autoComplete="one-time-code" maxLength={6}
            value={code} onChange={(e) => { setCode(e.target.value); setError(null) }} autoFocus />
        </div>
        {error && <span className="err" role="alert">{error}</span>}
        <button type="submit" className="btn" disabled={busy || code.length < 6}>{t('verify')}</button>
      </form>
    </DetailModal>
  )
}

function CodesView({ codes, onClose }: { codes: string[]; onClose: () => void }) {
  const { t } = useI18n()
  const { toast } = useSession()
  const txt = codes.join('\n')
  const download = () => {
    const a = document.createElement('a')
    a.href = URL.createObjectURL(new Blob([`Treasury recovery codes\n\n${txt}\n`], { type: 'text/plain' }))
    a.download = 'treasury-recovery-codes.txt'
    a.click()
    URL.revokeObjectURL(a.href)
  }
  return (
    <DetailModal title={t('recoveryCodes')} onClose={onClose} footer={
      <>
        <button type="button" className="btn ghost" onClick={() => navigator.clipboard?.writeText(txt).then(() => toast(t('copied')))}>{t('copy')}</button>
        <button type="button" className="btn ghost" onClick={download}>{t('download')}</button>
        <span className="grow" />
        <button type="button" className="btn" onClick={onClose}>{t('done')}</button>
      </>
    }>
      <p className="muted">{t('recoveryHint')}</p>
      <ol className="codes" dir="ltr">{codes.map((c) => <li key={c}><code>{c}</code></li>)}</ol>
    </DetailModal>
  )
}

function DisableForm({ onClose }: { onClose: () => void }) {
  const { t } = useI18n()
  const { toast, reloadUser } = useSession()
  const form = useForm({ current_password: '', code: '' }, () => { toast(t('twoFactorOffMsg')); reloadUser(); onClose() })
  return (
    <FormModal title={`${t('disable2fa')} — ${t('twoFactor')}`} errors={form.errors} busy={form.busy} onClose={onClose} submitLabel={t('disable2fa')}
      onSubmit={() => form.run(() => post('/auth/two-factor/disable', form.f))}
      fields={[
        text(form, 'current_password', t('currentPassword'), { type: 'password', dir: 'ltr', full: true }),
        text(form, 'code', t('codeOrRecovery'), { dir: 'ltr', full: true, maxLength: 30 }),
      ]} />
  )
}

function NewCodesFlow({ onClose }: { onClose: () => void }) {
  const { t } = useI18n()
  const { refresh } = useSession()
  const [codes, setCodes] = useState<string[] | null>(null)
  const form = useForm({ current_password: '' }, () => undefined)
  if (codes) return <CodesView codes={codes} onClose={() => { refresh(); onClose() }} />
  return (
    <FormModal title={t('newCodes')} errors={form.errors} busy={form.busy} onClose={onClose} submitLabel={t('next')}
      onSubmit={() => form.run(() => post<{ recovery_codes: string[] }>('/auth/two-factor/recovery-codes', form.f).then((r) => setCodes(r.recovery_codes)), {}, true)}
      fields={[text(form, 'current_password', t('currentPassword'), { type: 'password', dir: 'ltr', full: true })]} />
  )
}
