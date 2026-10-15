# Personal Finance App — Project Brief

Owner: Muhammad (full-stack developer, Laravel background). Single owner + heir(s). Egypt-based (EGP default).

## Stack
- `backend/` — Laravel 12 API, Sanctum token auth, MySQL in production (SQLite allowed for tests). PHP 8.3.
- `frontend/` — React + TypeScript + Vite, React Router. No UI framework; plain CSS with theme tokens.
- Production: Hostinger VPS. Laravel scheduler (`schedule:run` via cron) + queue worker.
- Keep the API clean and versioned (`/api/v1/...`) — a mobile app may consume it later.

## Reference files (read before building UI)
- `design/finance-prototype.html` — working prototype. Open it in a browser. It is the visual source of truth.
- `design/theme.css` — production theme tokens + layout CSS (4 themes, breakpoints, no-scroll rules). Use it in `frontend/src/styles/`.
- `design/i18n.ts` — Arabic/English dictionary + number/date/money formatters. Use it in `frontend/src/`.
- `design/samples/*.png` — static mockups of the 4 themes.

## Features (all required)
1. **Accounts / money holders**: debit card, credit card (with limit + due date), InstaPay, Vodafone Cash, Etisalat (e&) Cash, Orange Cash, WE Pay, cash, bank, savings. Each has a currency and a current balance.
2. **Debts**: person-based. Direction `owed_to_me` / `i_owe`. Amount, currency, date, due date, notes, partial payments (each payment can move money in/out of an account). Status open/settled.
3. **Bills**: name, category, amount, due date, repeat cycle (once/weekly/monthly/quarterly/yearly), remind-days-before, auto-pay flag, pay-from account. "Mark paid" creates an expense transaction and rolls the due date to the next cycle.
4. **Subscriptions**: same as bills plus scope `personal` / `work`, start date, renew date. Show total monthly cost (normalize cycles) split personal vs work.
5. **Investments with full details**: type (stocks, fund, certificate, deposit, real estate, gold, silver, crypto, business, bonds, other), platform/issuer, invested amount, current value, quantity + unit, start date, maturity date, annual return %, payout cycle, notes. Value history snapshots for growth charts. Profit and ROI.
6. **Alerts**: for bills, subscriptions, debt due dates, credit card due dates, investment maturity. Fire at N days before (per item, default from settings), on the day, and when overdue. Channels: in-app notification center, email, browser web push (PWA service worker + VAPID; use `laravel-notification-channels/webpush`). Deduplicate so each item+stage alerts once.
7. **Heir access** — BOTH mechanisms:
   - **Dead-man switch**: owner sets inactivity days (e.g. 60) and grace days. When inactive past the threshold, email the owner reminders during the grace window. If no check-in, activate heir access and email heirs a secure link.
   - **Manual request**: heir submits request by email → a trusted contact gets a confirm link → owner is notified and has the grace window to cancel → then access activates.
   - Heir has full profile (name, relation, phone, email, national ID optional, address, notes). Heir login is **read-only** everywhere (enforce on the API with a policy/middleware, not just the UI). Show a read-only banner.
   - Owner can "check in" any time to reset the timer. Every authenticated request updates last activity.
8. **Wealth / assets**: building, land, car, device, gold (grams + karat), silver (grams), jewelry, foreign cash, other. Purchase price/date, current valuation, location, notes. Gold/silver value auto-computed from settings price × grams × karat/24.
9. **Daily expenses** (top priority for daily use):
   - Transactions: expense / income / transfer between accounts. Amount, currency, account, category, date (default today), note.
   - Creating/editing/deleting a transaction updates account balances atomically (DB transaction).
   - **Quick-add**: a floating "+" button on every page opens a minimal sheet — big amount input, category chips (most-used first), account (last used preselected), save. Must take < 5 seconds to log an expense on a phone.
   - Day view grouped by date with day totals; month total; filter by category/account.
   - Editable categories with icon + color, seeded defaults (food, transport, bills, shopping, health, education, entertainment, family, work, other; income: salary, freelance, other).
10. **Reports & analysis**: per month and per year — total income, total spending, savings, savings rate, spending by category, income vs spending chart, net-worth trend (monthly snapshots). Compare to previous period and label **growing / not growing** with %. Rule-based advice list (e.g. savings rate < 20%, category up > 25% vs 3-month average, subscriptions > X% of income, idle cash above 6 months of spending, credit card utilization > 30%, debts overdue). Advice text in AR + EN.
11. **Multi-currency**: base currency EGP. Store each amount in its own currency. Exchange rates table editable manually + optional auto-fetch job. All totals converted to base.

## Design rules (strict)
- **4 themes**, switchable at runtime, saved per user: `ledger` (paper/serif), `swiss` (black/white + orange), `geo` (sand/indigo/terracotta, Kufi headings), `vault` (dark charcoal + brass, mono numbers). Tokens in `design/theme.css`. No purple gradients, no glassmorphism, no generic "AI" look.
- **Arabic + English**, RTL/LTR switch, Arabic default. All UI strings go through i18n. Numbers/dates formatted per locale.
- **No scrolling — ever.** Not vertical, not horizontal, not on the page, not inside cards. Page is exactly the viewport height (`100dvh`).
  - Lists paginate: compute rows/cards per page from available height (see `pageSizeFor` in the prototype). Pager + swipe left/right on touch.
  - Dashboard cards show as many items as fit and end with a "+N more" link (see `fitList`).
  - Forms that don't fit become multi-step (Step 1 of N), never scroll.
  - Recalculate on resize/orientation change.
- **Responsive**: desktop = full sidebar; tablet (≤1100px) = icon rail; phone (≤720px) = bottom nav (Home, Accounts, Daily expenses, Bills, More sheet).
- **Tables become cards on phones.** On desktop the user can toggle Table / Cards (saved).
- Accessible: real buttons/links, focus states, labels on inputs, sufficient contrast in all 4 themes.

## Security
- Financial data: hash passwords, Sanctum tokens, rate-limit login and heir-request endpoints, policies on every model (owner_id scoping), encrypted casts for sensitive fields (account numbers, national ID, notes on heirs). Signed/expiring URLs for heir activation and trusted-contact confirmation. No secrets in the repo; `.env.example` only.

## Build order
1. Backend: migrations, models, policies, auth, CRUD for accounts/categories/transactions (daily expenses first), feature tests.
2. Frontend: shell (themes, i18n, nav, no-scroll engine), login, dashboard, daily expenses + quick-add, accounts.
3. Debts, bills, subscriptions, investments, assets, currencies/settings.
4. Alerts (scheduler command, in-app, email, web push).
5. Reports + advice.
6. Heir system (both mechanisms) + read-only enforcement + tests.
7. README with local setup and Hostinger VPS deploy steps (nginx, PHP-FPM, MySQL, cron, queue via supervisor, HTTPS required for push).

## Quality bar
- Every API module has feature tests (`php artisan test` must pass).
- `npm run build` must pass with no TypeScript errors.
- Verify no-scroll in a headless browser at 390×780, 900×700, 1440×900 for every page: `document.documentElement.scrollHeight <= innerHeight` and no element with `scrollHeight > clientHeight`.
- Commit in small, clear steps.
