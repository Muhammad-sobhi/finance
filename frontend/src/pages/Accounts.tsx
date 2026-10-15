import { useMemo, useState } from 'react'
import { AccountForm } from '../components/AccountForm'
import { Icon } from '../components/Icon'
import { ListHead, ModeToggle, useListMode } from '../components/ListHead'
import { Paged } from '../components/Paged'
import { useApi } from '../hooks/useApi'
import { useI18n } from '../i18n'
import { useSession } from '../lib/session'
import type { Account } from '../lib/types'

export function Accounts() {
  const i = useI18n()
  const { t, num, date } = i
  const { readOnly, user } = useSession()
  const mode = useListMode()
  const { data } = useApi<Account[]>('/accounts')
  const [edit, setEdit] = useState<Account | null | undefined>(undefined)
  const rows = useMemo(() => data ?? [], [data])
  const total = rows.filter((a) => a.is_active).reduce((s, a) => s + (a.base_balance ?? 0), 0)
  const base = user?.base_currency ?? ''

  const amount = (a: Account) => (
    <span className={`num${a.balance < 0 ? ' neg' : ''}`}>{num(a.balance, 2)}{a.currency !== base ? ` ${a.currency}` : ''}</span>
  )
  const updated = (a: Account) => (a.updated_at ? date(a.updated_at.slice(0, 10), { day: 'numeric', month: 'short' }) : '—')

  return (
    <div className="list">
      <ListHead>
        <ModeToggle />
        <span className="count">{t('accountsCount', { n: rows.length })} · <span className="num">{num(total)}</span> {base}</span>
        <span className="grow" />
        {!readOnly && <button type="button" className="btn" onClick={() => setEdit(null)}><Icon name="plus" /><span className="hide-sm">{t('add')}</span></button>}
      </ListHead>
      <Paged
        items={rows}
        resetKey={mode}
        empty={<div className="empty"><Icon name="accounts" /><p>{t('empty')}</p></div>}
        container={(children) => mode === 'table' ? (
          <div className="tbl">
            <table>
              <thead><tr><th>{t('name')}</th><th>{t('type')}</th><th>{t('provider')}</th><th>{t('balance')}</th><th>{t('updated')}</th></tr></thead>
              <tbody>{children}</tbody>
            </table>
          </div>
        ) : <div className="grid">{children}</div>}
        renderItem={(a) => mode === 'table' ? (
          <tr key={a.id} onClick={() => setEdit(a)} className={a.is_active ? '' : 'off'}>
            <td><button type="button" className="row-link" onClick={() => setEdit(a)}>{a.name}</button></td>
            <td>{i.e(a.type)}</td>
            <td>{a.provider ?? '—'}</td>
            <td>{amount(a)}</td>
            <td>{updated(a)}</td>
          </tr>
        ) : (
          <button type="button" key={a.id} className={`card ic${a.is_active ? '' : ' off'}`} onClick={() => setEdit(a)}>
            <div className="t"><span>{a.name}</span><span className="tag quiet">{a.is_active ? i.e(a.type) : t('inactive')}</span></div>
            <div className="amt">{amount(a)}</div>
            <div className="meta"><span>{a.provider ?? i.e(a.type)}</span><span>{a.last4 ? `•••• ${a.last4}` : updated(a)}</span></div>
          </button>
        )}
      />
      {edit !== undefined && <AccountForm account={edit} onClose={() => setEdit(undefined)} />}
    </div>
  )
}
