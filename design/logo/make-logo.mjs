// Renders the Dinar coin logo and every app icon size.
// Usage (from the repo root): CHROMIUM=/path/to/chrome node design/logo/make-logo.mjs frontend/public
import { chromium } from '../../frontend/node_modules/playwright-core/index.mjs'
import { readFileSync, writeFileSync } from 'node:fs'
const font = (f) => readFileSync(new URL('../../frontend/node_modules/@fontsource/' + f, import.meta.url)).toString('base64')
const kufi = font('reem-kufi/files/reem-kufi-arabic-700-normal.woff2')
const amiri = font('amiri/files/amiri-arabic-700-normal.woff2')

// One coin, drawn in a 512 box. bg: 'icon' (rounded green tile) | 'full' (green, no corners) | 'none'.
// Crescent opening to the upper right: outer circle r 17 minus an offset circle r 14.
const CRESCENT = 'M5.5-15.5A17 17 0 1 0 15.5 6.5A14 14 0 1 1 5.5-15.5Z'

export const coinSvg = (bg = 'icon', face = 'kufi', scale = 1) => {
  const reeds = Array.from({ length: 120 }, (_, i) => {
    const a = (i / 120) * Math.PI * 2
    const x1 = 256 + Math.cos(a) * 186, y1 = 268 + Math.sin(a) * 186
    const x2 = 256 + Math.cos(a) * 196, y2 = 268 + Math.sin(a) * 196
    return `<line x1="${x1.toFixed(1)}" y1="${y1.toFixed(1)}" x2="${x2.toFixed(1)}" y2="${y2.toFixed(1)}"/>`
  }).join('')
  const beads = Array.from({ length: 48 }, (_, i) => {
    const a = (i / 48) * Math.PI * 2
    return `<circle cx="${(256 + Math.cos(a) * 146).toFixed(1)}" cy="${(250 + Math.sin(a) * 146).toFixed(1)}" r="4.2"/>`
  }).join('')
  const fam = face === 'kufi' ? 'K' : 'A'
  const size = face === 'kufi' ? 112 : 128
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512">
<defs>
  <style>
    @font-face { font-family: K; src: url(data:font/woff2;base64,${kufi}) format('woff2'); }
    @font-face { font-family: A; src: url(data:font/woff2;base64,${amiri}) format('woff2'); }
  </style>
  <linearGradient id="bg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2f6544"/><stop offset="1" stop-color="#173826"/></linearGradient>
  <radialGradient id="glow" cx="0.5" cy="0.42" r="0.6"><stop offset="0" stop-color="#3f7a55" stop-opacity="0.9"/><stop offset="1" stop-color="#173826" stop-opacity="0"/></radialGradient>
  <radialGradient id="face" cx="0.36" cy="0.3" r="0.85">
    <stop offset="0" stop-color="#fff3c4"/><stop offset="0.22" stop-color="#f2d377"/><stop offset="0.55" stop-color="#d9a93f"/><stop offset="0.85" stop-color="#b07f22"/><stop offset="1" stop-color="#8d6216"/>
  </radialGradient>
  <linearGradient id="rim" x1="0.15" y1="0.1" x2="0.85" y2="0.95">
    <stop offset="0" stop-color="#fff0b8"/><stop offset="0.35" stop-color="#e2b54b"/><stop offset="0.7" stop-color="#a87720"/><stop offset="1" stop-color="#6e4c10"/>
  </linearGradient>
  <linearGradient id="rimIn" x1="0.85" y1="0.9" x2="0.15" y2="0.1">
    <stop offset="0" stop-color="#fff0b8"/><stop offset="0.4" stop-color="#d9a93f"/><stop offset="1" stop-color="#7a5413"/>
  </linearGradient>
  <linearGradient id="edge" x1="0" y1="0" x2="1" y2="0">
    <stop offset="0" stop-color="#5e400c"/><stop offset="0.3" stop-color="#a77a24"/><stop offset="0.55" stop-color="#d7aa47"/><stop offset="0.8" stop-color="#8f6519"/><stop offset="1" stop-color="#4f350a"/>
  </linearGradient>
  <linearGradient id="ink" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#9a6c18"/><stop offset="1" stop-color="#6a4709"/></linearGradient>
  <linearGradient id="shine" x1="0.2" y1="0" x2="0.6" y2="0.7"><stop offset="0" stop-color="#fff" stop-opacity="0.55"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></linearGradient>
  <filter id="drop" x="-30%" y="-30%" width="160%" height="170%"><feGaussianBlur in="SourceAlpha" stdDeviation="14"/><feOffset dy="18"/><feComponentTransfer><feFuncA type="linear" slope="0.55"/></feComponentTransfer><feMerge><feMergeNode/><feMergeNode in="SourceGraphic"/></feMerge></filter>
  <clipPath id="faceClip"><circle cx="256" cy="250" r="186"/></clipPath>
  ${bg === 'icon' ? '<clipPath id="tile"><rect width="512" height="512" rx="112"/></clipPath>' : ''}
</defs>
<g ${bg === 'icon' ? 'clip-path="url(#tile)"' : ''}>
  ${bg !== 'none' ? '<rect width="512" height="512" fill="url(#bg)"/><rect width="512" height="512" fill="url(#glow)"/>' : ''}
  <g transform="translate(${256 - 256 * scale} ${256 - 256 * scale}) scale(${scale})"><g filter="url(#drop)">
    <!-- coin thickness (seen below the face) with milled edge -->
    <circle cx="256" cy="268" r="196" fill="url(#edge)"/>
    <g stroke="#4a3208" stroke-opacity="0.55" stroke-width="2.2">${reeds}</g>
    <!-- rim and face -->
    <circle cx="256" cy="250" r="196" fill="url(#rim)"/>
    <circle cx="256" cy="250" r="172" fill="url(#rimIn)"/>
    <circle cx="256" cy="250" r="166" fill="url(#face)"/>
    <!-- raised bead ring -->
    <g fill="#f6dc8c" stroke="#8d6216" stroke-width="1.2">${beads}</g>
    <circle cx="256" cy="250" r="128" fill="none" stroke="#9a6c18" stroke-opacity="0.55" stroke-width="2"/>
    <circle cx="256" cy="251.5" r="128" fill="none" stroke="#fff3c4" stroke-opacity="0.5" stroke-width="1.2"/>
    <!-- embossed word: light edge up-left, shadow down-right, engraved fill -->
    <g font-family="${fam}" font-size="${size}" text-anchor="middle" direction="rtl">
      <text x="259" y="${face === 'kufi' ? 290 : 286}" fill="#5a3c07" fill-opacity="0.6">دينار</text>
      <text x="253" y="${face === 'kufi' ? 284 : 280}" fill="#fff6d0" fill-opacity="0.85">دينار</text>
      <text x="256" y="${face === 'kufi' ? 287 : 283}" fill="url(#ink)">دينار</text>
    </g>
    <!-- small eight-point star above, line below -->
    <g transform="translate(256 166)">
      <path d="${CRESCENT}" transform="translate(2 2)" fill="#5a3c07" fill-opacity="0.55"/>
      <path d="${CRESCENT}" transform="translate(-1.5 -1.5)" fill="#fff6d0" fill-opacity="0.85"/>
      <path d="${CRESCENT}" fill="url(#ink)"/>
    </g>
    <path d="M196 ${face === 'kufi' ? 326 : 344}h120" stroke="#8d6216" stroke-width="3" stroke-linecap="round"/>
    <circle cx="256" cy="${face === 'kufi' ? 326 : 344}" r="5" fill="#8d6216"/>
    <!-- specular sweep -->
    <g clip-path="url(#faceClip)"><ellipse cx="190" cy="150" rx="170" ry="95" transform="rotate(-32 190 150)" fill="url(#shine)"/></g>
  </g></g>
</g>
</svg>`
}

const b = await chromium.launch({ executablePath: process.env.CHROMIUM })
const shot = async (svg, size, file, transparent) => {
  const p = await b.newPage({ viewport: { width: size, height: size } })
  await p.setContent(`<html><body style="margin:0;background:transparent">${svg.replace('width="512" height="512"', `width="${size}" height="${size}"`)}</body></html>`)
  await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(250)
  await p.screenshot({ path: file, omitBackground: transparent })
  await p.close()
}
const OUT = process.argv[2] ?? '.'
await shot(coinSvg('icon'), 1024, `${OUT}/dinar-logo-1024.png`, true)        // master logo
await shot(coinSvg('none'), 1024, `${OUT}/dinar-coin-1024.png`, true)        // coin alone, transparent
await shot(coinSvg('icon'), 512, `${OUT}/icon-512.png`, true)
await shot(coinSvg('icon'), 192, `${OUT}/icon-192.png`, true)
await shot(coinSvg('icon'), 64, `${OUT}/favicon-64.png`, true)
await shot(coinSvg('full', 'kufi', 0.78), 512, `${OUT}/icon-maskable-512.png`, false) // Android masks: keep the coin in the safe zone
await shot(coinSvg('full', 'kufi', 0.9), 180, `${OUT}/apple-touch-icon.png`, false)  // iOS rounds the corners itself
await b.close()
