import type { I18n } from '../i18n'

/** Human label for a due item, translated at render time. The name is bidi-isolated. */
export function dueSubject(i: I18n, kind: string, rawName: string): string {
  const name = `⁨${rawName}⁩`
  switch (kind) {
    case 'credit_card': return i.t('cardDue', { name })
    case 'owed_to_me': return i.t('debtFrom', { name })
    case 'i_owe': return i.t('debtTo', { name })
    case 'maturity': return i.t('matures', { name })
    case 'subscription': return i.t('subDue', { name })
    case 'income': return i.t('incomeDue', { name })
    case 'project': return i.t('projectDue', { name })
    case 'zakat': return i.t('zakatDaySub')
    case 'installment': return i.t('installmentDue', { name })
    case 'gameya': return i.t('gameyaDue', { name })
    case 'gameya_payout': return i.t('gameyaTurn', { name })
    case 'vault': return i.t('vaultDue')
    case 'gameya_shared': return i.t('gameyaDue', { name })
    case 'gameya_shared_payout': return i.t('gameyaTurn', { name })
    default: return name
  }
}

export const DUE_ROUTE: Record<string, string> = {
  bill: '/bills', income: '/income', project: '/income', subscription: '/subscriptions', owed_to_me: '/debts', i_owe: '/debts', maturity: '/investments', credit_card: '/accounts', zakat: '/planning',
  installment: '/debts', gameya: '/debts', gameya_payout: '/debts', vault: '/vault', gameya_shared: '/debts?tab=gameyas', gameya_shared_payout: '/debts?tab=gameyas',
}
