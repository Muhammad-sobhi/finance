// No-scroll verification (CLAUDE.md quality bar).
// For every page × viewport × theme/lang: the document must not exceed the viewport and
// no element may have scrollHeight > clientHeight. Also opens the dialogs.
//
// Usage: API + `npm run preview` running, then (add HEIR_EMAIL/HEIR_PASSWORD to also check the read-only heir view)
//   BASE_URL=http://localhost:4173 EMAIL=demo@example.com PASSWORD=password npm run verify:noscroll
// Set SHOTS=dir to save screenshots, ONLY=/reports,quick-add to check just those views. Set CHROMIUM=/path/to/chrome if Playwright's browser isn't installed.
import { chromium } from 'playwright-core'
import { mkdirSync } from 'node:fs'

const BASE = process.env.BASE_URL ?? 'http://localhost:4173'
const EMAIL = process.env.EMAIL ?? 'demo@example.com'
const PASSWORD = process.env.PASSWORD ?? 'password'
const SHOTS = process.env.SHOTS
const VIEWPORTS = [[390, 780], [900, 700], [1440, 900]]
const ROUTES = ['/', '/notifications', '/reports', '/income', '/planning', '/admin', '/accounts', '/transactions', '/categories', '/debts', '/bills', '/subscriptions', '/investments', '/assets', '/vault', '/heir', '/settings',
  '/heir-request', '/forgot-password', '/reset-password?token=x&email=a%40b.c', '/link?to=%2Fapi%2Fv1%2Flinks%2Fcheck-in%2F1%3Fexpires%3D1%26signature%3Dx']
const COMBOS = [['ledger', 'ar'], ['swiss', 'ar'], ['geo', 'ar'], ['vault', 'ar'], ['ledger', 'en'], ['vault', 'en']]
// Dialogs: [route, selector(s) to click, label]
const DIALOGS = [
  ['/', '.fab', 'quick-add'],
  ['/accounts', '.list-head .btn', 'account-form'],
  ['/transactions', '.list-head .btn', 'transaction-form'],
  ['/categories', '.list-head .btn', 'category-form'],
  ['/debts', '.list-head .btn', 'debt-form'],
  ['/debts', '.list-body [data-fi]', 'debt-detail'],
  ['/bills', '.list-head .btn', 'bill-form'],
  ['/bills', '.list-body .btn.sm', 'bill-pay'],
  ['/subscriptions', '.list-head .btn', 'subscription-form'],
  ['/investments', '.list-head .btn', 'investment-form'],
  ['/investments', '.list-body [data-fi]', 'investment-detail'],
  ['/assets', '.list-head .btn', 'asset-form'],
  ['/settings', ['.show-sm .seg button:nth-child(2)', '.split .actions .btn[type=button]'], 'rate-form'],
  ['/heir', ['.show-sm .seg button:nth-child(2)', '.split .card:last-child .actions .btn'], 'heir-form'],
  ['/settings', ['.acct-actions .btn:nth-child(1)'], 'profile-form'],
  ['/settings', ['.acct-actions .btn:nth-child(2)'], 'password-form'],
  ['/income', ['.income-page > .list-head .seg button:nth-child(2)'], 'income-recurring'],
  ['/income', ['.income-page > .list-head .seg button:nth-child(2)', '.tab-body .list-head .btn'], 'income-form'],
  ['/income', ['.income-page > .list-head .seg button:nth-child(3)'], 'income-projects'],
  ['/income', ['.income-page > .list-head .seg button:nth-child(3)', '.tab-body .list-head .btn'], 'project-form'],
  ['/income', ['.income-page > .list-head .seg button:nth-child(3)', '.tab-body .list-body [data-fi]'], 'project-detail'],
  ['/planning', ['.tab-body .list-head .btn'], 'budget-form'],
  ['/planning', ['.tab-body .list-body [data-fi]'], 'budget-edit'],
  ['/planning', ['.planning > .list-head .seg button:nth-child(2)'], 'planning-goals'],
  ['/planning', ['.planning > .list-head .seg button:nth-child(2)', '.tab-body .list-head .btn'], 'goal-form'],
  ['/planning', ['.planning > .list-head .seg button:nth-child(2)', '.tab-body .list-body [data-fi]'], 'goal-detail'],
  ['/planning', ['.planning > .list-head .seg button:nth-child(3)'], 'planning-zakat'],
  ['/planning', ['.planning > .list-head .seg button:nth-child(3)', '.z-due .acts .btn:not(.ghost)'], 'zakat-pay'],
  ['/planning', ['.planning > .list-head .seg button:nth-child(3)', '.z-due .acts .btn.ghost'], 'zakat-settings'],
  ['/planning', ['.planning > .list-head .seg button:nth-child(3)', '.z-parts h3 .more'], 'zakat-history'],
  ['/settings', ['.show-sm .seg button:nth-child(3)'], 'settings-data'],
  ['/admin', ['.list-head .btn'], 'invite-form'],
  ['/', ['.swatch-btn'], 'theme-pop'],
  ['/', ['.nav .mob', '.sheet button:has(svg) >> text=/تخصيص|Customize/'], 'bar-picker'],
  ['/settings', ['.show-sm .seg button:nth-child(3)', '.data-acts .btn:nth-child(3)'], 'backup-form'],
  ['/settings', ['.show-sm .seg button:nth-child(3)', '.data-acts .btn:nth-child(2)'], 'import-form'],
  ['/transactions', ['.list-body .row-link, .list-body .card.tx'], 'transaction-edit-form'],
  ['/transactions', ['.list-head .btn', '#f-type button:nth-child(3)'], 'transfer-form'],
  ['/debts', ['.debts-page > .list-head .seg button:nth-child(2)'], 'installments'],
  ['/debts', ['.debts-page > .list-head .seg button:nth-child(2)', '.tab-body .list-head .btn'], 'plan-form'],
  ['/debts', ['.debts-page > .list-head .seg button:nth-child(2)', '.tab-body .list-body [data-fi]'], 'plan-detail'],
  ['/debts', ['.debts-page > .list-head .seg button:nth-child(2)', '.tab-body .list-body [data-fi]', '.modal-f .btn:not(.ghost)'], 'plan-pay'],
  ['/debts', ['.debts-page > .list-head .seg button:nth-child(3)'], 'gameyas'],
  ['/debts', ['.debts-page > .list-head .seg button:nth-child(3)', '.tab-body .list-head .btn'], 'gameya-form'],
  ['/debts', ['.debts-page > .list-head .seg button:nth-child(3)', '.tab-body .list-body [data-fi]'], 'gameya-detail'],
  ['/debts', ['.debts-page > .list-head .seg button:nth-child(3)', '.tab-body .list-body [data-fi]', '.modal-f .btn:not(.ghost)'], 'gameya-pay'],
]

if (SHOTS) mkdirSync(SHOTS, { recursive: true })

const res = await fetch(`${BASE}/api/v1/auth/login`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ email: EMAIL, password: PASSWORD, device_name: 'noscroll-check' }),
})
if (!res.ok) throw new Error(`login failed: ${res.status}`)
const { token } = await res.json()

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM })
const failures = []

const check = (page) => page.evaluate(() => {
  const out = []
  const de = document.documentElement
  if (de.scrollHeight > innerHeight) out.push(`document height ${de.scrollHeight} > ${innerHeight}`)
  if (de.scrollWidth > innerWidth) out.push(`document width ${de.scrollWidth} > ${innerWidth}`)
  for (const el of document.querySelectorAll('body *')) {
    if (el.scrollHeight > el.clientHeight && el.clientHeight > 0) {
      const id = el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).join('.') : '')
      out.push(`${id} scrollHeight ${el.scrollHeight} > clientHeight ${el.clientHeight}`)
    }
  }
  return out
})

for (const [w, h] of VIEWPORTS) {
  for (const [theme, lang] of COMBOS) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, hasTouch: w <= 720 })
    await ctx.addInitScript(([t, th, l]) => {
      localStorage.setItem('fin.token', t)
      localStorage.setItem('fin.theme', th)
      localStorage.setItem('fin.lang', l)
    }, [token, theme, lang])
    const page = await ctx.newPage()
    // Server-side prefs win at boot; set them to the combo under test.
    await fetch(`${BASE}/api/v1/auth/me`, { method: 'PATCH', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ theme, locale: lang }) })

    const visit = async (route, label, action) => {
      await page.goto(BASE + route)
      await page.waitForSelector('.view, .auth', { timeout: 10000 })
      await page.waitForLoadState('networkidle')
      if (action) {
        // A list of selectors is clicked in order; steps that aren't visible at this size are skipped.
        for (const sel of [action].flat()) {
          const el = page.locator(sel).first()
          if (await el.isVisible()) await el.click()
          // Let the next step's content (tab data, list rows) load before looking for it.
          await page.waitForLoadState('networkidle')
          await page.waitForTimeout(150)
        }
        if (label.includes('form') || label.includes('detail') || label.includes('pay') || label === 'quick-add') await page.waitForSelector('.modal', { timeout: 8000 })
        await page.waitForLoadState('networkidle')
      }
      await page.evaluate(() => document.fonts.ready)
      await page.waitForTimeout(150)
      const problems = await check(page)
      const name = `${w}x${h} ${theme}/${lang} ${label}`
      if (problems.length) failures.push(`${name}\n    ${problems.join('\n    ')}`)
      if (SHOTS && (theme === 'ledger' || label === '/')) await page.screenshot({ path: `${SHOTS}/${w}x${h}-${theme}-${lang}-${label.replace(/\W+/g, '_') || 'home'}.png` })
    }

    const safe = async (r, label, sel) => {
      try { await visit(r, label, sel) } catch (e) { failures.push(`${w}x${h} ${theme}/${lang} ${label}: ${e.message.split('\n')[0]}`) }
    }
    // ONLY=/reports,quick-add limits the run to those routes/dialog labels.
    const only = process.env.ONLY?.split(',')
    for (const r of ROUTES) if (!only || only.includes(r)) await safe(r, r)
    for (const [r, sel, label] of DIALOGS) if (!only || only.includes(label)) await safe(r, label, sel)
    await ctx.close()
  }
}
// Optional: the read-only heir view (HEIR_EMAIL / HEIR_PASSWORD of an activated heir).
if (process.env.HEIR_EMAIL) {
  const r = await fetch(`${BASE}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ email: process.env.HEIR_EMAIL, password: process.env.HEIR_PASSWORD }) })
  if (!r.ok) throw new Error(`heir login failed: ${r.status}`)
  const heirToken = (await r.json()).token
  for (const [w, h] of VIEWPORTS) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h } })
    await ctx.addInitScript((t) => localStorage.setItem('fin.token', t), heirToken)
    const page = await ctx.newPage()
    for (const route of ROUTES.filter((r) => !['/heir-request', '/link', '/forgot-password', '/reset-password'].some((p) => r.startsWith(p)))) {
      await page.goto(BASE + route)
      await page.waitForSelector('.readonly-bar', { timeout: 10000 })
      await page.waitForLoadState('networkidle')
      await page.evaluate(() => document.fonts.ready)
      if (await page.$('.fab')) failures.push(`${w}x${h} heir ${route}: quick-add button visible for read-only heir`)
      const problems = await check(page)
      if (problems.length) failures.push(`${w}x${h} heir ${route}\n    ${problems.join('\n    ')}`)
      if (SHOTS && route === '/') await page.screenshot({ path: `${SHOTS}/${w}x${h}-heir-home.png` })
    }
    await ctx.close()
  }
}

await browser.close()

if (failures.length) {
  console.error(`✗ ${failures.length} scroll violation(s):\n  ${failures.join('\n  ')}`)
  process.exit(1)
}
const views = process.env.ONLY ? `${process.env.ONLY.split(',').length} selected` : ROUTES.length + DIALOGS.length
console.log(`✓ no scrolling: ${views} views × ${VIEWPORTS.length} viewports × ${COMBOS.length} theme/lang combos`
  + (process.env.HEIR_EMAIL ? ` + read-only heir view (${ROUTES.filter((r) => !['/heir-request', '/link', '/forgot-password', '/reset-password'].some((p) => r.startsWith(p))).length} pages × ${VIEWPORTS.length} viewports)` : ''))
