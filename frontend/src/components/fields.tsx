import type { Field } from './Modal'

/** Small builders for FormModal fields so every form wires id/label/value the same way. */
export interface Form {
  f: Record<string, unknown>
  set(k: string): (e: { target: { value: string } }) => void
  put(k: string, v: unknown): void
}

interface Opts { full?: boolean; hidden?: boolean; type?: string; inputMode?: 'decimal' | 'numeric' | 'text'; dir?: 'ltr'; placeholder?: string; maxLength?: number; num?: boolean }

export function text(form: Form, name: string, label: string, o: Opts = {}): Field {
  return {
    name, label, full: o.full, hidden: o.hidden,
    control: (
      <input id={`f-${name}`} className={`inp${o.num ? ' num' : ''}`} type={o.type ?? 'text'} inputMode={o.inputMode} dir={o.dir}
        placeholder={o.placeholder} maxLength={o.maxLength} value={String(form.f[name] ?? '')} onChange={form.set(name)} />
    ),
  }
}

export const money = (form: Form, name: string, label: string, o: Opts = {}) => text(form, name, label, { inputMode: 'decimal', num: true, ...o })
export const date = (form: Form, name: string, label: string, o: Opts = {}) => text(form, name, label, { type: 'date', ...o })

export function select(form: Form, name: string, label: string, options: [string | number, string][], o: Opts & { empty?: string } = {}): Field {
  return {
    name, label, full: o.full, hidden: o.hidden,
    control: (
      <select id={`f-${name}`} className="inp" value={String(form.f[name] ?? '')} onChange={form.set(name)}>
        {o.empty !== undefined && <option value="">{o.empty}</option>}
        {options.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
      </select>
    ),
  }
}

export function check(form: Form, name: string, label: string, text: string, o: Opts = {}): Field {
  return {
    name, label, full: o.full, hidden: o.hidden,
    control: (
      <label className="check" htmlFor={`f-${name}`}>
        <input id={`f-${name}`} type="checkbox" checked={!!form.f[name]}
          onChange={(e) => form.put(name, e.target.checked)} />
        {text}
      </label>
    ),
  }
}

export const CURRENCIES = ['EGP', 'USD', 'EUR', 'SAR', 'AED', 'KWD', 'GBP']

export function currency(form: Form, name = 'currency', label = 'Currency', o: Opts = {}): Field {
  return {
    name, label, full: o.full, hidden: o.hidden,
    control: (
      <>
        <input id={`f-${name}`} className="inp" list="currency-list" maxLength={3} dir="ltr" value={String(form.f[name] ?? '')}
          onChange={(e) => form.put(name, e.target.value.toUpperCase())} />
        <datalist id="currency-list">{CURRENCIES.map((c) => <option key={c} value={c} />)}</datalist>
      </>
    ),
  }
}
