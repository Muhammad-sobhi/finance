import { useI18n } from '../i18n'

/** "in N days" / "Today" / "N days late" tag. */
export function DueTag({ days, off }: { days: number | null | undefined; off?: boolean }) {
  const { t } = useI18n()
  if (off) return <span className="tag quiet">{t('inactiveItem')}</span>
  if (days === null || days === undefined) return <span className="tag quiet">—</span>
  if (days < 0) return <span className="tag late">{t('lateDays', { n: -days })}</span>
  if (days === 0) return <span className="tag">{t('today')}</span>
  return <span className={`tag${days > 7 ? ' quiet' : ''}`}>{t('inDays', { n: days })}</span>
}
