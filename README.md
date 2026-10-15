# Treasury · خزينتي

Personal finance app — Laravel 12 API (`backend/`) + React/TypeScript SPA (`frontend/`).
See `CLAUDE.md` for the full brief and `design/` for the visual source of truth.

> Full Hostinger VPS deployment steps (nginx, PHP-FPM, MySQL, cron, supervisor, HTTPS) come with build step 7.

## Status

| Step | Scope | State |
| --- | --- | --- |
| 1 | Backend core: schema, policies, Sanctum auth, accounts / categories / transactions API, feature tests | ✅ |
| 2 | Frontend shell (4 themes, AR/EN + RTL, nav, no-scroll engine), login, dashboard, daily expenses + quick-add, accounts, categories | ✅ |
| 3 | Debts (partial payments moving money), bills & subscriptions (mark paid, auto-pay), investments (value history, ROI), assets (gold/silver auto-valued), settings & exchange rates | ✅ |
| 4 | Alerts: in-app notification center, email, browser web push (service worker + VAPID); each item+stage once | ✅ |
| 5–7 | Reports · heir system · deploy docs | next |

## Local setup

Requirements: PHP 8.3 (pdo_mysql or pdo_sqlite), Composer, Node 20+, MySQL 8 (or SQLite for a quick run).

```bash
# API
cd backend
composer install
cp .env.example .env          # set DB_* (or DB_CONNECTION=sqlite and touch database/database.sqlite)
php artisan key:generate
php artisan migrate
php artisan finance:owner you@example.com "Your Name"   # creates the owner + default categories
# or, for demo data: php artisan db:seed --class=DemoSeeder   (demo@example.com / password)
php artisan serve             # http://127.0.0.1:8000

# SPA (proxies /api to :8000)
cd ../frontend
npm install
npm run dev                   # http://localhost:5173
```

## Checks

```bash
cd backend && php artisan test          # feature tests (SQLite in-memory)
cd frontend && npm run build            # type-check + production build
# No-scroll check: API running with demo data, then in frontend/:
npm run build && npx vite preview --port 4173 &
npm run verify:noscroll                 # 390×780, 900×700, 1440×900 × themes × AR/EN
# (set CHROMIUM=/path/to/chrome if Playwright's own browser isn't installed)
```

## API (v1)

All under `/api/v1`, JSON, `Authorization: Bearer <token>`.

| Method | Path | Notes |
| --- | --- | --- |
| POST | `/auth/login` | `{email, password, device_name?}` → `{token, user}`; rate-limited |
| POST | `/auth/logout` | revokes the current token |
| GET / PATCH | `/auth/me` | profile; PATCH saves `theme`, `locale`, `list_mode` |
| GET | `/dashboard` | net worth, liquid cash, balances, 6-month spending, upcoming |
| CRUD | `/accounts` | balances in own currency + `base_balance`; delete refused once it has transactions |
| CRUD | `/categories` | `?type=expense\|income`; sorted most-used (90 days) first |
| CRUD | `/transactions` | `?month=YYYY-MM&account_id&category_id&type`; returns month + per-day totals; writes adjust balances atomically |
| GET | `/transactions/quick-meta` | last used account/category for quick-add |
| CRUD | `/debts` | `?status&direction`; meta totals; `POST /debts/{id}/payments`, `DELETE /debts/{id}/payments/{pid}` |
| CRUD | `/bills`, `/subscriptions` | `POST /{type}/{id}/pay` creates the expense and rolls the due date; subscriptions meta = monthly cost personal/work |
| CRUD | `/investments` | `POST /investments/{id}/values` records a dated value; detail returns history |
| CRUD | `/assets` | gold/silver valued from settings price × grams × karat/24 |
| GET / PATCH | `/settings` | metal prices, alert days, email alerts; `POST/PATCH/DELETE /exchange-rates` |

| GET | `/notifications` | latest alerts + unread count; `GET /notifications/unread`, `POST /notifications/{id}/read`, `POST /notifications/read-all` |
| GET / POST | `/push/key`, `/push/subscribe`, `/push/unsubscribe` | browser push (VAPID public key, register/remove this device) |

Scheduler (`* * * * * php artisan schedule:run`):
- `finance:alerts` hourly — N days before (per item, else the settings default), on the day, and overdue; deduplicated per item + stage + due date. In-app alerts are written immediately; email and push go through the queue worker.
- `finance:auto-pay` daily at 06:00 — pays due auto-pay bills/subscriptions.

Web push needs VAPID keys (`php artisan webpush:vapid`) and HTTPS in production (browsers only allow push and service workers on secure origins; `localhost` is exempt).

Heir logins (role `heir`) are read-only: every non-GET request is refused with `403 {code: "read_only"}` by middleware, and policies refuse writes independently.
