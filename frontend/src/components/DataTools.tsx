import { useState } from 'react'
import { useForm } from '../hooks/useForm'
import { useI18n } from '../i18n'
import { ApiError, fetchBlob, saveBlob } from '../lib/api'
import { isoDate } from '../lib/dates'
import { useNavigate } from 'react-router-dom'
import { useInstall } from '../lib/install'
import { useSession } from '../lib/session'
import { text } from './fields'
import { FormModal } from './Modal'

/** Settings card: export, encrypted backup. */
export function DataTools({ className = '' }: { className?: string }) {
  const { t } = useI18n()
  const { readOnly, toast } = useSession()
  const [open, setOpen] = useState<'backup' | null>(null)
  const { canInstall, iosHint, install } = useInstall()
  const { user } = useSession()
  const nav = useNavigate()

  const exportCsv = () => fetchBlob('/export/transactions')
    .then((b) => saveBlob(b, `transactions-${isoDate()}.csv`))
    .catch(() => toast(t('error')))

  return (
    <div className={`card data-card ${className}`}>
      <h3>{t('dataTools')}</h3>
      <div className="data-acts">
        <button type="button" className="btn ghost" onClick={exportCsv}>{t('exportCsv')}</button>
        {!readOnly && <button type="button" className="btn ghost" onClick={() => setOpen('backup')}>{t('backupNow')}</button>}
        {canInstall && <button type="button" className="btn" onClick={install}>{t('installApp')}</button>}
        {user?.is_admin && <button type="button" className="btn ghost" onClick={() => nav('/admin')}>{t('usersAdmin')}</button>}
      </div>
      <p className="muted hint">{iosHint ? t('iosInstall') : t('backupHint')}</p>
      {open === 'backup' && <BackupForm onClose={() => setOpen(null)} />}
    </div>
  )
}

function BackupForm({ onClose }: { onClose: () => void }) {
  const { t } = useI18n()
  const { toast } = useSession()
  const form = useForm({ current_password: '', passphrase: '', passphrase2: '' }, onClose)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const submit = async () => {
    if (form.f.passphrase !== form.f.passphrase2) { setErrors({ passphrase2: t('passMismatch') }); return }
    try {
      const blob = await fetchBlob('/backup', { method: 'POST', body: { current_password: form.f.current_password, passphrase: form.f.passphrase } })
      saveBlob(blob, `dinar-backup-${isoDate()}.json`)
      toast(t('backupSaved'))
      onClose()
    } catch (err) {
      if (err instanceof ApiError && err.data.errors) setErrors(err.fieldErrors)
      else toast(t('error'))
    }
  }
  return (
    <FormModal title={t('backupNow')} errors={{ ...form.errors, ...errors }} busy={form.busy} onClose={onClose} onSubmit={submit} submitLabel={t('download')} fields={[
      text(form, 'current_password', t('currentPassword'), { type: 'password', full: true }),
      text(form, 'passphrase', t('passphrase'), { type: 'password', placeholder: t('passphraseHint') }),
      text(form, 'passphrase2', t('confirmPassphrase'), { type: 'password' }),
      { name: 'note', label: t('important'), full: true, control: <p className="muted hint" id="f-note">{t('passphraseWarn')}</p> },
    ]} />
  )
}
