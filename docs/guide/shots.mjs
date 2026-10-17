// Takes the Arabic screenshots used by the user guide (docs/guide/build.py).
// Needs the API with demo data and `vite preview` running (see README → Checks).
// Usage: node docs/guide/shots.mjs   (BASE_URL, EMAIL, PASSWORD, CHROMIUM as in verify-noscroll)
import { chromium } from '/opt/node-tools/node_modules/playwright/index.mjs'
import { mkdirSync } from 'node:fs'

const BASE = process.env.BASE_URL ?? 'http://localhost:4173'
const OUT = new URL('./shots/', import.meta.url).pathname
mkdirSync(OUT, { recursive: true })

const res = await fetch(`${BASE}/api/v1/auth/login`, {
  method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ email: process.env.EMAIL ?? 'demo@example.com', password: process.env.PASSWORD ?? 'password', device_name: 'guide' }),
})
if (!res.ok) throw new Error(`login failed: ${res.status}`)
const { token } = await res.json()
await fetch(`${BASE}/api/v1/auth/me`, { method: 'PATCH', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ theme: 'ledger', locale: 'ar' }) })

const D = [1440, 900], P = [390, 780]
const DEBTS_TAB = n => `.debts-page > .list-head .seg button:nth-child(${n})`
const PLAN_TAB = n => `.planning > .list-head .seg button:nth-child(${n})`
const INC_TAB = n => `.income-page > .list-head .seg button:nth-child(${n})`
// [name, viewport, route, clicks, signed-in]
const SHOTS = [
  ['login', P, '/', [], false],
  ['heir-request', P, '/heir-request', [], false],
  ['dashboard', D, '/', []],
  ['dashboard-phone', P, '/', []],
  ['more-sheet', P, '/', ['.nav .mob']],
  ['quick-add', P, '/', ['.fab']],
  ['theme-pop', D, '/', ['.swatch-btn']],
  ['transactions', D, '/transactions', []],
  ['transaction-form', D, '/transactions', ['.list-head .btn']],
  ['transfer-form', D, '/transactions', ['.list-head .btn', '#f-type button:nth-child(3)']],
  ['accounts', D, '/accounts', []],
  ['account-form', D, '/accounts', ['.list-head .btn']],
  ['categories', D, '/categories', []],
  ['income', D, '/income', []],
  ['income-recurring', D, '/income', [INC_TAB(2)]],
  ['income-projects', D, '/income', [INC_TAB(3)]],
  ['debts', D, '/debts', []],
  ['debt-detail', D, '/debts', ['.list-body [data-fi]']],
  ['installments', D, '/debts', [DEBTS_TAB(2)]],
  ['gameyas', D, '/debts', [DEBTS_TAB(3)]],
  ['bills', D, '/bills', []],
  ['bill-pay', D, '/bills', ['.list-body .btn.sm']],
  ['subscriptions', D, '/subscriptions', []],
  ['investments', D, '/investments', []],
  ['investment-detail', D, '/investments', ['.list-body [data-fi]']],
  ['assets', D, '/assets', []],
  ['budgets', D, '/planning', []],
  ['goals', D, '/planning', [PLAN_TAB(2)]],
  ['zakat', D, '/planning', [PLAN_TAB(3)]],
  ['reports', D, '/reports', []],
  ['notifications', D, '/notifications', []],
  ['vault', D, '/vault', []],
  ['heir', D, '/heir', []],
  ['settings', D, '/settings', []],
  ['settings-data', D, '/settings', ['.show-sm .seg button:nth-child(3)']],
  ['import-form', D, '/settings', ['.show-sm .seg button:nth-child(3)', '.data-acts .btn:nth-child(2)']],
  ['transactions-phone', P, '/transactions', []],
]

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM ?? '/opt/pw-browsers/chromium' })
for (const [name, [w, h], route, clicks, signedIn = true] of SHOTS) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2, hasTouch: w <= 720, locale: 'ar-EG' })
  await ctx.addInitScript(([t, s]) => {
    if (s) localStorage.setItem('fin.token', t)
    localStorage.setItem('fin.theme', 'ledger'); localStorage.setItem('fin.lang', 'ar')
  }, [token, signedIn])
  const page = await ctx.newPage()
  try {
    await page.goto(BASE + route)
    await page.waitForSelector('.view, .auth', { timeout: 10000 })
    await page.waitForLoadState('networkidle')
    for (const sel of clicks) {
      const el = page.locator(sel).first()
      if (await el.isVisible()) await el.click()
      await page.waitForLoadState('networkidle')
      await page.waitForTimeout(250)
    }
    await page.evaluate(() => document.fonts.ready)
    await page.waitForTimeout(400)
    await page.screenshot({ path: `${OUT}${name}.jpg`, type: 'jpeg', quality: 82 })
    console.log('ok', name)
  } catch (e) { console.log('FAIL', name, e.message.split('\n')[0]) }
  await ctx.close()
}
await browser.close()
