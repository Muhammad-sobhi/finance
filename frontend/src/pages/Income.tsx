import { useMemo, useState } from 'react'
import { BarChart } from '../components/Charts'
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
import { del, patch, post } from '../lib/api'
import { isoDate, monthIndex } from '../lib/dates'
import { catName } from '../lib/labels'
import { useSession } from '../lib/session'
import type { Account, Category, IncomeSummary, Listed, Project } from '../lib/types'
import { Recurring } from './Recurring'

type Tab = 'overview' | 'recurring' | 'projects'

/** Income: overview, recurring income (salary…) and freelance projects. */
export function Income() {
  const { t } = useI18n()
  const [tab, setTab] = useState<Tab>('overview')
  return (
    <div className="list income-page">
      <div className="list-head">
        <div className="seg" role="tablist" aria-label={t('incomeNav')}>
          {(['overview', 'recurring', 'projects'] as const).map((k) => (
            <button type="button" key={k} role="tab" aria-selected={tab === k} className={tab === k ? 'on' : ''} onClick={() => setTab(k)}>
              {k === 'recurring' ? t('recurringIncome') : t(k)}
            </button>
          ))}
        </div>
      </div>
      <div className="tab-body">
        {tab === 'overview' && <Overview />}
        {tab === 'recurring' && <Recurring kind="income-sources" />}
        {tab === 'projects' && <Projects />}
      </div>
    </div>
  )
}

function Overview() {
  const { t, num: n, d } = useI18n()
  const { data: s } = useApi<IncomeSummary>('/income/summary')
  const series = [
    { key: 'recurring', label: t('fromRecurring'), color: 1 as const },
    { key: 'projects', label: t('fromProjects'), color: 2 as const },
    { key: 'other', label: t('otherIncome'), color: 3 as const },
  ]
  const base = s?.base_currency ?? ''
  return (
    <div className="income-ov">
      <div className="card kpi"><div className="lbl">{t('receivedMonth')}</div><div className="v num pos">{s ? n(s.received.total) : '…'}</div><div className="d muted">{base}</div></div>
      <div className="card kpi">
        <div className="lbl">{t('bySource')}</div>
        <div className="breakdown">
          {series.map((x) => (
            <span key={x.key}><i className="sw" style={{ background: `var(--c${x.color})` }} />{x.label}<b>{s ? n(s.received[x.key as 'recurring']) : '…'}</b></span>
          ))}
        </div>
      </div>
      <div className="card kpi"><div className="lbl">{t('expectedMonth')}</div><div className="v num">{s ? n(s.expected) : '…'}</div><div className="d muted">{base}</div></div>
      <div className="card kpi"><div className="lbl">{t('outstanding')}</div><div className={`v num${s && s.outstanding > 0 ? ' neg' : ''}`}>{s ? n(s.outstanding) : '…'}</div><div className="d muted">{base}</div></div>
      <div className="card ov-chart">
        <h3>{t('incomeNav')}<small>{base}</small></h3>
        <BarChart
          stacked
          points={(s?.series ?? []).map((m) => ({ label: d.months[monthIndex(m.month)], values: { recurring: m.recurring, projects: m.projects, other: m.other } }))}
          series={series}
          format={(v) => `${n(v)} ${base}`}
          ariaLabel={(s?.series ?? []).map((m) => `${d.months[monthIndex(m.month)]}: ${n(m.recurring + m.projects + m.other)}`).join(', ')}
        />
      </div>
    </div>
  )
}

function Projects() {
  const { t, num: n, date: fmtDate } = useI18n()
  const { readOnly } = useSession()
  const mode = useListMode()
  const { data } = useApi<Listed<Project, { base_currency: string; outstanding: number; received: number }>>('/projects')
  const accounts = useApi<Account[]>('/accounts')
  const cats = useApi<Category[]>('/categories?type=income')
  const rows = useMemo(() => data?.data ?? [], [data])
  const [edit, setEdit] = useState<Project | null | undefined>(undefined)
  const [view, setView] = useState<number | null>(null)

  const status = (p: Project) => (p.status === 'active' && p.outstanding > 0 ? <DueTag days={p.days} /> : <span className="tag quiet">{t(`proj_${p.status}`)}</span>)
  const out = (p: Project) => <span className={`num${p.outstanding > 0 ? ' neg' : ' pos'}`}>{n(p.outstanding, 2)}</span>

  return (
    <div className="list">
      <ListHead>
        <ModeToggle />
        <span className="count">{t('outstanding')}: <span className="num">{n(data?.meta.outstanding ?? 0)}</span> {data?.meta.base_currency}</span>
        <span className="grow" />
        {!readOnly && <button type="button" className="btn" onClick={() => setEdit(null)}><Icon name="plus" /><span className="hide-sm">{t('add')}</span></button>}
      </ListHead>
      <Paged
        items={rows}
        resetKey={mode}
        empty={<div className="empty"><Icon name="work" /><p>{t('empty')}</p></div>}
        container={(c) => mode === 'table' ? (
          <div className="tbl"><table>
            <thead><tr><th>{t('client')}</th><th>{t('projectName')}</th><th>{t('agreed')}</th><th>{t('received')}</th><th>{t('remaining')}</th><th>{t('paymentDue')}</th><th>{t('status')}</th></tr></thead>
            <tbody>{c}</tbody>
          </table></div>
        ) : <div className="grid">{c}</div>}
        renderItem={(p) => mode === 'table' ? (
          <tr key={p.id} onClick={() => setView(p.id)} className={p.status === 'cancelled' ? 'off' : ''}>
            <td><button type="button" className="row-link" onClick={() => setView(p.id)}>{p.client}</button></td>
            <td>{p.name}</td>
            <td className="num">{n(p.amount, 2)} {p.currency}</td>
            <td className="num">{n(p.received, 2)}</td>
            <td>{out(p)}</td>
            <td>{p.due_date ? fmtDate(p.due_date) : '—'}</td>
            <td>{status(p)}</td>
          </tr>
        ) : (
          <button type="button" key={p.id} className={`card ic${p.status === 'cancelled' ? ' off' : ''}`} onClick={() => setView(p.id)}>
            <div className="t"><span>{p.client}</span>{status(p)}</div>
            <div className="amt">{out(p)} <small className="cur-sm">{p.currency}</small></div>
            <div className="meta"><span>{p.name}</span><span>{t('received')}: <span className="num">{n(p.received)}</span> / <span className="num">{n(p.amount)}</span></span></div>
          </button>
        )}
      />
      {view !== null && <ProjectDetail id={view} accounts={accounts.data ?? []} onClose={() => setView(null)} onEdit={(p) => { setView(null); setEdit(p) }} />}
      {edit !== undefined && <ProjectForm project={edit} cats={cats.data ?? []} onClose={() => setEdit(undefined)} />}
    </div>
  )
}

function ProjectDetail({ id, accounts, onClose, onEdit }: { id: number; accounts: Account[]; onClose: () => void; onEdit: (p: Project) => void }) {
  const { t, num: n, date: fmtDate } = useI18n()
  const { readOnly, refresh, toast } = useSession()
  const { data: p } = useApi<Project>(`/projects/${id}`)
  const [paying, setPaying] = useState(false)
  const payments = useMemo(() => p?.payments ?? [], [p])
  const accName = (aid: number | null) => accounts.find((a) => a.id === aid)?.name ?? '—'

  return (
    <DetailModal title={p ? `${p.client} — ${p.name}` : t('loading')} onClose={onClose}
      footer={!readOnly && p ? (
        <>
          <button type="button" className="btn ghost" onClick={() => onEdit(p)}>{t('edit')}</button>
          <span className="grow" />
          {p.outstanding > 0 && p.status !== 'cancelled' && <button type="button" className="btn" onClick={() => setPaying(true)}>{t('addPayment')}</button>}
        </>
      ) : undefined}>
      {p && (
        <>
          <Facts items={[
            [t('agreed'), <span className="num">{n(p.amount, 2)} {p.currency}</span>],
            [t('received'), <span className="num pos">{n(p.received, 2)}</span>],
            [t('remaining'), <span className="num neg">{n(p.outstanding, 2)}</span>],
            [t('paymentDue'), p.due_date ? fmtDate(p.due_date) : '—'],
            [t('status'), t(`proj_${p.status}`)],
          ]} />
          <h3 className="sub-h">{t('payments')}</h3>
          <Paged items={payments} resetKey={String(id)}
            empty={<p className="muted none">{t('empty')}</p>}
            container={(c) => <div className="rows">{c}</div>}
            renderItem={(x) => (
              <div className="li" key={x.id}>
                <span>{fmtDate(x.date)} · {accName(x.account_id)}{x.note ? ` · ${x.note}` : ''}</span>
                <span>
                  <b className="num">{n(x.amount, 2)}</b>
                  {!readOnly && (
                    <button type="button" className="icon-btn sm" aria-label={t('delete')}
                      onClick={() => del(`/projects/${id}/payments/${x.id}`).then(() => { toast(t('saved')); refresh() }).catch(() => toast(t('error')))}>
                      <Icon name="close" />
                    </button>
                  )}
                </span>
              </div>
            )} />
        </>
      )}
      {paying && p && <ProjectPaymentForm project={p} accounts={accounts} onClose={() => setPaying(false)} />}
    </DetailModal>
  )
}

function ProjectPaymentForm({ project, accounts, onClose }: { project: Project; accounts: Account[]; onClose: () => void }) {
  const { t } = useI18n()
  const form = useForm({ amount: String(project.outstanding), date: isoDate(), account_id: String(accounts.find((a) => a.is_active)?.id ?? ''), note: '' }, onClose)
  const submit = () => form.run(() => post(`/projects/${project.id}/payments`, {
    amount: num(form.f.amount), date: form.f.date, account_id: num(form.f.account_id), note: orNull(form.f.note),
  }))
  return (
    <FormModal title={`${t('addPayment')} — ${project.client}`} errors={form.errors} busy={form.busy} onClose={onClose} onSubmit={submit} fields={[
      money(form, 'amount', `${t('amount')} (${project.currency})`),
      date(form, 'date', t('date')),
      select(form, 'account_id', t('toAccountIn'), accounts.filter((a) => a.is_active).map((a) => [a.id, `${a.name} · ${a.currency}`]), { full: true }),
      text(form, 'note', t('note'), { full: true, maxLength: 500 }),
    ]} />
  )
}

function ProjectForm({ project, cats, onClose }: { project: Project | null; cats: Category[]; onClose: () => void }) {
  const i = useI18n()
  const { t } = i
  const { readOnly } = useSession()
  const form = useForm({
    client: project?.client ?? '', name: project?.name ?? '', amount: project ? String(project.amount) : '', currency: project?.currency ?? 'EGP',
    start_date: project?.start_date ?? isoDate(), due_date: project?.due_date ?? '', status: project?.status ?? 'active',
    category_id: project?.category_id ? String(project.category_id) : '', remind_days: project?.remind_days != null ? String(project.remind_days) : '',
    notes: project?.notes ?? '',
  }, onClose)
  const f = form.f
  const submit = () => form.run(() => {
    const body = {
      client: f.client, name: f.name, amount: num(f.amount), currency: f.currency, start_date: orNull(f.start_date), due_date: orNull(f.due_date),
      status: f.status, category_id: num(f.category_id), remind_days: num(f.remind_days), notes: orNull(f.notes),
    }
    return project ? patch(`/projects/${project.id}`, body) : post('/projects', body)
  })
  return (
    <FormModal title={project ? t('editProject') : t('newProject')} errors={form.errors} busy={form.busy || readOnly} onClose={onClose} onSubmit={submit}
      extra={project && !readOnly ? <DeleteButton busy={form.busy} onConfirm={() => form.run(() => del(`/projects/${project.id}`))} /> : undefined}
      fields={[
        text(form, 'client', t('client'), { maxLength: 120 }),
        text(form, 'name', t('projectName'), { maxLength: 160 }),
        money(form, 'amount', t('agreed')),
        currency(form, 'currency', t('currency')),
        date(form, 'start_date', t('startDate')),
        date(form, 'due_date', t('paymentDue')),
        select(form, 'status', t('status'), [['active', t('proj_active')], ['done', t('proj_done')], ['cancelled', t('proj_cancelled')]], { hidden: !project }),
        select(form, 'category_id', t('category'), cats.map((c) => [c.id, catName(i, c)]), { empty: i.e('freelance') }),
        text(form, 'remind_days', t('remindDays'), { inputMode: 'numeric', num: true, placeholder: t('defaultHint') }),
        text(form, 'notes', t('notes'), { full: true, maxLength: 2000 }),
      ]} />
  )
}
