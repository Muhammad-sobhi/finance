import { useEffect, useLayoutEffect, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { measureLines, observeSize, packPages } from '../lib/fit'
import { useI18n } from '../i18n'
import { Icon } from './Icon'

export interface Field {
  name: string
  label: string
  control: ReactNode
  full?: boolean
  hidden?: boolean
}

interface Props {
  title: string
  fields: Field[]
  errors?: Record<string, string>
  busy?: boolean
  onClose: () => void
  onSubmit: () => void
  /** Extra footer actions (e.g. delete) shown at the start of the footer. */
  extra?: ReactNode
  submitLabel?: string
}

/**
 * A form dialog that never scrolls. All fields render once; if they don't fit the
 * available height the form becomes "Step 1 of N", packing whole rows per step.
 */
export function FormModal({ title, fields, errors = {}, busy, onClose, onSubmit, extra, submitLabel }: Props) {
  const { t } = useI18n()
  const bodyRef = useRef<HTMLDivElement>(null)
  const overlayRef = useRef<HTMLDivElement>(null)
  const [steps, setSteps] = useState<[number, number][] | null>(null)
  const [step, setStep] = useState(0)
  const [tick, setTick] = useState(0)
  const visible = fields.filter((f) => !f.hidden)
  const shape = visible.map((f) => f.name + (errors[f.name] ? '!' : '')).join('|')

  useLayoutEffect(() => { setSteps(null) }, [shape, tick])

  useLayoutEffect(() => {
    const body = bodyRef.current
    if (steps !== null || !body) return
    const lines = measureLines(body)
    const cs = getComputedStyle(body)
    const padTop = parseFloat(cs.paddingTop)
    const padBottom = parseFloat(cs.paddingBottom)
    const packed = lines.length ? packPages(lines, body.clientHeight, padTop, padBottom) : [[0, 0] as [number, number]]
    setSteps(packed)
    // Jump to the step holding the first error, if any.
    const firstErr = visible.findIndex((f) => errors[f.name])
    if (firstErr >= 0) setStep(Math.max(0, packed.findIndex(([a, b]) => firstErr >= a && firstErr < b)))
    else setStep((s) => Math.min(s, packed.length - 1))
  }, [steps, visible, errors])

  useEffect(() => {
    const el = overlayRef.current
    return el ? observeSize(el, () => setTick((n) => n + 1)) : undefined
  }, [])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  const count = steps?.length ?? 1
  const [from, to] = steps ? steps[Math.min(step, count - 1)] : [0, visible.length]
  const last = step >= count - 1

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (!last) setStep(step + 1)
    else onSubmit()
  }

  return (
    <div className="overlay" ref={overlayRef} onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <form className="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title" onSubmit={submit} noValidate>
        <div className="modal-h">
          <h2 id="modal-title">{title}</h2>
          <button type="button" className="icon-btn" onClick={onClose} aria-label={t('close')}><Icon name="close" /></button>
        </div>
        <div className={`modal-b${steps === null ? ' measuring' : ''}`} ref={bodyRef}>
          {visible.slice(from, to).map((f) => (
            <div key={f.name} className={`field${f.full ? ' full' : ''}`} data-fi="">
              <label htmlFor={`f-${f.name}`}>{f.label}</label>
              {f.control}
              {errors[f.name] && <span className="err" role="alert">{errors[f.name]}</span>}
            </div>
          ))}
        </div>
        <div className="modal-f">
          {extra}
          <span className="grow steps">{count > 1 ? t('step', { a: step + 1, b: count }) : ''}</span>
          {step > 0 && <button type="button" className="btn ghost" onClick={() => setStep(step - 1)}>{t('back')}</button>}
          <button type="submit" className="btn" disabled={busy}>{last ? (submitLabel ?? t('save')) : t('next')}</button>
        </div>
      </form>
    </div>
  )
}

/** Two-tap delete: first tap arms, second confirms. */
export function DeleteButton({ onConfirm, busy }: { onConfirm: () => void; busy?: boolean }) {
  const { t } = useI18n()
  const [armed, setArmed] = useState(false)
  useEffect(() => {
    if (!armed) return
    const id = setTimeout(() => setArmed(false), 4000)
    return () => clearTimeout(id)
  }, [armed])
  return (
    <button type="button" className="btn danger" disabled={busy} onClick={() => (armed ? onConfirm() : setArmed(true))}>
      {armed ? t('confirmDelete') : t('delete')}
    </button>
  )
}
