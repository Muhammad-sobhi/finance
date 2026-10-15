import { useMemo, useState } from 'react'
import { currency, date, money, select, text } from '../components/fields'
import { Icon } from '../components/Icon'
import { ListHead, ModeToggle, useListMode } from '../components/ListHead'
import { DeleteButton, FormModal } from '../components/Modal'
import { Paged } from '../components/Paged'
import { useApi } from '../hooks/useApi'
import { num, orNull, useForm } from '../hooks/useForm'
import { useI18n } from '../i18n'
import { del, patch, post } from '../lib/api'
import { useSession } from '../lib/session'
import { ASSET_TYPES, type Asset, type Listed } from '../lib/types'

const KARATS = [24, 22, 21, 18, 14, 12, 10, 9]

export function Assets() {
  const i = useI18n()
  const { t, num: n } = i
  const { readOnly } = useSession()
  const mode = useListMode()
  const { data } = useApi<Listed<Asset, { base_currency: string; total: number }>>('/assets')
  const rows = useMemo(() => data?.data ?? [], [data])
  const [edit, setEdit] = useState<Asset | null | undefined>(undefined)

  const detail = (a: Asset) => (a.grams !== null ? `${n(a.grams, 2)} ${t('grams')}${a.karat ? ` · ${a.karat}K` : ''}` : a.location ?? '—')
  const value = (a: Asset) => <span className="num">{n(a.value)}{a.value_currency !== data?.meta.base_currency ? ` ${a.value_currency}` : ''}</span>

  return (
    <div className="list">
      <ListHead>
        <ModeToggle />
        <span className="count">{t('total')}: <span className="num">{n(data?.meta.total ?? 0)}</span> {data?.meta.base_currency}</span>
        <span className="grow" />
        {!readOnly && <button type="button" className="btn" onClick={() => setEdit(null)}><Icon name="plus" /><span className="hide-sm">{t('add')}</span></button>}
      </ListHead>
      <Paged
        items={rows}
        resetKey={mode}
        empty={<div className="empty"><Icon name="assets" /><p>{t('empty')}</p></div>}
        container={(c) => mode === 'table' ? (
          <div className="tbl"><table>
            <thead><tr><th>{t('name')}</th><th>{t('type')}</th><th>{t('details')}</th><th>{t('purchasePrice')}</th><th>{t('valuation')}</th><th>{t('location')}</th></tr></thead>
            <tbody>{c}</tbody>
          </table></div>
        ) : <div className="grid">{c}</div>}
        renderItem={(a) => mode === 'table' ? (
          <tr key={a.id} onClick={() => setEdit(a)}>
            <td><button type="button" className="row-link" onClick={() => setEdit(a)}>{a.name}</button></td>
            <td>{i.e(a.type)}</td>
            <td>{a.grams !== null ? detail(a) : '—'}</td>
            <td className="num">{a.purchase_price !== null ? n(a.purchase_price) : '—'}</td>
            <td>{value(a)}</td>
            <td>{a.location ?? '—'}</td>
          </tr>
        ) : (
          <button type="button" key={a.id} className="card ic" onClick={() => setEdit(a)}>
            <div className="t"><span>{a.name}</span><span className="tag quiet">{i.e(a.type)}</span></div>
            <div className="amt">{value(a)}</div>
            <div className="meta"><span>{detail(a)}</span><span title={t('autoValued')} aria-label={a.auto_valued ? t('autoValued') : undefined}>{a.auto_valued ? '⟳' : ''}</span></div>
          </button>
        )}
      />
      {edit !== undefined && <AssetForm asset={edit} onClose={() => setEdit(undefined)} />}
    </div>
  )
}

function AssetForm({ asset, onClose }: { asset: Asset | null; onClose: () => void }) {
  const i = useI18n()
  const { t } = i
  const { readOnly } = useSession()
  const s = (v: number | null | undefined) => (v === null || v === undefined ? '' : String(v))
  const form = useForm({
    name: asset?.name ?? '', type: asset?.type ?? 'gold', currency: asset?.currency ?? 'EGP', purchase_price: s(asset?.purchase_price),
    purchase_date: asset?.purchase_date ?? '', current_value: s(asset?.current_value), grams: s(asset?.grams), karat: s(asset?.karat ?? 21),
    location: asset?.location ?? '', notes: asset?.notes ?? '',
  }, onClose)
  const f = form.f
  const metal = f.type === 'gold' || f.type === 'silver'
  const submit = () => form.run(() => {
    const body = {
      name: f.name, type: f.type, currency: f.currency, purchase_price: num(f.purchase_price), purchase_date: orNull(f.purchase_date),
      current_value: metal ? null : num(f.current_value), grams: metal ? num(f.grams) : null, karat: f.type === 'gold' ? num(f.karat) : null,
      location: orNull(f.location), notes: orNull(f.notes),
    }
    return asset ? patch(`/assets/${asset.id}`, body) : post('/assets', body)
  })
  return (
    <FormModal title={asset ? t('editAsset') : t('newAsset')} errors={form.errors} busy={form.busy || readOnly} onClose={onClose} onSubmit={submit}
      extra={asset && !readOnly ? <DeleteButton busy={form.busy} onConfirm={() => form.run(() => del(`/assets/${asset.id}`))} /> : undefined}
      fields={[
        text(form, 'name', t('name'), { maxLength: 120 }),
        select(form, 'type', t('type'), ASSET_TYPES.map((k) => [k, i.e(k)])),
        money(form, 'grams', t('grams'), { hidden: !metal }),
        select(form, 'karat', t('karat'), KARATS.map((k) => [k, `${k}K`]), { hidden: f.type !== 'gold' }),
        money(form, 'current_value', t('valuation'), { hidden: metal }),
        currency(form, 'currency', t('currency')),
        money(form, 'purchase_price', t('purchasePrice')),
        date(form, 'purchase_date', t('purchaseDate')),
        text(form, 'location', t('location'), { maxLength: 255 }),
        text(form, 'notes', t('notes'), { full: true, maxLength: 2000 }),
      ]} />
  )
}
