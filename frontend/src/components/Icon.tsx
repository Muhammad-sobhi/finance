/** Line icons (24×24, stroke = currentColor). Nav icons follow the prototype. */
const PATHS: Record<string, string> = {
  home: '<path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
  accounts: '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18M7 15h4"/>',
  debts: '<path d="M7 7h11l-3-3M17 17H6l3 3"/>',
  bills: '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
  subscriptions: '<path d="M4 12a8 8 0 0 1 14-5l2-2v6h-6l2-2a5 5 0 1 0 1 6"/>',
  investments: '<path d="M3 17l6-6 4 4 8-8M15 7h6v6"/>',
  assets: '<path d="M4 20V9l8-5 8 5v11M9 20v-6h6v6"/>',
  transactions: '<circle cx="12" cy="12" r="9"/><path d="M12 7v10M9 9.5c0-1.2 1.3-2 3-2s3 .8 3 2-1.3 1.7-3 2-3 .8-3 2 1.3 2 3 2 3-.8 3-2"/>',
  reports: '<path d="M5 20V10M12 20V4M19 20v-7"/>',
  heir: '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/>',
  categories: '<path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4z"/><circle cx="16.5" cy="16.5" r="3.5"/>',
  settings: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
  bell: '<path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4zM10 20a2 2 0 0 0 4 0"/>',
  notifications: '<path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4zM10 20a2 2 0 0 0 4 0"/>',
  income: '<path d="M12 20V8M7 13l5-5 5 5"/><path d="M4 4h16"/>',
  incomeNav: '<path d="M12 20V8M7 13l5-5 5 5"/><path d="M4 4h16"/>',
  planning: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
  more: '<circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/>',
  next: '<path d="M9 6l6 6-6 6"/>',
  prev: '<path d="M15 6l-6 6 6 6"/>',
  close: '<path d="M6 6l12 12M18 6L6 18"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  logout: '<path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h11"/>',
  filter: '<path d="M4 5h16l-6 8v6l-4-2v-4z"/>',
  empty: '<rect x="4" y="5" width="16" height="14" rx="2"/><path d="M4 13h5l1 2h4l1-2h5"/>',
  // category icons
  food: '<path d="M7 3v8M5 3v5a2 2 0 0 0 4 0V3M7 11v10M17 3c-2 0-3 3-3 6s1 4 3 4v8"/>',
  car: '<path d="M5 16V11l2-5h10l2 5v5M3 16h18v3H3zM7 11h10"/><circle cx="7.5" cy="16" r="1"/><circle cx="16.5" cy="16" r="1"/>',
  bill: '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
  bag: '<path d="M5 8h14l-1 13H6zM9 8V6a3 3 0 0 1 6 0v2"/>',
  health: '<path d="M12 20s-8-5-8-11a4 4 0 0 1 8-1 4 4 0 0 1 8 1c0 6-8 11-8 11z"/><path d="M12 9v5M9.5 11.5h5"/>',
  book: '<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2zM4 21V5M8 7h7"/>',
  film: '<rect x="3" y="5" width="18" height="14" rx="1"/><path d="M7 5v14M17 5v14M3 9h4M3 15h4M17 9h4M17 15h4"/>',
  family: '<circle cx="8" cy="7" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M2 20c0-4 3-6 6-6s6 2 6 6M14 20c0-3 1.5-5 3-5s4 2 4 5"/>',
  work: '<rect x="3" y="7" width="18" height="13" rx="1"/><path d="M9 7V4h6v3M3 12h18"/>',
  tag: '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.5"/>',
  wallet: '<path d="M4 6h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4zM4 6l11-3v3M16 13h4"/>',
  gift: '<rect x="3" y="9" width="18" height="12"/><path d="M3 13h18M12 9v12M12 9c-1-4-6-5-6-2s6 2 6 2c1-4 6-5 6-2s-6 2-6 2"/>',
  phone: '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>',
  plane: '<path d="M2 14l20-8-6 15-4-6-6 3 1-5z"/>',
  coffee: '<path d="M4 9h13v5a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5zM17 10h2a2 2 0 0 1 0 4h-2M8 3v3M12 3v3"/>',
  house: '<path d="M3 11l9-7 9 7M5 9v11h14V9"/>',
  bolt: '<path d="M13 2L4 14h7l-1 8 9-12h-7z"/>',
  paw: '<circle cx="6" cy="10" r="2"/><circle cx="10" cy="6" r="2"/><circle cx="14" cy="6" r="2"/><circle cx="18" cy="10" r="2"/><path d="M8 18c0-3 2-5 4-5s4 2 4 5-2 3-4 3-4 0-4-3z"/>',
  sport: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/>',
}

export const CATEGORY_ICONS = ['food', 'car', 'bill', 'bag', 'health', 'book', 'film', 'family', 'work', 'tag', 'wallet', 'gift', 'phone', 'plane', 'coffee', 'house', 'bolt', 'paw', 'sport']

export function Icon({ name, className }: { name: string; className?: string }) {
  return (
    <svg viewBox="0 0 24 24" className={className} aria-hidden="true" focusable="false"
      dangerouslySetInnerHTML={{ __html: PATHS[name] ?? PATHS.tag }} />
  )
}
