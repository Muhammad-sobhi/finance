import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react'
import { DetailModal, Facts } from '../components/DetailModal'
import { check, date, select, text } from '../components/fields'
import { Icon } from '../components/Icon'
import { ListHead } from '../components/ListHead'
import { DeleteButton, FormModal } from '../components/Modal'
import { Paged } from '../components/Paged'
import { useApi } from '../hooks/useApi'
import { useForm } from '../hooks/useForm'
import { useI18n, type Key } from '../i18n'
import { api, ApiError, del, post, put } from '../lib/api'
import { useSession } from '../lib/session'
import * as V from '../lib/vaultCrypto'

type Kind = 'password' | 'bank' | 'card' | 'document' | 'insurance' | 'crypto' | 'note'
interface Row { id: number; kind: Kind; iv: string; ciphertext: string; remind_on: string | null }
interface VaultData { vault: V.VaultKeyBox | null; items: Row[] }
type Fields = Record<string, string>
interface Item { id: number; kind: Kind; f: Fields; remind_on: string | null }

/** Fields per kind. `secret` values are hidden until shown; `date` fields drive the renewal reminder. */
const SPEC: Record<Kind, { icon: string; fields: { k: string; label: Key; secret?: boolean; date?: boolean; ltr?: boolean; multi?: boolean }[] }> = {
  password: { icon: 'key', fields: [{ k: 'username', label: 'vUsername', ltr: true }, { k: 'password', label: 'vPassword', secret: true, ltr: true }, { k: 'url', label: 'vUrl', ltr: true }] },
  bank: { icon: 'accounts', fields: [{ k: 'holder', label: 'vHolder' }, { k: 'account_number', label: 'vAccountNumber', secret: true, ltr: true }, { k: 'iban', label: 'vIban', secret: true, ltr: true }, { k: 'swift', label: 'vSwift', ltr: true }, { k: 'branch', label: 'vBranch' }] },
  card: { icon: 'wallet', fields: [{ k: 'holder', label: 'vHolder' }, { k: 'number', label: 'vCardNumber', secret: true, ltr: true }, { k: 'expiry', label: 'vCardExpiry', ltr: true }] },
  document: { icon: 'book', fields: [{ k: 'number', label: 'vDocNumber', secret: true, ltr: true }, { k: 'expiry', label: 'vExpiresOn', date: true }] },
  insurance: { icon: 'health', fields: [{ k: 'policy', label: 'vPolicyNumber', secret: true, ltr: true }, { k: 'renewal', label: 'vRenewsOn', date: true }] },
  crypto: { icon: 'investments', fields: [{ k: 'address', label: 'vAddress', ltr: true }, { k: 'seed', label: 'vSeed', secret: true, ltr: true, multi: true }] },
  note: { icon: 'book', fields: [{ k: 'body', label: 'vSecretNote', secret: true, multi: true }] },
}
const KINDS = Object.keys(SPEC) as Kind[]
const kindLabel = (k: Kind): Key => `vk_${k}` as Key
const remindOf = (kind: Kind, f: Fields) => SPEC[kind].fields.find((s) => s.date && f[s.k])?.k
const fail = (field: string, msg: string) => new ApiError(422, { errors: { [field]: [msg] } })

/** Passwords, bank details, documents… sealed in the browser; opened with a PIN or the owner's key copy. */
export function Vault() {
  const { t, date: fmt } = useI18n()
  const { readOnly } = useSession()
  const { data } = useApi<VaultData>('/vault')
  const [open, setOpen] = useState(V.isUnlocked())
  const [items, setItems] = useState<Item[] | null>(null)
  const [kind, setKind] = useState<Kind | ''>('')
  const [dialog, setDialog] = useState<'setup' | 'settings' | 'reset' | null>(null)
  const [sealedOff, setSealedOff] = useState(false)
  const [edit, setEdit] = useState<Item | Kind | null>(null)
  const [view, setView] = useState<Item | null>(null)

  useEffect(() => V.onLockChange(() => setOpen(V.isUnlocked())), [])

  // No PIN (owner) or an heir with a key copy: open with the copy instead of asking.
  const mode = data?.vault?.mode
  useEffect(() => {
    if (open || !mode || (mode !== 'open' && !(readOnly && mode === 'pin_heir'))) return
    let live = true
    api<{ key: string }>('/vault/escrow').then((r) => { if (live) return V.unlockWithCopy(r.key) }).catch(() => { if (live) setSealedOff(true) })
    return () => { live = false }
  }, [open, mode, readOnly])

  // Decrypt in the browser whenever the list or the lock state changes.
  useEffect(() => {
    if (!open || !data?.vault) { setItems(null); return }
    let live = true
    Promise.all(data.items.map(async (r) => ({ id: r.id, kind: r.kind, remind_on: r.remind_on, f: await V.open<Fields>(r) })))
      .then((list) => live && setItems(list))
      .catch(() => live && V.lock())
    return () => { live = false }
  }, [open, data])

  const rows = useMemo(() => (items ?? []).filter((i) => !kind || i.kind === kind)
    .sort((a, b) => a.kind.localeCompare(b.kind) || (a.f.title ?? '').localeCompare(b.f.title ?? '')), [items, kind])

  if (!data) return <div className="empty">{t('loading')}</div>

  if (!data.vault) {
    return (
      <div className="empty vault-gate">
        <Icon name="lock" />
        <p><b>{t('vaultTitle')}</b></p>
        <p className="hint">{readOnly ? t('vaultHeirNone') : t('vaultIntro')}</p>
        {!readOnly && <button type="button" className="btn" onClick={() => setDialog('setup')}>{t('vaultSetup')}</button>}
        {dialog === 'setup' && <SetupForm onClose={() => setDialog(null)} />}
      </div>
    )
  }

  if (!open) {
    return (
      <div className="empty vault-gate">
        <Icon name="lock" />
        <p><b>{t('vaultLocked')}</b></p>
        {readOnly || mode === 'open'
          ? <p className="hint">{readOnly && (mode === 'pin' || sealedOff) ? t('vaultHeirPin') : t('loading')}</p>
          : <UnlockForm box={data.vault} onForgot={() => setDialog('reset')} />}
        {dialog === 'reset' && <ResetForm onClose={() => setDialog(null)} />}
      </div>
    )
  }

  return (
    <div className="list" onPointerDown={V.touch} onKeyDown={V.touch}>
      <ListHead>
        <select className="inp sm" aria-label={t('type')} value={kind} onChange={(e) => setKind(e.target.value as Kind | '')}>
          <option value="">{t('all')}</option>
          {KINDS.map((k) => <option key={k} value={k}>{t(kindLabel(k))}</option>)}
        </select>
        <span className="grow" />
        {!readOnly && <button type="button" className="icon-btn" title={t('vaultSettings')} aria-label={t('vaultSettings')} onClick={() => setDialog('settings')}><Icon name="key" /></button>}
        {mode !== 'open' && !readOnly && <button type="button" className="icon-btn" title={t('vaultLock')} aria-label={t('vaultLock')} onClick={V.lock}><Icon name="lock" /></button>}
        {!readOnly && <button type="button" className="btn" onClick={() => setEdit(kind || 'password')}><Icon name="plus" /><span className="hide-sm">{t('add')}</span></button>}
      </ListHead>
      <Paged
        items={rows}
        resetKey={kind}
        empty={<div className="empty"><Icon name="lock" /><p>{items === null ? t('loading') : t('empty')}</p></div>}
        container={(c) => <div className="grid">{c}</div>}
        renderItem={(i) => (
          <button type="button" key={i.id} className="card ic" onClick={() => setView(i)}>
            <div className="t"><span className="cat-name"><span className="cat-ic vault-ic"><Icon name={SPEC[i.kind].icon} /></span>{i.f.title || t(kindLabel(i.kind))}</span><span className="tag quiet">{t(kindLabel(i.kind))}</span></div>
            <div className="meta"><span dir="auto">{subtitle(i)}</span>{i.remind_on && <span>{t('vRenewsOn')} {fmt(i.remind_on, { day: 'numeric', month: 'short', year: 'numeric' })}</span>}</div>
          </button>
        )}
      />
      {view && <ItemView item={view} onClose={() => setView(null)} onEdit={readOnly ? undefined : () => { setEdit(view); setView(null) }} />}
      {edit !== null && <ItemForm item={typeof edit === 'string' ? null : edit} kind={typeof edit === 'string' ? edit : edit.kind} onClose={() => setEdit(null)} />}
      {dialog === 'settings' && <ModeForm current={data.vault.mode} onClose={() => setDialog(null)} />}
    </div>
  )
}

/** A non-secret hint under the title: username, holder, last digits… */
function subtitle(i: Item): string {
  const f = i.f
  if (f.username) return f.username
  if (f.holder) return f.holder
  const secret = f.number || f.account_number || f.policy
  return secret ? `•••• ${secret.slice(-4)}` : ''
}

const MODES: V.Mode[] = ['pin_heir', 'pin', 'open']

/** Mode picker + PIN fields shared by setup and settings. Throws field errors; returns the PIN (or null). */
function modeFields(form: ReturnType<typeof useForm<Record<string, unknown>>>, t: ReturnType<typeof useI18n>['t']) {
  const mode = form.f.mode as V.Mode
  const fields = [
    select(form, 'mode', t('vMode'), MODES.map((m) => [m, t(`vMode_${m}` as Key)]), { full: true }),
    { name: 'modeHint', label: t('important'), full: true, control: <p className="hint" id="f-modeHint">{t(`vModeHint_${mode}` as Key)}</p> },
    text(form, 'pin', t('vPin'), { type: 'password', dir: 'ltr', hidden: mode === 'open' }),
    text(form, 'pin2', t('vPinConfirm'), { type: 'password', dir: 'ltr', hidden: mode === 'open' }),
  ]
  const pin = () => {
    if (mode === 'open') return null
    const p = String(form.f.pin ?? '')
    const issue = V.pinIssue(p)
    if (issue) throw fail('pin', t(issue === 'short' ? 'vPinShort' : 'vPinWeak'))
    if (p !== form.f.pin2) throw fail('pin2', t('vPinMismatch'))
    return p
  }
  return { mode, fields, pin }
}

function SetupForm({ onClose }: { onClose: () => void }) {
  const { t } = useI18n()
  const { refresh } = useSession()
  const form = useForm<Record<string, unknown>>({ mode: 'pin_heir', pin: '', pin2: '', understood: false }, onClose)
  const m = modeFields(form, t)
  const submit = () => form.run(async () => {
    const pin = m.pin()
    if (m.mode === 'pin' && !form.f.understood) throw fail('understood', t('vMustAccept'))
    await post('/vault', await V.createVault(m.mode, pin))
    refresh()
    onClose()
  }, {}, true)
  return (
    <FormModal title={t('vaultSetup')} errors={form.errors} busy={form.busy} onClose={onClose} onSubmit={submit} fields={[
      ...m.fields,
      check(form, 'understood', t('important'), t('vForgetWarning'), { full: true, hidden: m.mode !== 'pin' }),
    ]} />
  )
}

function UnlockForm({ box, onForgot }: { box: V.VaultKeyBox; onForgot: () => void }) {
  const { t } = useI18n()
  const [pin, setPin] = useState('')
  const [err, setErr] = useState(false)
  const [busy, setBusy] = useState(false)
  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    const ok = await V.unlock(box, pin)
    setBusy(false)
    setErr(!ok)
    if (ok) setPin('')
  }
  return (
    <form className="vault-unlock" onSubmit={submit}>
      <label htmlFor="vault-pin">{t('vPin')}</label>
      <input id="vault-pin" className="inp" type="password" dir="ltr" autoComplete="off" autoFocus value={pin} onChange={(e) => setPin(e.target.value)} />
      {err && <span className="err" role="alert">{t('vWrongPin')}</span>}
      <button type="submit" className="btn" disabled={busy || !pin}>{busy ? t('loading') : t('vaultUnlock')}</button>
      <button type="button" className="btn ghost" onClick={onForgot}>{t('vForgotPin')}</button>
    </form>
  )
}

function ModeForm({ current, onClose }: { current: V.Mode; onClose: () => void }) {
  const { t } = useI18n()
  const { toast, refresh } = useSession()
  const form = useForm<Record<string, unknown>>({ mode: current, pin: '', pin2: '', understood: false, current_password: '' }, onClose)
  const m = modeFields(form, t)
  const submit = () => form.run(async () => {
    const pin = m.pin()
    if (m.mode === 'pin' && !form.f.understood) throw fail('understood', t('vMustAccept'))
    await put('/vault/key', { ...(await V.rekey(m.mode, pin)), current_password: form.f.current_password })
    toast(t('saved'))
    refresh()
    onClose()
  }, {}, true)
  return (
    <FormModal title={t('vaultSettings')} errors={form.errors} busy={form.busy} onClose={onClose} onSubmit={submit} fields={[
      ...m.fields,
      check(form, 'understood', t('important'), t('vForgetWarning'), { full: true, hidden: m.mode !== 'pin' }),
      text(form, 'current_password', t('currentPassword'), { type: 'password', dir: 'ltr', full: true }),
    ]} />
  )
}

function ResetForm({ onClose }: { onClose: () => void }) {
  const { t } = useI18n()
  const { toast, refresh } = useSession()
  const form = useForm({ current_password: '' }, onClose)
  const submit = () => form.run(async () => {
    await api('/vault', { method: 'DELETE', body: { current_password: form.f.current_password } })
    V.lock()
    toast(t('vaultWiped'))
    refresh()
    onClose()
  }, {}, true)
  return (
    <FormModal title={t('vForgotPin')} errors={form.errors} busy={form.busy} onClose={onClose} onSubmit={submit} submitLabel={t('vWipeVault')} fields={[
      { name: 'warn', label: t('important'), full: true, control: <p className="hint neg" id="f-warn">{t('vResetWarning')}</p> },
      text(form, 'current_password', t('currentPassword'), { type: 'password', dir: 'ltr', full: true }),
    ]} />
  )
}

function ItemForm({ item, kind: initialKind, onClose }: { item: Item | null; kind: Kind; onClose: () => void }) {
  const { t } = useI18n()
  const form = useForm<Record<string, unknown>>({ kind: initialKind, title: '', notes: '', ...(item?.f ?? {}) }, onClose)
  const kind = form.f.kind as Kind
  const submit = () => form.run(async () => {
    if (!String(form.f.title ?? '').trim()) throw fail('title', t('required'))
    const f: Fields = { title: String(form.f.title).trim(), notes: String(form.f.notes ?? '') }
    SPEC[kind].fields.forEach((s) => { f[s.k] = String(form.f[s.k] ?? '') })
    const dateKey = remindOf(kind, f)
    const body = { kind, ...(await V.seal(f)), remind_on: dateKey ? f[dateKey] : null }
    if (item) await put(`/vault/items/${item.id}`, body)
    else await post('/vault/items', body)
  })
  return (
    <FormModal title={item ? t('edit') : t('add')} errors={form.errors} busy={form.busy} onClose={onClose} onSubmit={submit}
      extra={item ? <DeleteButton busy={form.busy} onConfirm={() => form.run(() => del(`/vault/items/${item.id}`))} /> : undefined}
      fields={[
        select(form, 'kind', t('type'), KINDS.map((k) => [k, t(kindLabel(k))]), { full: true }),
        text(form, 'title', t('name'), { maxLength: 120, full: true }),
        ...SPEC[kind].fields.map((s) => s.date ? date(form, s.k, t(s.label)) : text(form, s.k, t(s.label), { dir: s.ltr ? 'ltr' : undefined, type: s.secret ? 'password' : 'text', full: s.multi })),
        ...(kind === 'card' ? [{ name: 'cardNote', label: t('important'), full: true, control: <p className="hint" id="f-cardNote">{t('vNoCvv')}</p> }] : []),
        text(form, 'notes', t('notes'), { full: true }),
      ]} />
  )
}

function ItemView({ item, onClose, onEdit }: { item: Item; onClose: () => void; onEdit?: () => void }) {
  const { t, date: fmt } = useI18n()
  const { toast } = useSession()
  const [shown, setShown] = useState<Record<string, boolean>>({})
  const copy = (v: string) => navigator.clipboard?.writeText(v).then(() => toast(t('vCopied')), () => toast(t('error')))
  const facts: [string, ReactNode][] = SPEC[item.kind].fields.filter((s) => item.f[s.k]).map((s) => [t(s.label), (
    <span className="vault-val">
      <span dir={s.ltr ? 'ltr' : 'auto'} className={s.secret && !shown[s.k] ? 'masked' : ''}>{s.secret && !shown[s.k] ? '••••••••' : s.date ? fmt(item.f[s.k], { day: 'numeric', month: 'long', year: 'numeric' }) : item.f[s.k]}</span>
      {s.secret && <button type="button" className="icon-btn sm" aria-label={t(shown[s.k] ? 'vHide' : 'vShow')} title={t(shown[s.k] ? 'vHide' : 'vShow')} onClick={() => setShown((x) => ({ ...x, [s.k]: !x[s.k] }))}><Icon name="eye" /></button>}
      <button type="button" className="icon-btn sm" aria-label={t('vCopy')} title={t('vCopy')} onClick={() => copy(item.f[s.k])}><Icon name="copy" /></button>
    </span>
  )])
  if (item.f.notes) facts.push([t('notes'), item.f.notes])
  return (
    <DetailModal title={item.f.title || t(kindLabel(item.kind))} onClose={onClose}
      footer={onEdit && <><span className="grow" /><button type="button" className="btn" onClick={onEdit}>{t('edit')}</button></>}>
      <Facts items={[[t('type'), t(kindLabel(item.kind))], ...facts]} />
    </DetailModal>
  )
}
