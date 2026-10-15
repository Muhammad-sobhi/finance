import { useState } from 'react'
import { useI18n } from '../i18n'
import { ApiError, del, patch, post } from '../lib/api'
import { catName } from '../lib/labels'
import { useSession } from '../lib/session'
import type { Category, CategoryType } from '../lib/types'
import { CATEGORY_ICONS, Icon } from './Icon'
import { DeleteButton, FormModal, type Field } from './Modal'

const COLORS = ['#c0623b', '#9b2c2c', '#b08d57', '#2e5e3e', '#6b8f71', '#1e3a5f', '#4a6fa5', '#8a5a44', '#555555', '#6b6a5c']

export function CategoryForm({ category, type, onClose }: { category?: Category | null; type: CategoryType; onClose: () => void }) {
  const i = useI18n()
  const { t } = i
  const { refresh, toast, readOnly } = useSession()
  const [f, setF] = useState({
    name: category ? catName(i, category) : '',
    type: category?.type ?? type,
    icon: category?.icon ?? 'tag',
    color: category?.color ?? COLORS[0],
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)

  const run = async (fn: () => Promise<unknown>) => {
    setBusy(true)
    try {
      await fn()
      toast(t('saved'))
      refresh()
      onClose()
    } catch (err) {
      if (err instanceof ApiError) setErrors(err.fieldErrors)
      else toast(t('error'))
    } finally {
      setBusy(false)
    }
  }

  const submit = () => run(() => {
    // Keep a seeded category translatable unless the user actually renamed it.
    const body: Record<string, unknown> = { type: f.type, icon: f.icon, color: f.color }
    if (!category || f.name !== catName(i, category)) body.name = f.name
    return category ? patch(`/categories/${category.id}`, body) : post('/categories', body)
  })

  const fields: Field[] = [
    { name: 'name', label: t('name'), control: <input id="f-name" className="inp" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} maxLength={60} required /> },
    {
      name: 'type', label: t('type'),
      control: (
        <select id="f-type" className="inp" value={f.type} onChange={(e) => setF({ ...f, type: e.target.value as CategoryType })}>
          <option value="expense">{t('expense')}</option>
          <option value="income">{t('income')}</option>
        </select>
      ),
    },
    {
      name: 'icon', label: t('icon'), full: true,
      control: (
        <div className="icon-pick" id="f-icon" role="radiogroup">
          {CATEGORY_ICONS.map((k) => (
            <button type="button" key={k} role="radio" aria-checked={f.icon === k} aria-label={k} className={f.icon === k ? 'on' : ''}
              onClick={() => setF({ ...f, icon: k })}><Icon name={k} /></button>
          ))}
        </div>
      ),
    },
    {
      name: 'color', label: t('color'), full: true,
      control: (
        <div className="color-pick" id="f-color" role="radiogroup">
          {COLORS.map((c) => (
            <button type="button" key={c} role="radio" aria-checked={f.color === c} aria-label={c} className={f.color === c ? 'on' : ''}
              style={{ background: c }} onClick={() => setF({ ...f, color: c })} />
          ))}
          <input type="color" aria-label={t('color')} value={f.color} onChange={(e) => setF({ ...f, color: e.target.value })} />
        </div>
      ),
    },
  ]

  return (
    <FormModal title={category ? t('editCategory') : t('newCategory')} fields={fields} errors={errors} busy={busy || readOnly}
      onClose={onClose} onSubmit={submit}
      extra={category && !readOnly ? <DeleteButton busy={busy} onConfirm={() => run(() => del(`/categories/${category.id}`))} /> : undefined} />
  )
}
