import { useEffect, useState } from 'react'
import { useForm } from '../hooks/useForm'
import { useI18n } from '../i18n'
import { ApiError } from '../lib/api'
import { appLock, biometricAvailable, pinIssue, registerBiometric, LOCK_CHANGED } from '../lib/appLock'
import { useSession } from '../lib/session'
import { check, select, text } from './fields'
import { FormModal } from './Modal'

const fail = (field: string, msg: string) => new ApiError(422, { errors: { [field]: [msg] } })

/** Settings row: lock this device's app with a PIN, plus fingerprint / face if the phone has it. */
export function AppLockRow() {
  const { t } = useI18n()
  const { user } = useSession()
  const [config, setConfig] = useState(() => appLock.get(user?.id))
  const [open, setOpen] = useState<'setup' | 'off' | null>(null)
  useEffect(() => {
    const load = () => setConfig(appLock.get(user?.id))
    window.addEventListener(LOCK_CHANGED, load)
    return () => window.removeEventListener(LOCK_CHANGED, load)
  }, [user?.id])
  const status = !config ? t('lockOff') : config.credential ? t('lockOnBio') : t('lockOnPin')
  return (
    <>
      <div className="setting-row">
        <span>{t('appLock')} — <b className={config ? 'pos' : 'neg'}>{status}</b></span>
        <span className="acct-actions">
          <button type="button" className="btn sm ghost" onClick={() => setOpen('setup')}>{config ? t('lockChange') : t('lockSetUp')}</button>
          {config && <button type="button" className="btn sm danger" onClick={() => setOpen('off')}>{t('lockTurnOff')}</button>}
        </span>
      </div>
      {open === 'setup' && <LockForm onClose={() => setOpen(null)} />}
      {open === 'off' && <LockOffForm onClose={() => setOpen(null)} />}
    </>
  )
}

function LockForm({ onClose }: { onClose: () => void }) {
  const { t } = useI18n()
  const { user } = useSession()
  const current = appLock.get(user?.id)
  const [bioOk, setBioOk] = useState(false)
  useEffect(() => { biometricAvailable().then(setBioOk) }, [])
  const form = useForm<Record<string, unknown>>({ current_pin: '', pin: '', pin2: '', after: String(current?.after ?? 60), bio: !!current?.credential }, onClose)

  const submit = () => form.run(async () => {
    if (current && !(await appLock.checkPin(current, String(form.f.current_pin))).ok) throw fail('current_pin', t('lockWrongPin'))
    const pin = String(form.f.pin)
    const issue = pinIssue(pin)
    if (issue) throw fail('pin', t(issue === 'format' ? 'lockPinFormat' : 'lockPinWeak'))
    if (pin !== form.f.pin2) throw fail('pin2', t('vPinMismatch'))
    let credential: string | null = null
    if (form.f.bio && bioOk) {
      try {
        // Keep the one already set up; register only when turning it on.
        credential = current?.credential ?? await registerBiometric({ email: user!.email, name: user!.name })
      } catch {
        throw fail('bio', t('lockBioFailed'))
      }
    }
    await appLock.create(user!.id, pin, Number(form.f.after), credential)
  })

  return (
    <FormModal title={t('appLock')} errors={form.errors} busy={form.busy} onClose={onClose} onSubmit={submit}
      fields={[
        text(form, 'current_pin', t('lockCurrentPin'), { type: 'password', inputMode: 'numeric', dir: 'ltr', full: true, hidden: !current, maxLength: 8 }),
        text(form, 'pin', t('lockNewPin'), { type: 'password', inputMode: 'numeric', dir: 'ltr', maxLength: 8 }),
        text(form, 'pin2', t('vPinConfirm'), { type: 'password', inputMode: 'numeric', dir: 'ltr', maxLength: 8 }),
        select(form, 'after', t('lockAfter'), [['0', t('lockAlways')], ['60', t('lockAfter1')], ['300', t('lockAfter5')]], { full: true }),
        check(form, 'bio', t('lockBioTitle'), t('lockBioText'), { full: true, hidden: !bioOk }),
      ]} />
  )
}

function LockOffForm({ onClose }: { onClose: () => void }) {
  const { t } = useI18n()
  const { user } = useSession()
  const form = useForm<Record<string, unknown>>({ current_pin: '' }, onClose)
  const submit = () => form.run(async () => {
    const current = appLock.get(user?.id)
    if (current && !(await appLock.checkPin(current, String(form.f.current_pin))).ok) throw fail('current_pin', t('lockWrongPin'))
    appLock.clear()
  })
  return (
    <FormModal title={t('lockTurnOff')} errors={form.errors} busy={form.busy} onClose={onClose} onSubmit={submit} submitLabel={t('lockTurnOff')}
      fields={[text(form, 'current_pin', t('lockCurrentPin'), { type: 'password', inputMode: 'numeric', dir: 'ltr', full: true, maxLength: 8 })]} />
  )
}
