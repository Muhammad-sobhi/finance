import { useState, type InputHTMLAttributes } from 'react'
import { useI18n } from '../i18n'
import { Icon } from './Icon'

/** Password field with a show/hide toggle. Typed left-to-right, so the toggle always sits on the right. */
export function PasswordInput(props: Omit<InputHTMLAttributes<HTMLInputElement>, 'type'>) {
  const { t } = useI18n()
  const [shown, setShown] = useState(false)
  const label = t(shown ? 'hidePassword' : 'showPassword')
  return (
    <div className="pw-field" dir="ltr">
      <input {...props} className={`inp ${props.className ?? ''}`.trim()} type={shown ? 'text' : 'password'} dir="ltr" />
      <button type="button" className="icon-btn sm pw-toggle" aria-label={label} title={label} aria-pressed={shown}
        aria-controls={props.id} onClick={() => setShown((s) => !s)}>
        <Icon name={shown ? 'eyeOff' : 'eye'} />
      </button>
    </div>
  )
}
