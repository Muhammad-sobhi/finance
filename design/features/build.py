# Generates features.html (rendered to features.png with headless Chromium).
import base64, pathlib
here = pathlib.Path(__file__).parent
def b64(p): return base64.b64encode((here/'..'/p).read_bytes()).decode()

I = {  # 24x24 stroke icons
'plus':'<rect x="3" y="3" width="18" height="18" rx="5"/><path d="M12 8v8M8 12h8"/>',
'wallet':'<path d="M3 7h15a3 3 0 0 1 3 3v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M3 7l12-4v4"/><circle cx="16.5" cy="14" r="1.3"/>',
'transfer':'<path d="M4 8h14l-4-4M20 16H6l4 4"/>',
'income':'<path d="M12 20V6M6 12l6-6 6 6"/><path d="M4 22h16"/>',
'debt':'<circle cx="7" cy="8" r="3"/><circle cx="17" cy="8" r="3"/><path d="M2 20c0-3 2-5 5-5s5 2 5 5M12 20c0-3 2-5 5-5s5 2 5 5"/>',
'circle':'<circle cx="12" cy="12" r="8"/><circle cx="12" cy="4" r="1.8"/><circle cx="19" cy="15" r="1.8"/><circle cx="5" cy="15" r="1.8"/>',
'bill':'<path d="M6 2h12v20l-3-2-3 2-3-2-3 2z"/><path d="M9 7h6M9 11h6M9 15h3"/>',
'sub':'<path d="M20 12a8 8 0 1 1-2.3-5.6"/><path d="M20 4v4h-4"/><path d="M12 8v4l3 2"/>',
'invest':'<path d="M3 20h18"/><path d="M5 16l5-5 3 3 6-7"/><path d="M15 7h4v4"/>',
'house':'<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><rect x="10" y="14" width="4" height="6"/>',
'target':'<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.5"/>',
'zakat':'<path d="M12 3a9 9 0 1 0 8 13 7 7 0 1 1-8-13z"/><path d="M17 5l.8 1.7 1.7.3-1.3 1.2.3 1.8-1.5-.9-1.5.9.3-1.8-1.3-1.2 1.7-.3z"/>',
'bell':'<path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 21h4"/>',
'chart':'<rect x="4" y="12" width="3" height="8"/><rect x="10.5" y="7" width="3" height="13"/><rect x="17" y="3" width="3" height="17"/>',
'spark':'<path d="M12 3v4M12 17v4M3 12h4M17 12h4"/><path d="M12 8l1.2 2.8L16 12l-2.8 1.2L12 16l-1.2-2.8L8 12l2.8-1.2z"/>',
'fx':'<circle cx="8" cy="9" r="5"/><circle cx="16" cy="15" r="5"/><path d="M7 7.5h2M8 6v6"/>',
'heir':'<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><circle cx="12" cy="10" r="2.5"/><path d="M8 17c.8-2 2.2-3 4-3s3.2 1 4 3"/>',
'vault':'<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="12" cy="12" r="4"/><path d="M12 8v1M12 15v1M8 12h1M15 12h1M3 20v1M21 20v1"/>',
'lock':'<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><circle cx="12" cy="15.5" r="1.5"/>',
'offline':'<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/><path d="M3 3l18 18"/>',
'data':'<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
'theme':'<circle cx="12" cy="12" r="9"/><path d="M12 3v18"/><path d="M12 3a9 9 0 0 1 0 18" fill="currentColor" stroke="none"/>',
'lang':'<path d="M3 5h10M8 3v2M5 5c1 4 4 7 7 8M11 5c-1 4-4 7-7 8"/><path d="M13 21l4-9 4 9M14.5 18h5"/>',
'pulse':'<path d="M2 12h4l3-7 4 14 3-7h6"/>',
}
C = ['#2e5e3e','#c0623b','#1e3a5f','#b08d57','#e4572e','#6b4f2a']
F = [
 ('plus','Quick-add in 5 seconds','Tap the + button on any page, type the amount, pick a category and save. Your last used account is already selected.'),
 ('wallet','All your money holders','Bank, debit and credit cards, InstaPay, Vodafone Cash, e& Cash, Orange Cash, WE Pay, cash and savings — each with its own balance.'),
 ('transfer','Daily expenses & transfers','Log expenses, income and transfers. Balances update automatically. See each day with its total and filter by category or account.'),
 ('income','Income','Salary records itself every month or with one tap. Track freelance projects, their payments and what is still owed to you.'),
 ('debt','Debts','Know who owes you and whom you owe. Pay back in parts from any account until the debt is settled.'),
 ('circle','Installments & gameyas','Follow taqseet plans month by month. Join or run a gameya with members, rounds, payout turns and WhatsApp reminders.'),
 ('bill','Bills','Set the amount, due date and repeat cycle. Mark it paid and the expense is booked and the next due date is set.'),
 ('sub','Subscriptions','See your total monthly subscription cost, split between personal and work.'),
 ('invest','Investments','Stocks, funds, certificates, deposits, gold, crypto, real estate and more — with value history, profit and ROI.'),
 ('house','Wealth & assets','Record buildings, land, cars, devices, gold, silver and jewelry. Gold and silver are valued from today’s price.'),
 ('target','Budgets & savings goals','Set a monthly limit per category and get warned at 80% and 100%. Goals show how much to save each month.'),
 ('zakat','Zakat calculator','Calculates your zakatable wealth against the nisab and reminds you before your zakat day.'),
 ('bell','Alerts','Get reminded before, on the day and when overdue — in the app, by email and as phone notifications. Each alert comes once.'),
 ('chart','Reports & advice','Monthly and yearly income, spending, savings rate and net worth. See if you are growing, with advice in Arabic and English.'),
 ('spark','Smart forecast','See how your month will end before it ends, and get warned about unusual expenses.'),
 ('fx','Multi-currency','Every amount keeps its own currency. All totals are converted to EGP with rates you can edit.'),
 ('heir','Heir access','If you stop using the app, your heirs get read-only access after a grace period — or they request it through a trusted contact.'),
 ('vault','Vault','Keep passwords, bank details and documents encrypted in your browser and protected by a PIN.'),
 ('lock','Security','Your financial data is encrypted. Two-factor login, new-device alerts, and an app lock with PIN, fingerprint or face.'),
 ('offline','Works offline, installs as an app','Install it on your phone. Add expenses without internet — they are sent when you are back online.'),
 ('data','Your data','Export to CSV, print a report, import bank statements, attach receipt photos and download an encrypted backup.'),
 ('pulse','Always running','Nightly encrypted backups, health checks and email warnings if anything on the server stops.'),
 ('lang','Arabic & English','Arabic by default with full right-to-left layout. Switch to English any time. Numbers and dates follow your language.'),
 ('theme','4 themes, no scrolling','Ledger, Swiss, Geometric and Vault themes. Every page fits the screen on phone, tablet and desktop — no scrolling ever.'),
]
cards = ''
for n,(ic,t,d) in enumerate(F):
    c = C[n % len(C)]
    cards += f'''<div class="card"><div class="ic" style="--c:{c}"><svg viewBox="0 0 24 24">{I[ic]}</svg></div>
<div><h3><span class="n">{n+1:02d}</span>{t}</h3><p>{d}</p></div></div>'''
shots = ''.join(f'<figure><img src="data:image/png;base64,{b64("samples/"+f)}"><figcaption>{c}</figcaption></figure>'
  for f,c in [('1-ledger.png','Ledger'),('2-swiss.png','Swiss'),('3-arabic-geometric.png','Geometric (Arabic)'),('4-vault.png','Vault')])
html = f'''<!doctype html><html><head><meta charset="utf-8"><title>Dinar features</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Inter:wght@400;500;600&family=Reem+Kufi:wght@600&display=swap" rel="stylesheet">
<style>
*{{box-sizing:border-box;margin:0}}
body{{width:1800px;background:#f4efe3;color:#1f2a24;font-family:Inter,sans-serif;padding:56px 64px 48px}}
header{{display:flex;align-items:center;gap:28px;border-bottom:3px double #1f2a24;padding-bottom:28px;margin-bottom:36px}}
header img{{width:120px;height:120px}}
h1{{font-family:Fraunces,serif;font-size:64px;line-height:1}}
h1 .ar{{font-family:'Reem Kufi',sans-serif;color:#b08d57;margin-inline-start:14px}}
.sub{{font-size:24px;margin-top:10px;color:#4a5550}}
.tag{{margin-inline-start:auto;text-align:end;font-size:18px;color:#4a5550;line-height:1.6}}
.tag b{{color:#2e5e3e}}
.grid{{display:grid;grid-template-columns:repeat(4,1fr);gap:20px}}
.card{{background:#fffdf7;border:1px solid #d9cfb8;border-radius:12px;padding:22px;display:flex;gap:18px;align-items:flex-start}}
.ic{{flex:none;width:64px;height:64px;border-radius:14px;background:var(--c);display:grid;place-items:center}}
.ic svg{{width:36px;height:36px;fill:none;stroke:#fff;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round;color:#fff}}
h3{{font-family:Fraunces,serif;font-size:22px;margin-bottom:8px;line-height:1.2}}
.n{{font-family:Inter;font-size:13px;color:#b08d57;margin-inline-end:8px;font-weight:600;vertical-align:3px}}
p{{font-size:16.5px;line-height:1.5;color:#36413b}}
h2{{font-family:Fraunces,serif;font-size:30px;margin:40px 0 18px}}
.shots{{display:grid;grid-template-columns:repeat(4,1fr);gap:20px}}
figure img{{width:100%;border-radius:10px;border:1px solid #d9cfb8;display:block}}
figcaption{{text-align:center;font-size:16px;margin-top:8px;font-weight:500}}
footer{{margin-top:32px;padding-top:18px;border-top:1px solid #d9cfb8;display:flex;justify-content:space-between;font-size:16px;color:#4a5550}}
</style></head><body>
<header><img src="data:image/png;base64,{b64('logo/dinar-coin-1024.png')}">
<div><h1>Dinar<span class="ar">دينار</span></h1><div class="sub">Your personal finance app — everything it does, on one page.</div></div>
<div class="tag">Arabic-first · Egyptian pound by default<br><b>Phone · Tablet · Desktop</b></div></header>
<div class="grid">{cards}</div>
<h2>Four themes — switch any time</h2>
<div class="shots">{shots}</div>
<footer><span>Built with Laravel 12 + React · Hosted on your own server</span><span>One owner · Read-only access for heirs</span></footer>
</body></html>'''
(here/'features.html').write_text(html)
