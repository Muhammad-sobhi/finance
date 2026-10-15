import { useMemo, useState } from 'react'
import { CategoryForm } from '../components/CategoryForm'
import { Icon } from '../components/Icon'
import { ListHead, ModeToggle, useListMode } from '../components/ListHead'
import { Paged } from '../components/Paged'
import { useApi } from '../hooks/useApi'
import { useI18n } from '../i18n'
import { catName } from '../lib/labels'
import { useSession } from '../lib/session'
import type { Category, CategoryType } from '../lib/types'

export function CatBadge({ c }: { c: Pick<Category, 'icon' | 'color'> | null | undefined }) {
  return <span className="cat-ic" style={{ background: c?.color ?? 'var(--muted)' }}><Icon name={c?.icon ?? 'tag'} /></span>
}

export function Categories() {
  const i = useI18n()
  const { t, num } = i
  const { readOnly } = useSession()
  const mode = useListMode()
  const [type, setType] = useState<CategoryType>('expense')
  const { data } = useApi<Category[]>(`/categories?type=${type}`)
  const rows = useMemo(() => [...(data ?? [])].sort((a, b) => a.sort - b.sort || a.id - b.id), [data])
  const [edit, setEdit] = useState<Category | null | undefined>(undefined)

  return (
    <div className="list">
      <ListHead>
        <div className="seg" role="radiogroup" aria-label={t('type')}>
          {(['expense', 'income'] as const).map((k) => (
            <button type="button" key={k} role="radio" aria-checked={type === k} className={type === k ? 'on' : ''} onClick={() => setType(k)}>{t(k)}</button>
          ))}
        </div>
        <ModeToggle />
        <span className="grow" />
        {!readOnly && <button type="button" className="btn" onClick={() => setEdit(null)}><Icon name="plus" /><span className="hide-sm">{t('add')}</span></button>}
      </ListHead>
      <Paged
        items={rows}
        resetKey={`${type}|${mode}`}
        empty={<div className="empty"><Icon name="categories" /><p>{t('empty')}</p></div>}
        container={(children) => mode === 'table' ? (
          <div className="tbl">
            <table>
              <thead><tr><th>{t('name')}</th><th>{t('type')}</th><th>{t('transactions')} (90)</th></tr></thead>
              <tbody>{children}</tbody>
            </table>
          </div>
        ) : <div className="grid">{children}</div>}
        renderItem={(c) => mode === 'table' ? (
          <tr key={c.id} onClick={() => setEdit(c)}>
            <td><button type="button" className="row-link cat-name" onClick={() => setEdit(c)}><CatBadge c={c} />{catName(i, c)}</button></td>
            <td>{t(c.type)}</td>
            <td className="num">{num(c.usage_count)}</td>
          </tr>
        ) : (
          <button type="button" key={c.id} className="card ic" onClick={() => setEdit(c)}>
            <div className="t"><span className="cat-name"><CatBadge c={c} />{catName(i, c)}</span><span className="tag quiet">{t(c.type)}</span></div>
            <div className="meta"><span>{t('txCount', { n: c.usage_count })}</span></div>
          </button>
        )}
      />
      {edit !== undefined && <CategoryForm category={edit} type={type} onClose={() => setEdit(undefined)} />}
    </div>
  )
}
