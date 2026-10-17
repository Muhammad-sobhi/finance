import { useMemo, useState, type ReactNode } from 'react'
import { DetailModal, Facts } from '../components/DetailModal'
import { DueTag } from '../components/Due'
import { text } from '../components/fields'
import { Icon } from '../components/Icon'
import { FormModal } from '../components/Modal'
import { Paged } from '../components/Paged'
import { orNull, useForm } from '../hooks/useForm'
import { useI18n } from '../i18n'
import { del, patch, post } from '../lib/api'
import { useSession } from '../lib/session'
import type { Gameya, GameyaSlot, SharedGameya, SharedGameyas } from '../lib/types'

type Act = (fn: () => Promise<unknown>) => Promise<void>

/** This round's state for one turn: paid (confirmed), claimed by the member, or not yet. */
function roundState(s: GameyaSlot, round: number): 'paid' | 'claimed' | 'unpaid' {
  const p = s.payments.find((x) => x.round === round)
  return !p ? 'unpaid' : p.confirmed ? 'paid' : 'claimed'
}

/** Every turn of a gameya the owner runs: who paid this round, payouts, links. */
export function ManagedSlots({ g, act }: { g: Gameya; act: Act }) {
  const { t, num: n } = useI18n()
  const [open, setOpen] = useState<number | null>(null)
  const slots = useMemo(() => g.slots ?? [], [g])
  const round = g.current_round ?? 1
  const slot = slots.find((s) => s.id === open) ?? null

  return (
    <>
      <h3 className="sub-h">{t('turnsAndMembers')} · {t('roundN', { n: round })}: {t('paidOf', { a: g.paid_this_round ?? 0, b: g.members })}</h3>
      <Paged items={slots} resetKey={String(g.id)}
        container={(c) => <div className="rows">{c}</div>}
        renderItem={(s) => {
          const st = s.is_me ? (g.paid_rounds >= round ? 'paid' : 'unpaid') : roundState(s, round)
          return (
            <button type="button" className="li-btn slot-row" key={s.id} onClick={() => setOpen(s.id)}>
              <span>{s.is_me || s.name ? <><b className="num">{n(s.turn)}</b> · {s.is_me ? t('meLbl') : s.name}</> : <span className="muted">{t('turnN', { n: s.turn })}</span>}
                {s.link === 'linked' && <Icon name="heir" />}</span>
              <span>
                <span className={`tag${st === 'paid' ? ' quiet' : st === 'claimed' ? '' : ' late'}`}>{t(st === 'paid' ? 'paidLbl' : st === 'claimed' ? 'claimedLbl' : 'unpaidLbl')}</span>
                {s.payout_date && <span className="tag quiet">{t('payoutDone')}</span>}
              </span>
            </button>
          )
        }} />
      {slot && <SlotDetail g={g} s={slot} act={act} onClose={() => setOpen(null)} />}
    </>
  )
}

function SlotDetail({ g, s, act, onClose }: { g: Gameya; s: GameyaSlot; act: Act; onClose: () => void }) {
  const { t, num: n, date: fmtDate } = useI18n()
  const { readOnly } = useSession()
  const [editing, setEditing] = useState(false)
  const rounds = useMemo(() => Array.from({ length: g.members }, (_, i) => i + 1), [g.members])
  const base = `/gameyas/${g.id}/members/${s.id}`
  const roundDate = (r: number) => g.round_dates?.[r - 1] ?? g.start_date
  const wa = s.phone ? `https://wa.me/${waNumber(s.phone)}?text=${encodeURIComponent(t('waReminder', { name: g.name, n: g.current_round ?? 1, amount: n(g.amount), cur: g.currency }))}` : null

  return (
    <DetailModal title={`${t('turnN', { n: s.turn })} · ${s.is_me ? t('meLbl') : s.name || '—'}`} onClose={onClose}
      footer={!readOnly && !s.is_me ? (
        <>
          <button type="button" className="btn ghost" onClick={() => setEditing(true)}>{t('edit')}</button>
          <span className="grow" />
          {s.link === 'linked'
            ? <button type="button" className="btn ghost" onClick={() => act(() => post(`${base}/remind`, {}))}>{t('sendReminder')}</button>
            : wa && <a className="btn ghost" href={wa} target="_blank" rel="noreferrer">{t('whatsappReminder')}</a>}
          {s.payout_date
            ? <button type="button" className="btn ghost" onClick={() => act(() => del(`${base}/payout`))}>{t('undoPayout')}</button>
            : <button type="button" className="btn" onClick={() => act(() => post(`${base}/payout`, {}))}>{t('handPayout')}</button>}
        </>
      ) : undefined}>
      <Facts items={[
        [t('turnDate'), fmtDate(s.turn_date)],
        [t('payoutAmt'), s.payout_date ? `${n(s.payout_amount ?? g.payout)} · ${fmtDate(s.payout_date)}` : t('notYet')],
        ...(s.is_me ? [] : [
          [t('phone'), s.phone ? <a href={`tel:${s.phone}`} dir="ltr">{s.phone}</a> : '—'],
        ] as [string, ReactNode][]),
        ...(s.notes ? [[t('notes'), s.notes] as [string, ReactNode]] : []),
      ]} />
      {!s.is_me && (
        <div className="li slot-link">
          <span>{t('appLink')}: {t(`link_${s.link}`)}{!s.email && s.link === 'none' && <small className="muted"> · {t('addEmailFirst')}</small>}</span>
          {!readOnly && (
            <span>
              {s.email && s.link !== 'linked' && <button type="button" className="btn ghost sm" onClick={() => act(() => post(`${base}/invite`, {}))}>{t(s.link === 'invited' ? 'resendInvitation' : 'inviteToApp')}</button>}
              {s.link !== 'none' && <button type="button" className="btn ghost sm" onClick={() => act(() => del(`${base}/link`))}>{t('unlink')}</button>}
            </span>
          )}
        </div>
      )}
      <h3 className="sub-h">{t('roundsLbl')}</h3>
      <Paged items={rounds} resetKey={String(s.id)}
        container={(c) => <div className="rows">{c}</div>}
        renderItem={(r) => {
          const p = s.payments.find((x) => x.round === r)
          const st = p ? (p.confirmed ? 'paid' : 'claimed') : 'unpaid'
          return (
            <div className="li" key={r}>
              <span>{t('roundN', { n: r })} · {fmtDate(roundDate(r))}{r === s.turn && <> · <b>{t('hisPayout')}</b></>}</span>
              <span>
                <span className={`tag${st === 'paid' ? ' quiet' : st === 'claimed' ? '' : r <= (g.current_round ?? 1) ? ' late' : ' quiet'}`}>{t(st === 'paid' ? 'paidLbl' : st === 'claimed' ? 'claimedLbl' : 'unpaidLbl')}</span>
                {!readOnly && !s.is_me && st !== 'paid' && <button type="button" className="icon-btn sm" title={t('markMemberPaid')} aria-label={t('markMemberPaid')} onClick={() => act(() => post(`${base}/pay`, { round: r }))}><Icon name="plus" /></button>}
                {!readOnly && !s.is_me && p?.id && <button type="button" className="icon-btn sm" title={t('delete')} aria-label={t('delete')} onClick={() => act(() => del(`${base}/payments/${p.id}`))}><Icon name="close" /></button>}
              </span>
            </div>
          )
        }} />
      {editing && <SlotForm g={g} s={s} onClose={() => setEditing(false)} />}
    </DetailModal>
  )
}

function SlotForm({ g, s, onClose }: { g: Gameya; s: GameyaSlot; onClose: () => void }) {
  const { t } = useI18n()
  const form = useForm({ name: s.name ?? '', phone: s.phone ?? '', email: s.email ?? '', notes: s.notes ?? '' }, onClose)
  const submit = () => form.run(() => patch(`/gameyas/${g.id}/members/${s.id}`, {
    name: orNull(form.f.name), phone: orNull(form.f.phone), email: orNull(String(form.f.email).trim()), notes: orNull(form.f.notes),
  }))
  return (
    <FormModal title={`${t('turnN', { n: s.turn })} — ${g.name}`} errors={form.errors} busy={form.busy} onClose={onClose} onSubmit={submit} fields={[
      text(form, 'name', t('name'), { maxLength: 120 }),
      text(form, 'phone', t('phone'), { type: 'tel', dir: 'ltr', maxLength: 32 }),
      text(form, 'email', t('email'), { type: 'email', dir: 'ltr', full: true }),
      { name: 'emailHint', label: t('important'), full: true, control: <p className="hint" id="f-emailHint">{t('memberEmailHint')}</p> },
      text(form, 'notes', t('notes'), { full: true, maxLength: 2000 }),
    ]} />
  )
}

// ---------------------------------------------------------------- member's side

/** Gameyas someone else runs that I'm linked to (my turn only), plus invitations. */
export function SharedList({ data, act }: { data: SharedGameyas | null; act: Act }) {
  const { t, num: n, date: fmtDate } = useI18n()
  const { readOnly } = useSession()
  const [view, setView] = useState<SharedGameya | null>(null)
  type Row = { invite: SharedGameyas['invites'][number] } | { shared: SharedGameya }
  const rows: Row[] = useMemo(() => [...(data?.invites ?? []).map((invite) => ({ invite })), ...(data?.data ?? []).map((shared) => ({ shared }))], [data])

  return (
    <>
      <Paged items={rows}
        empty={<div className="empty"><Icon name="family" /><p>{t('noSharedGameyas')}</p></div>}
        container={(c) => <div className="grid">{c}</div>}
        renderItem={(r) => 'invite' in r ? (
          <div className="card ic" key={`i${r.invite.id}`}>
            <div className="t"><span>{r.invite.gameya}</span><span className="tag">{t('invitation')}</span></div>
            <div className="meta"><span>{t('gameyaInviteFrom', { name: r.invite.manager, n: r.invite.turn })}</span><span><span className="num">{n(r.invite.amount)}</span> {r.invite.currency}</span></div>
            {!readOnly && (
              <div className="acts">
                <button type="button" className="btn ghost sm" onClick={() => act(() => post(`/shared-gameyas/${r.invite.id}/decline`, {}))}>{t('decline')}</button>
                <button type="button" className="btn sm" onClick={() => act(() => post(`/shared-gameyas/${r.invite.id}/accept`, {}))}>{t('accept')}</button>
              </div>
            )}
          </div>
        ) : (
          <button type="button" key={`s${r.shared.gameya}${r.shared.turns[0]?.member_id}`} className="card ic" onClick={() => setView(r.shared)}>
            <div className="t"><span>{r.shared.gameya}</span>{r.shared.next ? <DueTag days={r.shared.next.days} /> : <span className="tag quiet">{t('allRoundsPaid')}</span>}</div>
            <div className="amt"><span className="num">{n(r.shared.amount)}</span> <small className="cur-sm">{r.shared.currency} · {t('managerLbl')}: {r.shared.manager}</small></div>
            <div className="meta">
              <span>{t('myTurn')}: {r.shared.turns.map((x) => n(x.turn)).join('، ')}</span>
              <span>{t('payoutDate')}: {r.shared.turns.every((x) => x.received) ? t('gotPayout') : fmtDate(r.shared.turns.find((x) => !x.received)?.payout_date ?? r.shared.end_date)}</span>
            </div>
          </button>
        )} />
      {view && <SharedDetail g={view} act={act} onClose={() => setView(null)} />}
    </>
  )
}

function SharedDetail({ g, act, onClose }: { g: SharedGameya; act: Act; onClose: () => void }) {
  const { t, num: n, date: fmtDate } = useI18n()
  const { readOnly } = useSession()
  return (
    <DetailModal title={g.gameya} onClose={onClose}
      footer={!readOnly && g.next ? (
        <><span className="grow" /><button type="button" className="btn" onClick={() => act(() => post(`/shared-gameyas/${g.next!.member_id}/paid`, { round: g.next!.round })).then(onClose)}>{t('iPaidRound', { n: g.next.round })}</button></>
      ) : undefined}>
      <Facts items={[
        [t('managerLbl'), g.manager],
        [t('perRound'), <span className="num">{n(g.amount, 2)} {g.currency}</span>],
        [t('members'), <span className="num">{n(g.members)}</span>],
        ...g.turns.map((x) => [`${t('myTurn')} ${n(x.turn)}`, x.received ? `${t('gotPayout')} · ${fmtDate(x.received_date!)}` : `${fmtDate(x.payout_date)} · ${n(x.payout)}`] as [string, string]),
        [t('paid'), t('paidOf', { a: g.paid_count, b: g.rounds_total })],
        ...(g.next ? [[t('nextPayment'), `${t('roundN', { n: g.next.round })} · ${fmtDate(g.next.date)}`] as [string, string]] : []),
      ]} />
      <h3 className="sub-h">{t('myPayments')}</h3>
      <Paged items={g.paid} resetKey={g.gameya}
        empty={<p className="muted none">{t('empty')}</p>}
        container={(c) => <div className="rows">{c}</div>}
        renderItem={(p) => (
          <div className="li" key={`${p.turn}-${p.round}`}>
            <span>{t('roundN', { n: p.round })} · {fmtDate(p.date)}</span>
            <span><b className="num">{n(p.amount, 2)}</b><span className={`tag${p.confirmed ? ' quiet' : ''}`}>{t(p.confirmed ? 'confirmedLbl' : 'awaitingConfirm')}</span></span>
          </div>
        )} />
    </DetailModal>
  )
}

// ---------------------------------------------------------------- helpers

/** Egyptian numbers as wa.me wants them: digits only, with the country code. */
function waNumber(phone: string): string {
  const d = phone.replace(/[^\d]/g, '')
  return d.startsWith('0') ? `2${d}` : d
}
