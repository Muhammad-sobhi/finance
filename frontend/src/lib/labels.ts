import type { I18n } from '../i18n'
import type { Category } from './types'

/** Seeded categories are translated by key; user-named ones show their own name. */
export const catName = (i: I18n, c: Category | undefined | null) => (c ? (c.key ? i.e(c.key) : c.name) : i.t('noCategory'))
