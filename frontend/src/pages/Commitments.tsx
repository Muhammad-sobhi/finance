import { useMemo, useState } from 'react'
import { DetailModal, Facts } from '../components/DetailModal'
import { DueTag } from '../components/Due'
import { currency, date, money, select, text } from '../components/fields'
import { Icon } from '../components/Icon'
import { ListHead, ModeToggle, useListMode } from '../components/ListHead'
import { DeleteButton, FormModal } from '../components/Modal'
import { Paged } from '../components/Paged'
import { useApi } from '../hooks/useApi'
import { num, orNull, useForm } from '../hooks/useForm'
import { useI18n } from '../i18n'
import { ApiError, del, patch, post } from '../lib/api'
import { isoDate } from '../lib/dates'
import { catName } from '../lib/labels'
import { useSession } from '../lib/session'
import type { Account, Category, Gameya, InstallmentPlan, Listed, SharedGameyas } from '../lib/types'
import { ManagedSlots, SharedList } from './GameyaManaged'

const active = (accounts: Account[], keep?: number | null) => accounts.filter((a) => a.is_active || a.id === keep)

/** A filled bar for "n of N paid". */
function Steps({ done, of }: { done: number; of: number }) {
  const pct = of > 0 ? (done / of) * 100 : 0
  return <div className="meter ok" role="progressbar" aria-valuemin={0} aria-valuemax={of} aria-valuenow={done}><i style={{ width: `${pct}%` }} /></div>
}

/** Toast the API's 422 message for actions taken from a list (pay / undo). */
function useAction() {
  const { t } = useI18n()
  const { refresh, toast } = useSession()
  return (fn: () => Promise<unknown>) => fn().then(() => { toast(t('saved')); refresh() }).catch((err) => {
    const first = err instanceof ApiError && err.data.errors ? Object.values(err.data.errors)[0]?.[0] : null
    toast(first ?? t('error'))
  })
}

// ---------------------------------------------------------------- installments

export function Installments() {
  const { t, num: n, date: fmtDate } = useI18n()
  const { readOnly } = useSession()
  const mode = useListMode()
  const { data } = useApi<Listed<InstallmentPlan, { base_currency: string; remaining: number; this_month: number }>>('/installments')
  const accounts = useApi<Account[]>('/accounts')
  const cats = useApi<Category[]>('/categories?type=expense')
  const rows = useMemo(() => data?.data ?? [], [data])
  const [edit, setEdit] = useState<InstallmentPlan | null | undefined>(undefined)
  const [view, setView] = useState<number | null>(null)
  const base = data?.meta.base_currency ?? ''

  const status = (p: InstallmentPlan) => (p.done ? <span className="tag quiet">{t('finished')}</span> : <DueTag days={p.days} />)

  return (
    <div className="list">
      <ListHead>
        <ModeToggle />
        <span className="count">
          {t('leftToPay')}: <span className="num">{n(data?.meta.remaining ?? 0)}</span> · {t('dueThisMonth')}: <span className="num">{n(data?.meta.this_month ?? 0)}</span> {base}
        </span>
        <span className="grow" />
        {!readOnly && <button type="button" className="btn" onClick={() => setEdit(null)}><Icon name="plus" /><span className="hide-sm">{t('add')}</span></button>}
      </ListHead>
      <Paged
        items={rows}
        resetKey={mode}
        empty={<div className="empty"><Icon name="bills" /><p>{t('noPlans')}</p></div>}
        container={(c) => mode === 'table' ? (
          <div className="tbl"><table>
            <thead><tr><th>{t('name')}</th><th>{t('installmentAmount')}</th><th>{t('paid')}</th><th>{t('leftToPay')}</th><th>{t('nextDue')}</th><th>{t('status')}</th></tr></thead>
            <tbody>{c}</tbody>
          </table></div>
        ) : <div className="grid">{c}</div>}
        renderItem={(p) => mode === 'table' ? (
          <tr key={p.id} onClick={() => setView(p.id)} className={p.done ? 'off' : ''}>
            <td><button type="button" className="row-link" onClick={() => setView(p.id)}>{p.name}{p.provider ? ` · ${p.provider}` : ''}</button></td>
            <td className="num">{n(p.installment_amount, 2)} {p.currency}</td>
            <td className="num">{t('paidOf', { a: p.paid_count, b: p.count })}</td>
            <td className="num neg">{n(p.remaining, 2)}</td>
            <td>{p.next_due ? fmtDate(p.next_due) : '—'}</td>
            <td>{status(p)}</td>
          </tr>
        ) : (
          <button type="button" key={p.id} className={`card ic${p.done ? ' off' : ''}`} onClick={() => setView(p.id)}>
            <div className="t"><span>{p.name}</span>{status(p)}</div>
            <div className="amt"><span className="num">{n(p.installment_amount)}</span> <small className="cur-sm">{p.currency}</small></div>
            <Steps done={p.paid_count} of={p.count} />
            <div className="meta"><span>{t('paidOf', { a: p.paid_count, b: p.count })}</span><span>{t('leftToPay')}: <span className="num">{n(p.remaining)}</span></span></div>
          </button>
        )}
      />
      {view !== null && <PlanDetail id={view} accounts={accounts.data ?? []} onClose={() => setView(null)} onEdit={(p) => { setView(null); setEdit(p) }} />}
      {edit !== undefined && <PlanForm plan={edit} accounts={accounts.data ?? []} cats={cats.data ?? []} onClose={() => setEdit(undefined)} />}
    </div>
  )
}

function PlanDetail({ id, accounts, onClose, onEdit }: { id: number; accounts: Account[]; onClose: () => void; onEdit: (p: InstallmentPlan) => void }) {
  const { t, num: n, date: fmtDate } = useI18n()
  const { readOnly } = useSession()
  const { data: p } = useApi<InstallmentPlan>(`/installments/${id}`)
  const [paying, setPaying] = useState(false)
  const act = useAction()
  const list = useMemo(() => p?.payments ?? [], [p])
  const accName = (aid: number | null) => accounts.find((a) => a.id === aid)?.name ?? t('noAccount')

  return (
    <DetailModal title={p ? p.name : t('loading')} onClose={onClose}
      footer={!readOnly && p ? (
        <>
          <button type="button" className="btn ghost" onClick={() => onEdit(p)}>{t('edit')}</button>
          <span className="grow" />
          {!p.done && <button type="button" className="btn" onClick={() => setPaying(true)}>{t('payInstallment')}</button>}
        </>
      ) : undefined}>
      {p && (
        <>
          <Facts items={[
            [t('installmentAmount'), <span className="num">{n(p.installment_amount, 2)} {p.currency}</span>],
            [t('paid'), t('paidOf', { a: p.paid_count, b: p.count })],
            [t('leftToPay'), <span className="num neg">{n(p.remaining, 2)}</span>],
            [t('nextDue'), p.next_due ? fmtDate(p.next_due) : '—'],
            [t('lastDue'), fmtDate(p.last_due)],
            [t('totalCost'), <span className="num">{n(p.total + (p.down_payment ?? 0), 2)}</span>],
          ]} />
          <h3 className="sub-h">{t('payments')}</h3>
          <Paged items={list} resetKey={String(id)}
            empty={<p className="muted none">{t('empty')}</p>}
            container={(c) => <div className="rows">{c}</div>}
            renderItem={(x, idx) => (
              <div className="li" key={x.id}>
                <span>#{n(x.number)} · {fmtDate(x.date)} · {accName(x.account_id)}</span>
                <span>
                  <b className="num">{n(x.amount, 2)}</b>
                  {!readOnly && idx === 0 && (
                    <button type="button" className="icon-btn sm" aria-label={t('delete')} title={t('undoLastHint')}
                      onClick={() => act(() => del(`/installments/${id}/payments/${x.id}`))}>
                      <Icon name="close" />
                    </button>
                  )}
                </span>
              </div>
            )} />
        </>
      )}
      {paying && p && (
        <MoveForm title={`${t('payInstallment')} — ${p.name}`} currency={p.currency} amount={p.installment_amount} account={p.account_id}
          accounts={accounts} url={`/installments/${p.id}/pay`} onClose={() => setPaying(false)} />
      )}
    </DetailModal>
  )
}

/** Pay an installment / a gameya round, or record the payout: amount, date, account. */
function MoveForm({ title, currency: cur, amount, account, accounts, url, onClose }: {
  title: string; currency: string; amount: number; account: number | null; accounts: Account[]; url: string; onClose: () => void
}) {
  const { t } = useI18n()
  const form = useForm({ amount: String(amount), date: isoDate(), account_id: account ? String(account) : '' }, onClose)
  const submit = () => form.run(() => post(url, { amount: num(form.f.amount), date: form.f.date, account_id: num(form.f.account_id) }))
  return (
    <FormModal title={title} errors={form.errors} busy={form.busy} onClose={onClose} onSubmit={submit} fields={[
      money(form, 'amount', `${t('amount')} (${cur})`),
      date(form, 'date', t('date')),
      select(form, 'account_id', t('moneyAccount'), active(accounts, account).map((a) => [a.id, `${a.name} · ${a.currency}`]), { empty: t('noAccount'), full: true }),
    ]} />
  )
}

function PlanForm({ plan, accounts, cats, onClose }: { plan: InstallmentPlan | null; accounts: Account[]; cats: Category[]; onClose: () => void }) {
  const i = useI18n()
  const { t } = i
  const s = (v: number | null | undefined) => (v === null || v === undefined ? '' : String(v))
  const form = useForm({
    name: plan?.name ?? '', provider: plan?.provider ?? '', installment_amount: s(plan?.installment_amount), count: s(plan?.count ?? 12),
    currency: plan?.currency ?? 'EGP', first_due_date: plan?.first_due_date ?? isoDate(), cycle: plan?.cycle ?? 'monthly',
    down_payment: s(plan?.down_payment), account_id: s(plan?.account_id), category_id: s(plan?.category_id ?? cats.find((c) => c.key === 'shopping')?.id),
    remind_days: s(plan?.remind_days), notes: plan?.notes ?? '',
  }, onClose)
  const f = form.f
  const submit = () => form.run(() => {
    const body = {
      name: f.name, provider: orNull(f.provider), installment_amount: num(f.installment_amount), count: num(f.count), currency: f.currency,
      first_due_date: f.first_due_date, cycle: f.cycle, down_payment: num(f.down_payment), account_id: num(f.account_id),
      category_id: num(f.category_id), remind_days: num(f.remind_days), notes: orNull(f.notes),
    }
    return plan ? patch(`/installments/${plan.id}`, body) : post('/installments', body)
  })
  return (
    <FormModal title={plan ? t('editPlan') : t('newPlan')} errors={form.errors} busy={form.busy} onClose={onClose} onSubmit={submit}
      extra={plan ? <DeleteButton busy={form.busy} onConfirm={() => form.run(() => del(`/installments/${plan.id}`))} /> : undefined}
      fields={[
        text(form, 'name', t('name'), { maxLength: 120 }),
        text(form, 'provider', t('provider'), { maxLength: 120, placeholder: t('providerHint') }),
        money(form, 'installment_amount', t('installmentAmount')),
        currency(form, 'currency', t('currency')),
        text(form, 'count', t('installmentsCount'), { inputMode: 'numeric', num: true }),
        select(form, 'cycle', t('cycle'), [['monthly', t('monthly')], ['quarterly', t('quarterly')], ['weekly', t('weekly')]]),
        date(form, 'first_due_date', t('firstDue')),
        money(form, 'down_payment', t('downPayment')),
        select(form, 'account_id', t('fromAccount'), active(accounts, plan?.account_id).map((a) => [a.id, `${a.name} · ${a.currency}`]), { empty: t('noAccount') }),
        select(form, 'category_id', t('category'), cats.map((c) => [c.id, catName(i, c)]), { empty: t('noCategory') }),
        text(form, 'remind_days', t('remindDays'), { inputMode: 'numeric', num: true, placeholder: t('defaultHint') }),
        text(form, 'notes', t('notes'), { full: true, maxLength: 2000 }),
      ]} />
  )
}

// ---------------------------------------------------------------- gameyas

export function Gameyas() {
  const { t, num: n, date: fmtDate } = useI18n()
  const { readOnly } = useSession()
  const { data } = useApi<Listed<Gameya, { base_currency: string; position: number }>>('/gameyas')
  const shared = useApi<SharedGameyas>('/shared-gameyas')
  const accounts = useApi<Account[]>('/accounts')
  const act = useAction()
  const rows = useMemo(() => data?.data ?? [], [data])
  const [edit, setEdit] = useState<Gameya | null | undefined>(undefined)
  const [view, setView] = useState<number | null>(null)
  const [scope, setScope] = useState<'mine' | 'shared'>('mine')
  const pos = data?.meta.position ?? 0
  const invites = shared.data?.invites.length ?? 0
  const sharedCount = (shared.data?.data.length ?? 0) + invites

  return (
    <div className="list">
      <ListHead>
        {sharedCount > 0 && (
          <div className="seg" role="radiogroup" aria-label={t('gameyas')}>
            <button type="button" role="radio" aria-checked={scope === 'mine'} className={scope === 'mine' ? 'on' : ''} onClick={() => setScope('mine')}>{t('myGameyas')}</button>
            <button type="button" role="radio" aria-checked={scope === 'shared'} className={scope === 'shared' ? 'on' : ''} onClick={() => setScope('shared')}>
              {t('sharedWithMe')}{invites > 0 && <span className="dot-count">{n(invites)}</span>}
            </button>
          </div>
        )}
        {scope === 'mine' && (
          <span className="count">
            {t('netPosition')}: <span className={`num${pos > 0 ? ' pos' : pos < 0 ? ' neg' : ''}`}>{n(pos)}</span> {data?.meta.base_currency}
          </span>
        )}
        <span className="grow" />
        {!readOnly && scope === 'mine' && <button type="button" className="btn" onClick={() => setEdit(null)}><Icon name="plus" /><span className="hide-sm">{t('add')}</span></button>}
      </ListHead>
      {scope === 'shared' ? <SharedList data={shared.data} act={act} /> : <Paged
        items={rows}
        empty={<div className="empty"><Icon name="family" /><p>{t('noGameyas')}</p></div>}
        container={(c) => <div className="grid">{c}</div>}
        renderItem={(g) => g.role === 'manager' ? (
          <button type="button" key={g.id} className={`card ic${g.done ? ' off' : ''}`} onClick={() => setView(g.id)}>
            <div className="t"><span>{g.name}</span><span className="tag quiet">{t('managerLbl')}</span>{!!g.claims && <span className="tag">{t('claimsN', { n: g.claims })}</span>}</div>
            <div className="amt"><span className="num">{n(g.amount)}</span> <small className="cur-sm">{g.currency} · {t('roundN', { n: g.current_round ?? 1 })}</small></div>
            <Steps done={g.paid_this_round ?? 0} of={g.members} />
            <div className="meta">
              <span>{t('paidThisRound')}: {t('paidOf', { a: g.paid_this_round ?? 0, b: g.members })}</span>
              <span>{g.my_turn ? `${t('myTurn')}: ${n(g.my_turn)}` : t('noTurnLbl')}</span>
            </div>
          </button>
        ) : (
          <button type="button" key={g.id} className={`card ic${g.done ? ' off' : ''}`} onClick={() => setView(g.id)}>
            <div className="t"><span>{g.name}</span>{g.done ? <span className="tag quiet">{t('finished')}</span> : <DueTag days={g.days} />}</div>
            <div className="amt"><span className="num">{n(g.amount)}</span> <small className="cur-sm">{g.currency} · {t('roundN', { n: g.next_round ?? g.members })}</small></div>
            <Steps done={g.paid_rounds} of={g.members} />
            <div className="meta">
              <span>{t('payoutDate')}: {g.received || !g.payout_date ? t('gotPayout') : fmtDate(g.payout_date)}</span>
              <span>{t('payoutAmt')}: <span className="num">{n(g.payout)}</span></span>
            </div>
          </button>
        )}
      />}
      {view !== null && <GameyaDetail id={view} accounts={accounts.data ?? []} onClose={() => setView(null)} onEdit={(g) => { setView(null); setEdit(g) }} />}
      {edit !== undefined && <GameyaForm gameya={edit} accounts={accounts.data ?? []} onClose={() => setEdit(undefined)} />}
    </div>
  )
}

function GameyaDetail({ id, accounts, onClose, onEdit }: { id: number; accounts: Account[]; onClose: () => void; onEdit: (g: Gameya) => void }) {
  const { t, num: n, date: fmtDate } = useI18n()
  const { readOnly } = useSession()
  const { data: g } = useApi<Gameya>(`/gameyas/${id}`)
  const [move, setMove] = useState<'pay' | 'receive' | null>(null)
  const act = useAction()
  const list = useMemo(() => g?.payments ?? [], [g])
  const lastRound = Math.max(0, ...list.filter((x) => x.kind === 'contribution').map((x) => x.round))

  return (
    <DetailModal title={g ? g.name : t('loading')} onClose={onClose}
      footer={!readOnly && g ? (
        <>
          <button type="button" className="btn ghost" onClick={() => onEdit(g)}>{t('edit')}</button>
          <span className="grow" />
          {g.role === 'manager' && !!g.linked && <button type="button" className="btn ghost" onClick={() => act(() => post(`/gameyas/${g.id}/remind`, {}))}>{t('remindUnpaid')}</button>}
          {g.my_turn !== null && !g.received && <button type="button" className="btn ghost" onClick={() => setMove('receive')}>{t('gotPayout')}</button>}
          {g.next_round !== null && <button type="button" className="btn" onClick={() => setMove('pay')}>{t('payRound')}</button>}
        </>
      ) : undefined}>
      {g && g.role === 'manager' && (
        <>
          <Facts items={[
            [t('perRound'), <span className="num">{n(g.amount, 2)} {g.currency}</span>],
            [t('members'), <span className="num">{n(g.members)}</span>],
            [t('currentRound'), `${t('roundN', { n: g.current_round ?? 1 })} · ${fmtDate(g.current_date ?? g.start_date)}`],
            [t('payoutAmt'), <span className="num">{n(g.payout, 2)}</span>],
            [t('myTurn'), g.my_turn ? t('roundN', { n: g.my_turn }) : t('noTurnLbl')],
            [t('endDate'), fmtDate(g.end_date)],
          ]} />
          <ManagedSlots g={g} act={act} />
        </>
      )}
      {g && g.role !== 'manager' && (
        <>
          <Facts items={[
            [t('perRound'), <span className="num">{n(g.amount, 2)} {g.currency}</span>],
            [t('members'), <span className="num">{n(g.members)}</span>],
            [t('paid'), g.next_round === null ? t('allRoundsPaid') : t('paidOf', { a: g.paid_rounds, b: g.members })],
            [t('myTurn'), t('roundN', { n: g.my_turn ?? 0 })],
            [t('payoutDate'), g.received || !g.payout_date ? t('gotPayout') : fmtDate(g.payout_date)],
            [t('payoutAmt'), <span className="num">{n(g.payout, 2)}</span>],
            [t('netPosition'), <span className={`num${g.position > 0 ? ' pos' : g.position < 0 ? ' neg' : ''}`} title={t(g.position >= 0 ? 'groupOwesYou' : 'youOweGroup')}>{n(g.position, 2)}</span>],
          ]} />
          <h3 className="sub-h">{t('payments')}</h3>
          <Paged items={list} resetKey={String(id)}
            empty={<p className="muted none">{t('empty')}</p>}
            container={(c) => <div className="rows">{c}</div>}
            renderItem={(x) => (
              <div className="li" key={x.id}>
                <span>{t('roundN', { n: x.round })} · {t(x.kind === 'payout' ? 'payoutLbl' : 'contribution')} · {fmtDate(x.date)}</span>
                <span>
                  <b className={`num${x.kind === 'payout' ? ' pos' : ''}`}>{n(x.amount, 2)}</b>
                  {!readOnly && (x.kind === 'payout' || x.round === lastRound) && (
                    <button type="button" className="icon-btn sm" aria-label={t('delete')} title={t('undoLastHint')}
                      onClick={() => act(() => del(`/gameyas/${id}/payments/${x.id}`))}>
                      <Icon name="close" />
                    </button>
                  )}
                </span>
              </div>
            )} />
        </>
      )}
      {move === 'pay' && g && (
        <MoveForm title={`${t('payRound')} — ${t('roundN', { n: g.next_round ?? 0 })}`} currency={g.currency} amount={g.amount} account={g.account_id}
          accounts={accounts} url={`/gameyas/${g.id}/pay`} onClose={() => setMove(null)} />
      )}
      {move === 'receive' && g && (
        <MoveForm title={`${t('gotPayout')} — ${g.name}`} currency={g.currency} amount={g.payout} account={g.account_id}
          accounts={accounts} url={`/gameyas/${g.id}/receive`} onClose={() => setMove(null)} />
      )}
    </DetailModal>
  )
}

function GameyaForm({ gameya, accounts, onClose }: { gameya: Gameya | null; accounts: Account[]; onClose: () => void }) {
  const { t } = useI18n()
  const s = (v: number | null | undefined) => (v === null || v === undefined ? '' : String(v))
  const form = useForm({
    role: gameya?.role ?? 'member', name: gameya?.name ?? '', organizer: gameya?.organizer ?? '', members: s(gameya?.members ?? 10), my_turn: s(gameya ? gameya.my_turn : 1),
    amount: s(gameya?.amount), currency: gameya?.currency ?? 'EGP', start_date: gameya?.start_date ?? isoDate(), cycle: gameya?.cycle ?? 'monthly',
    account_id: s(gameya?.account_id), remind_days: s(gameya?.remind_days), notes: gameya?.notes ?? '',
  }, onClose)
  const f = form.f
  const submit = () => form.run(() => {
    const body = {
      ...(gameya ? {} : { role: f.role }),
      name: f.name, organizer: orNull(f.organizer), members: num(f.members), my_turn: num(f.my_turn), amount: num(f.amount), currency: f.currency,
      start_date: f.start_date, cycle: f.cycle, account_id: num(f.account_id), remind_days: num(f.remind_days), notes: orNull(f.notes),
    }
    return gameya ? patch(`/gameyas/${gameya.id}`, body) : post('/gameyas', body)
  })
  return (
    <FormModal title={gameya ? t('editGameya') : t('newGameya')} errors={form.errors} busy={form.busy} onClose={onClose} onSubmit={submit}
      extra={gameya ? <DeleteButton busy={form.busy} onConfirm={() => form.run(() => del(`/gameyas/${gameya.id}`))} /> : undefined}
      fields={[
        select(form, 'role', t('gameyaRole'), [['member', t('roleMember')], ['manager', t('roleManager')]], { full: true, hidden: !!gameya }),
        text(form, 'name', t('name'), { maxLength: 120 }),
        text(form, 'organizer', t('organizer'), { maxLength: 120, hidden: f.role === 'manager' }),
        money(form, 'amount', t('perRound')),
        currency(form, 'currency', t('currency')),
        text(form, 'members', t('members'), { inputMode: 'numeric', num: true }),
        text(form, 'my_turn', t('myTurn'), { inputMode: 'numeric', num: true, placeholder: f.role === 'manager' ? t('noTurnHint') : undefined }),
        date(form, 'start_date', t('startDate')),
        select(form, 'cycle', t('cycle'), [['monthly', t('monthly')], ['weekly', t('weekly')]]),
        select(form, 'account_id', t('moneyAccount'), active(accounts, gameya?.account_id).map((a) => [a.id, `${a.name} · ${a.currency}`]), { empty: t('noAccount') }),
        text(form, 'remind_days', t('remindDays'), { inputMode: 'numeric', num: true, placeholder: t('defaultHint') }),
        text(form, 'notes', t('notes'), { full: true, maxLength: 2000 }),
      ]} />
  )
}
