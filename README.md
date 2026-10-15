# Treasury · خزينتي

Personal finance app — Laravel 12 API (`backend/`) + React/TypeScript SPA (`frontend/`).
See `CLAUDE.md` for the full brief and `design/` for the visual source of truth.

> Full Hostinger VPS deployment steps (nginx, PHP-FPM, MySQL, cron, supervisor, HTTPS) come with build step 7.

## Status

| Step | Scope | State |
| --- | --- | --- |
| 1 | Backend core: schema, policies, Sanctum auth, accounts / categories / transactions API, feature tests | ✅ |
| 2 | Frontend shell (4 themes, AR/EN + RTL, nav, no-scroll engine), login, dashboard, daily expenses + quick-add, accounts, categories | ✅ |
| 3–7 | Debts, bills, subscriptions, investments, assets, settings · alerts · reports · heir system · deploy docs | next |

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

Heir logins (role `heir`) are read-only: every non-GET request is refused with `403 {code: "read_only"}` by middleware, and policies refuse writes independently.
