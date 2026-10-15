import { createContext, useContext } from 'react'

export type Lang = 'ar' | 'en'

const ar = {
  app: 'خزينتي',
  // nav
  home: 'الرئيسية', accounts: 'الحسابات', transactions: 'المصروفات اليومية', debts: 'الديون', bills: 'الفواتير',
  subscriptions: 'الاشتراكات', investments: 'الاستثمارات', assets: 'الممتلكات', reports: 'التقارير', people: 'الأشخاص',
  heir: 'الوريث والأمان', settings: 'الإعدادات', notifications: 'التنبيهات', more: 'المزيد', logout: 'خروج', categories: 'الفئات',
  // dashboard
  worth: 'صافي الثروة', liquid: 'السيولة', owedToMe: 'ديون لك', iOwe: 'ديون عليك', vsLast: 'عن الشهر الماضي',
  balances: 'الأرصدة', spending: 'المصروفات الشهرية', upcoming: 'تنبيهات قريبة', plusMore: 'و{n} أخرى', nothingSoon: 'لا مواعيد قريبة',
  // common
  name: 'الاسم', type: 'النوع', provider: 'الجهة', balance: 'الرصيد', currency: 'العملة', notes: 'ملاحظات', amount: 'المبلغ',
  date: 'التاريخ', category: 'الفئة', account: 'الحساب', toAccount: 'إلى حساب', note: 'ملاحظة', status: 'الحالة', person: 'الشخص',
  dueDate: 'تاريخ الاستحقاق', renewDate: 'تاريخ التجديد', startDate: 'تاريخ البدء', cycle: 'التكرار', scope: 'لمن', autoPay: 'دفع تلقائي',
  remindDays: 'نبّهني قبل (أيام)', active: 'نشط', creditLimit: 'حد الائتمان', last4: 'آخر ٤ أرقام', updated: 'آخر تحديث',
  phone: 'الهاتف', email: 'البريد', relation: 'صلة القرابة', address: 'العنوان', direction: 'الاتجاه', paid: 'المدفوع', remaining: 'المتبقي',
  inDays: 'بعد {n} يوم', today: 'اليوم', lateDays: 'متأخر {n} يوم', done: 'مكتمل',
  add: 'إضافة', edit: 'تعديل', save: 'حفظ', cancel: 'إلغاء', delete: 'حذف', confirmDelete: 'تأكيد الحذف؟', next: 'التالي', back: 'السابق',
  page: '{a} من {b}', tableV: 'جدول', cardV: 'بطاقات', step: 'خطوة {a} من {b}', loading: 'جارٍ التحميل…', empty: 'لا توجد بيانات بعد',
  saved: 'تم الحفظ', error: 'حدث خطأ', readOnly: 'وضع القراءة فقط — وصول الوريث', search: 'بحث',
  // transactions
  expense: 'مصروف', income: 'دخل', transfer: 'تحويل', quickExpense: 'مصروف سريع', addExpense: 'سجّل مصروف',
  monthTotal: 'إجمالي الشهر', dayTotal: 'إجمالي اليوم', prevMonth: 'الشهر السابق', nextMonth: 'الشهر التالي',
  // debts
  owed_to_me: 'لي عنده', i_owe: 'عليّ له', addPayment: 'تسجيل دفعة', payments: 'الدفعات', open: 'مفتوح', settled: 'مسدد',
  // recurring
  monthly: 'شهري', quarterly: 'ربع سنوي', yearly: 'سنوي', weekly: 'أسبوعي', once: 'مرة واحدة',
  personal: 'شخصي', work: 'عمل', markPaid: 'تم الدفع', fromAccount: 'الدفع من حساب', monthlyCost: 'التكلفة الشهرية',
  // investments
  invested: 'المبلغ المستثمر', currentValue: 'القيمة الحالية', quantity: 'الكمية', unit: 'الوحدة', platform: 'المنصة / الجهة',
  maturityDate: 'تاريخ الاستحقاق', returnRate: 'العائد السنوي ٪', payout: 'صرف العائد', profit: 'الربح', roi: 'العائد',
  // assets
  karat: 'العيار', purchasePrice: 'سعر الشراء', purchaseDate: 'تاريخ الشراء', location: 'المكان', valuation: 'التقييم',
  // reports
  month: 'شهر', year: 'سنة', totalIncome: 'إجمالي الدخل', totalExpense: 'إجمالي المصروف', savings: 'التوفير', savingsRate: 'نسبة التوفير',
  byCategory: 'حسب الفئة', growth: 'النمو', advice: 'نصائح', netWorthTrend: 'تطور صافي الثروة', incomeVsExpense: 'الدخل مقابل المصروف',
  growing: 'في نمو', notGrowing: 'لا يوجد نمو', noAdvice: 'لا توجد ملاحظات. استمر.',
  // heir
  heirs: 'الورثة', trusted: 'أشخاص موثوقون', inactivityDays: 'تفعيل بعد غياب (أيام)', graceDays: 'مهلة الإلغاء (أيام)',
  heirEnabled: 'تفعيل وصول الوريث', lastActivity: 'آخر نشاط لك', switchStatus: 'حالة المفتاح', armed: 'جاهز', triggered: 'مُفعّل',
  heirRequest: 'طلب وصول وريث', heirRequestHint: 'أدخل بريدك المسجل كوريث. سيُرسل طلب تأكيد لشخص موثوق.', sendRequest: 'إرسال الطلب',
  requestSent: 'إذا كان البريد مسجلًا، سيصل الطلب للشخص الموثوق.', heirLogin: 'دخول الوريث', checkIn: 'أنا بخير — إعادة ضبط المؤقت',
  pendingRequests: 'طلبات معلقة', cancelRequest: 'إلغاء الطلب',
  // settings / auth
  baseCurrency: 'العملة الأساسية', rates: 'أسعار الصرف', rate: 'السعر مقابل الأساسية', goldPrice: 'سعر جرام الذهب عيار ٢٤', silverPrice: 'سعر جرام الفضة',
  alertDays: 'التنبيه قبل الموعد (أيام)', pushOn: 'تفعيل إشعارات المتصفح', pushOk: 'الإشعارات مفعلة', pushNo: 'المتصفح لا يدعم الإشعارات',
  emailAlerts: 'تنبيهات البريد', login: 'دخول', password: 'كلمة المرور', wrongLogin: 'بيانات الدخول غير صحيحة', markAllRead: 'تحديد الكل كمقروء',
  fetchRates: 'تحديث الأسعار تلقائيًا', theme: 'المظهر', language: 'اللغة', color: 'اللون',
  // app shell / forms (added for the build)
  soon: 'هذا القسم يأتي في المرحلة التالية من البناء', thisMonth: 'هذا الشهر', filters: 'تصفية', all: 'الكل',
  allAccounts: 'كل الحسابات', allCategories: 'كل الفئات', allTypes: 'كل الأنواع', clear: 'مسح', icon: 'الأيقونة',
  dueDay: 'يوم السداد من الشهر', accountNumber: 'رقم الحساب', inactive: 'موقوف', received: 'المبلغ المستلم', net: 'الصافي',
  hasTransactions: 'لا يمكن الحذف لوجود معاملات. أوقف الحساب بدلًا من ذلك.', newAccount: 'حساب جديد', newCategory: 'فئة جديدة',
  newTransaction: 'معاملة جديدة', editTransaction: 'تعديل المعاملة', editAccount: 'تعديل الحساب', editCategory: 'تعديل الفئة',
  close: 'إغلاق', noCategory: 'بدون فئة', cardDue: 'سداد {name}', menu: 'القائمة', accountsCount: '{n} حساب',
  txCount: '{n} معاملة', limitLeft: 'المتاح {n}',
  newDebt: 'دين جديد', editDebt: 'تعديل الدين', newBill: 'فاتورة جديدة', editBill: 'تعديل الفاتورة', newSub: 'اشتراك جديد',
  editSub: 'تعديل الاشتراك', newInvestment: 'استثمار جديد', editInvestment: 'تعديل الاستثمار', newAsset: 'ممتلك جديد', editAsset: 'تعديل الممتلك',
  noAccount: 'بدون حساب (لا تحريك للمال)', moneyAccount: 'الحساب المتأثر', lastPaid: 'آخر دفع', inactiveItem: 'متوقف', addValue: 'تسجيل قيمة',
  valueHistory: 'سجل القيمة', grams: 'الجرامات', autoValued: 'محسوبة من سعر الجرام في الإعدادات', total: 'الإجمالي', general: 'عام', addRate: 'إضافة عملة',
  details: 'التفاصيل', matures: 'استحقاق {name}', debtFrom: 'دين لك على {name}', debtTo: 'دين عليك لـ {name}', defaultHint: 'فارغ = الافتراضي',
  managedByDebt: 'هذه المعاملة تابعة لدين. عدّلها من صفحة الديون.', perMonth: '/ شهر', noRemind: 'افتراضي', invalid: 'قيمة غير صحيحة',
  investmentsTotal: 'قيمة الاستثمارات', assetsTotal: 'قيمة الممتلكات',
  themes: { ledger: 'دفتر', swiss: 'سويسري', geo: 'هندسي', vault: 'خزنة' },
  months: ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'],
  enums: {
    debit_card: 'بطاقة خصم', credit_card: 'بطاقة ائتمان', instapay: 'إنستاباي', vodafone_cash: 'فودافون كاش', etisalat_cash: 'اتصالات كاش',
    orange_cash: 'أورانج كاش', we_pay: 'وي باي', cash: 'نقدي', bank: 'حساب بنكي', savings: 'توفير', other: 'أخرى',
    stocks: 'أسهم', fund: 'صندوق استثمار', certificate: 'شهادة ادخار', deposit: 'وديعة', real_estate: 'عقار', gold: 'ذهب', silver: 'فضة',
    crypto: 'عملات رقمية', business: 'مشروع', bonds: 'سندات',
    building: 'عقار / مبنى', land: 'أرض', car: 'سيارة', device: 'جهاز', jewelry: 'مجوهرات', foreign_cash: 'عملة أجنبية',
    end: 'في النهاية',
    food: 'طعام', transport: 'مواصلات', bills: 'فواتير', shopping: 'تسوق', health: 'صحة', education: 'تعليم',
    entertainment: 'ترفيه', family: 'عائلة', work: 'عمل', salary: 'راتب', freelance: 'عمل حر', other_income: 'دخل آخر',
  } as Record<string, string>,
}

const en: typeof ar = {
  app: 'Treasury',
  home: 'Home', accounts: 'Accounts', transactions: 'Daily expenses', debts: 'Debts', bills: 'Bills',
  subscriptions: 'Subscriptions', investments: 'Investments', assets: 'Assets', reports: 'Reports', people: 'People',
  heir: 'Heir & security', settings: 'Settings', notifications: 'Alerts', more: 'More', logout: 'Log out', categories: 'Categories',
  worth: 'Net worth', liquid: 'Liquid cash', owedToMe: 'Owed to you', iOwe: 'You owe', vsLast: 'vs last month',
  balances: 'Balances', spending: 'Monthly spending', upcoming: 'Upcoming', plusMore: '+{n} more', nothingSoon: 'Nothing due soon',
  name: 'Name', type: 'Type', provider: 'Provider', balance: 'Balance', currency: 'Currency', notes: 'Notes', amount: 'Amount',
  date: 'Date', category: 'Category', account: 'Account', toAccount: 'To account', note: 'Note', status: 'Status', person: 'Person',
  dueDate: 'Due date', renewDate: 'Renewal date', startDate: 'Start date', cycle: 'Repeats', scope: 'For', autoPay: 'Auto-pay',
  remindDays: 'Remind me (days before)', active: 'Active', creditLimit: 'Credit limit', last4: 'Last 4 digits', updated: 'Updated',
  phone: 'Phone', email: 'Email', relation: 'Relation', address: 'Address', direction: 'Direction', paid: 'Paid', remaining: 'Remaining',
  inDays: 'in {n} days', today: 'Today', lateDays: '{n} days late', done: 'Done',
  add: 'Add', edit: 'Edit', save: 'Save', cancel: 'Cancel', delete: 'Delete', confirmDelete: 'Confirm delete?', next: 'Next', back: 'Back',
  page: '{a} of {b}', tableV: 'Table', cardV: 'Cards', step: 'Step {a} of {b}', loading: 'Loading…', empty: 'No data yet',
  saved: 'Saved', error: 'Something went wrong', readOnly: 'Read-only — heir access', search: 'Search',
  expense: 'Expense', income: 'Income', transfer: 'Transfer', quickExpense: 'Quick expense', addExpense: 'Log expense',
  monthTotal: 'Month total', dayTotal: 'Day total', prevMonth: 'Previous month', nextMonth: 'Next month',
  owed_to_me: 'They owe me', i_owe: 'I owe them', addPayment: 'Add payment', payments: 'Payments', open: 'Open', settled: 'Settled',
  monthly: 'Monthly', quarterly: 'Quarterly', yearly: 'Yearly', weekly: 'Weekly', once: 'Once',
  personal: 'Personal', work: 'Work', markPaid: 'Mark paid', fromAccount: 'Pay from account', monthlyCost: 'Monthly cost',
  invested: 'Invested', currentValue: 'Current value', quantity: 'Quantity', unit: 'Unit', platform: 'Platform / issuer',
  maturityDate: 'Maturity date', returnRate: 'Annual return %', payout: 'Payout', profit: 'Profit', roi: 'Return',
  karat: 'Karat', purchasePrice: 'Purchase price', purchaseDate: 'Purchase date', location: 'Location', valuation: 'Valuation',
  month: 'Month', year: 'Year', totalIncome: 'Total income', totalExpense: 'Total spending', savings: 'Savings', savingsRate: 'Savings rate',
  byCategory: 'By category', growth: 'Growth', advice: 'Advice', netWorthTrend: 'Net worth trend', incomeVsExpense: 'Income vs spending',
  growing: 'Growing', notGrowing: 'Not growing', noAdvice: 'Nothing to flag. Keep going.',
  heirs: 'Heirs', trusted: 'Trusted contacts', inactivityDays: 'Activate after inactivity (days)', graceDays: 'Cancel window (days)',
  heirEnabled: 'Enable heir access', lastActivity: 'Your last activity', switchStatus: 'Switch status', armed: 'Armed', triggered: 'Triggered',
  heirRequest: 'Heir access request', heirRequestHint: 'Enter the email registered as heir. A trusted contact will be asked to confirm.', sendRequest: 'Send request',
  requestSent: 'If the email is registered, the trusted contact will receive the request.', heirLogin: 'Heir login', checkIn: "I'm fine — reset the timer",
  pendingRequests: 'Pending requests', cancelRequest: 'Cancel request',
  baseCurrency: 'Base currency', rates: 'Exchange rates', rate: 'Rate vs base', goldPrice: 'Gold price / gram (24k)', silverPrice: 'Silver price / gram',
  alertDays: 'Alert before due (days)', pushOn: 'Enable browser notifications', pushOk: 'Notifications enabled', pushNo: 'Browser does not support notifications',
  emailAlerts: 'Email alerts', login: 'Log in', password: 'Password', wrongLogin: 'Wrong email or password', markAllRead: 'Mark all read',
  fetchRates: 'Auto-update rates', theme: 'Theme', language: 'Language', color: 'Color',
  soon: 'This section arrives in the next build step', thisMonth: 'this month', filters: 'Filters', all: 'All',
  allAccounts: 'All accounts', allCategories: 'All categories', allTypes: 'All types', clear: 'Clear', icon: 'Icon',
  dueDay: 'Payment day of month', accountNumber: 'Account number', inactive: 'Inactive', received: 'Amount received', net: 'Net',
  hasTransactions: 'Cannot delete: it has transactions. Deactivate it instead.', newAccount: 'New account', newCategory: 'New category',
  newTransaction: 'New transaction', editTransaction: 'Edit transaction', editAccount: 'Edit account', editCategory: 'Edit category',
  close: 'Close', noCategory: 'No category', cardDue: '{name} payment', menu: 'Menu', accountsCount: '{n} accounts',
  txCount: '{n} transactions', limitLeft: '{n} available',
  newDebt: 'New debt', editDebt: 'Edit debt', newBill: 'New bill', editBill: 'Edit bill', newSub: 'New subscription',
  editSub: 'Edit subscription', newInvestment: 'New investment', editInvestment: 'Edit investment', newAsset: 'New asset', editAsset: 'Edit asset',
  noAccount: 'No account (no money moves)', moneyAccount: 'Account affected', lastPaid: 'Last paid', inactiveItem: 'Stopped', addValue: 'Record value',
  valueHistory: 'Value history', grams: 'Grams', autoValued: 'Valued from the gram price in settings', total: 'Total', general: 'General', addRate: 'Add currency',
  details: 'Details', matures: '{name} matures', debtFrom: '{name} owes you', debtTo: 'You owe {name}', defaultHint: 'empty = default',
  managedByDebt: 'This transaction belongs to a debt. Edit it from Debts.', perMonth: '/ month', noRemind: 'Default', invalid: 'Invalid value',
  investmentsTotal: 'Investments value', assetsTotal: 'Assets value',
  themes: { ledger: 'Ledger', swiss: 'Swiss', geo: 'Geometric', vault: 'Vault' },
  months: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
  enums: {
    debit_card: 'Debit card', credit_card: 'Credit card', instapay: 'InstaPay', vodafone_cash: 'Vodafone Cash', etisalat_cash: 'e& Cash',
    orange_cash: 'Orange Cash', we_pay: 'WE Pay', cash: 'Cash', bank: 'Bank account', savings: 'Savings', other: 'Other',
    stocks: 'Stocks', fund: 'Mutual fund', certificate: 'Savings certificate', deposit: 'Deposit', real_estate: 'Real estate', gold: 'Gold', silver: 'Silver',
    crypto: 'Crypto', business: 'Business', bonds: 'Bonds',
    building: 'Building', land: 'Land', car: 'Car', device: 'Device', jewelry: 'Jewelry', foreign_cash: 'Foreign cash',
    end: 'At maturity',
    food: 'Food', transport: 'Transport', bills: 'Bills', shopping: 'Shopping', health: 'Health', education: 'Education',
    entertainment: 'Entertainment', family: 'Family', work: 'Work', salary: 'Salary', freelance: 'Freelance', other_income: 'Other income',
  },
}

export const dict = { ar, en }
export type Dict = typeof ar
export type Key = Exclude<keyof Dict, 'themes' | 'months' | 'enums'>

export interface I18n {
  lang: Lang
  d: Dict
  t: (k: Key | string, vars?: Record<string, string | number>) => string
  num: (n: number, digits?: number) => string
  money: (n: number, cur?: string) => string
  date: (s: string | Date, opts?: Intl.DateTimeFormatOptions) => string
  e: (enumKey: string | null | undefined) => string
}

export function makeI18n(lang: Lang): I18n {
  const d = dict[lang]
  const locale = lang === 'ar' ? 'ar-EG' : 'en-US'
  const num = (n: number, digits = 0) =>
    new Intl.NumberFormat(locale, { maximumFractionDigits: digits, minimumFractionDigits: 0 }).format(n)
  const t = (k: string, vars?: Record<string, string | number>) => {
    let s = String((d as unknown as Record<string, unknown>)[k] ?? k)
    if (vars) for (const v in vars) s = s.replace(`{${v}}`, typeof vars[v] === 'number' ? num(vars[v] as number) : String(vars[v]))
    return s
  }
  return {
    lang, d, t, num,
    money: (n, cur) => `${num(n, Math.abs(n) < 100 && n % 1 ? 2 : 0)}${cur ? ' ' + cur : ''}`,
    date: (s, opts = { day: 'numeric', month: 'short', year: 'numeric' }) =>
      new Intl.DateTimeFormat(lang === 'ar' ? 'ar-EG' : 'en-GB', opts).format(typeof s === 'string' ? new Date(s + (s.length === 10 ? 'T00:00:00' : '')) : s),
    e: (k) => (k ? d.enums[k] ?? t(k) : '—'),
  }
}

export const I18nCtx = createContext<I18n>(makeI18n('ar'))
export const useI18n = () => useContext(I18nCtx)
