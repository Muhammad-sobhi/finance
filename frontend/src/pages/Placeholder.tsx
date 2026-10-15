import { useI18n, type Key } from '../i18n'
import { Icon } from '../components/Icon'

/** Sections built in later steps of the build order. */
export function Placeholder({ k }: { k: Key }) {
  const { t } = useI18n()
  return (
    <div className="empty">
      <Icon name={k} />
      <p>{t('soon')}</p>
    </div>
  )
}
