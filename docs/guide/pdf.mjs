// Prints guide-ar.html to the PDF (run build.py first).
import { chromium } from '/opt/node-tools/node_modules/playwright/index.mjs'
const dir = new URL('.', import.meta.url).pathname
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM ?? '/opt/pw-browsers/chromium' })
const page = await browser.newPage()
await page.goto(`file://${dir}guide-ar.html`, { waitUntil: 'networkidle' })
await page.evaluate(() => document.fonts.ready)
await page.pdf({ path: `${dir}دليل-دينار.pdf`, format: 'A4', printBackground: true, preferCSSPageSize: true,
  displayHeaderFooter: true, headerTemplate: '<span></span>',
  footerTemplate: '<div style="width:100%;text-align:center;font-size:8pt;color:#888;font-family:sans-serif"><span class="pageNumber"></span> / <span class="totalPages"></span></div>' })
await browser.close()
