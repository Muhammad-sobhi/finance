# Treasury · خزينتي

Personal finance app for one owner and their heirs — Laravel 12 API (`backend/`) + React/TypeScript SPA (`frontend/`).
Arabic-first (RTL) with English, four themes, and a strict no-scroll layout. See `CLAUDE.md` for the brief and `design/` for the visual source of truth.

| Step | Scope | State |
| --- | --- | --- |
| 1 | Schema, owner-scoped policies, Sanctum auth, accounts / categories / transactions API | ✅ |
| 2 | Shell (4 themes, AR/EN + RTL, nav, no-scroll engine), login, dashboard, daily expenses + quick-add, accounts, categories | ✅ |
| 3 | Debts, bills, subscriptions, investments, assets, settings & exchange rates | ✅ |
| 4 | Alerts: in-app center, email, browser push (service worker + VAPID); each item+stage once | ✅ |
| 5 | Reports (month/year), growth vs previous period, net-worth trend, rule-based advice (AR + EN) | ✅ |
| 6 | Heir access: dead-man switch + manual request with trusted-contact confirmation; read-only heirs | ✅ |
| 7 | This README, deployment files in `deploy/` | ✅ |
| + | Income: recurring income (salary, auto-record or one-tap "received"), freelance projects with payments and outstanding, overview | ✅ |

**Database:** MySQL 8 in production (`DB_CONNECTION=mysql`). The test suite runs on SQLite in memory by default and also passes on MySQL/MariaDB (see *Checks*).

---

## Local setup

Requirements: PHP 8.3 (`pdo_mysql`, `mbstring`, `openssl`, `curl`, `intl`; `gmp` recommended), Composer 2, Node 20+, MySQL 8 (or MariaDB 10.6+).

```bash
# Database
mysql -uroot -e "CREATE DATABASE finance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                 CREATE USER 'finance'@'localhost' IDENTIFIED BY 'secret';
                 GRANT ALL ON finance.* TO 'finance'@'localhost';"

# API  (http://127.0.0.1:8000)
cd backend
composer install
cp .env.example .env                 # set DB_PASSWORD=secret (and mail settings if you want real emails)
php artisan key:generate
php artisan webpush:vapid            # browser-push keys, written into .env
php artisan migrate
php artisan finance:owner you@example.com "Your Name"     # the owner + default categories
#   …or demo data instead: php artisan db:seed --class=DemoSeeder   (demo@example.com / password)
php artisan serve
php artisan queue:work               # second terminal: emails + push
php artisan schedule:work            # third terminal (optional): alerts, heir check, snapshots…

# SPA  (http://localhost:5173, proxies /api to :8000)
cd ../frontend
npm install
npm run dev
```

Locally `MAIL_MAILER=log` writes emails (alerts, heir links) to `backend/storage/logs/laravel.log`.

## Checks

```bash
cd backend && php artisan test                         # 99 feature tests, SQLite in memory
# same suite on MySQL (uses a separate database):
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=finance_test DB_USERNAME=finance DB_PASSWORD=secret php artisan test

cd frontend && npm run build                           # type-check + production build
npm run lint

# No-scroll check in a headless browser: every page and dialog at 390×780, 900×700, 1440×900,
# in all 4 themes and both languages. Needs the API with demo data and the built SPA:
npm run build && npx vite preview --port 4173 &
npm run verify:noscroll
# also check the read-only heir view (an heir with active access):
HEIR_EMAIL=heir@example.com HEIR_PASSWORD=… npm run verify:noscroll
# CHROMIUM=/path/to/chrome if Playwright's own browser isn't installed (npx playwright install chromium)
```

The check fails if `document.documentElement.scrollHeight > innerHeight` or any element has `scrollHeight > clientHeight`.

---

## How it works (short)

- **Money** — every amount is stored in its own currency; totals convert to the owner's base currency (EGP) with the editable exchange-rate table (rate = base units per 1 unit). Transaction writes adjust balances inside one DB transaction (`TransactionService`).
- **No scroll** — lists render once off-screen, measure every row at the real width, and pack whole rows into pages (pager + swipe). Dashboard cards show what fits plus "+N more". Forms that don't fit become "Step 1 of N". Everything re-measures on resize, orientation, font load, theme and language change.
- **Alerts** — `finance:alerts` (hourly) checks bills, subscriptions, debts, card payment days and investment maturities: N days before (per item, else the settings default), on the day, overdue. `alert_logs` makes each item + stage + due date fire once.
- **Heirs** — see *Heir access* below. Heir logins are read-only, enforced by middleware **and** policies.

## API (v1)

All under `/api/v1`, JSON, `Authorization: Bearer <token>` unless marked public.

| Method | Path | Notes |
| --- | --- | --- |
| POST | `/auth/login` | public, rate-limited → `{token, user}` |
| POST | `/auth/logout` · GET/PATCH `/auth/me` | PATCH saves `theme`, `locale`, `list_mode` |
| POST | `/auth/two-factor` | **public**, login step 2: `{challenge, code \| recovery_code}` → `{token, user}` |
| PATCH/PUT/POST | `/auth/profile`, `/auth/password`, `/auth/logout-others` | name/email, password (signs out other devices), other devices |
| GET/POST | `/auth/two-factor`, `/auth/two-factor/{setup,confirm,disable,recovery-codes}` | 2FA status and management |
| GET | `/dashboard` | net worth (+ change vs last month), liquid, debts, balances, 6-month spending, upcoming |
| GET | `/reports?period=month&month=YYYY-MM` · `?period=year&year=YYYY` | totals, comparison, growth, categories, series, trend, advice |
| CRUD | `/accounts`, `/categories`, `/transactions` | `?month=YYYY-MM&account_id&category_id&type`; `GET /transactions/quick-meta` |
| CRUD | `/debts` | `POST /debts/{id}/payments`, `DELETE /debts/{id}/payments/{pid}` |
| CRUD | `/bills`, `/subscriptions` | `POST /{type}/{id}/pay` — expense + roll due date |
| GET | `/income/summary?month=YYYY-MM` | received this month by recurring / projects / other, expected, outstanding, 6-month series |
| CRUD | `/income-sources` | recurring income (salary…); `POST /income-sources/{id}/receive` records the income and rolls the next date |
| CRUD | `/projects` | freelance work; `POST /projects/{id}/payments` (income into an account), `DELETE /projects/{id}/payments/{pid}` |
| CRUD | `/investments` | `POST/DELETE /investments/{id}/values` |
| CRUD | `/assets` | gold/silver valued from settings price × grams × karat/24 |
| GET/PATCH | `/settings` | metal prices, alert days, email alerts, auto rates; `POST/PATCH/DELETE /exchange-rates` |
| GET | `/notifications` | `GET /notifications/unread`, `POST /notifications/{id}/read`, `POST /notifications/read-all` |
| GET/POST | `/push/key`, `/push/subscribe`, `/push/unsubscribe` | browser push for this device |
| GET | `/heir` | switch status, heirs, trusted contacts, requests |
| PATCH/POST | `/heir/settings`, `/heir/check-in`, `/heir/revoke` | owner only |
| CRUD | `/heirs` (+ `POST /heirs/{id}/invite`), `/trusted-contacts` | |
| POST | `/heir-requests` | **public**, rate-limited, same answer for any email |
| POST | `/heir-requests/{id}/cancel` | owner |
| GET/POST | `/links/{check-in,confirm,cancel,activate}/…` | **public**, signed + expiring (emailed links) |

Heir (role `heir`) tokens get `403 {code: "read_only"}` on every non-GET request.

## Heir access

1. Owner adds heirs (name, relation, phone, email, national ID, address, notes — sensitive fields encrypted) and trusted contacts, and enables the switch with *inactivity days* and *grace days*.
2. **Dead-man switch** — any authenticated request (or the *I'm fine* button / emailed check-in link) counts as activity. After N inactive days the owner gets a warning email + in-app alert with a one-click check-in link, repeated daily during the grace window. No check-in → access activates.
3. **Manual request** — the heir uses *Heir access request* on the login page. Each trusted contact gets a confirm link; once confirmed the owner is emailed (and notified in-app) with a cancel link and has the grace window to cancel. Otherwise access activates.
4. On activation every heir is emailed a set-password link (7 days). They then log in normally and see everything read-only with a banner. The owner can revoke access at any time.

---

## Production: Hostinger VPS (Ubuntu 24.04)

HTTPS is required: browsers only allow service workers and web push on secure origins.

### 1. Server packages

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server supervisor git unzip certbot python3-certbot-nginx \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-intl php8.3-gmp
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable
```

### 2. MySQL

```bash
sudo mysql_secure_installation
sudo mysql -e "CREATE DATABASE finance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
               CREATE USER 'finance'@'localhost' IDENTIFIED BY '<strong-password>';
               GRANT ALL ON finance.* TO 'finance'@'localhost'; FLUSH PRIVILEGES;"
```

### 3. Code and configuration

```bash
sudo mkdir -p /var/www && sudo chown $USER:www-data /var/www
git clone <repo-url> /var/www/finance && cd /var/www/finance/backend
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate && php artisan webpush:vapid
```

Edit `backend/.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://finance.example.com
FRONTEND_URL=https://finance.example.com      # links in emails point here
APP_TIMEZONE=Africa/Cairo
DB_CONNECTION=mysql
DB_DATABASE=finance
DB_USERNAME=finance
DB_PASSWORD=<strong-password>
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
MAIL_MAILER=smtp                               # e.g. Hostinger mail or any SMTP provider
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=465
MAIL_USERNAME=alerts@example.com
MAIL_PASSWORD=<mail-password>
MAIL_FROM_ADDRESS=alerts@example.com
MAIL_FROM_NAME="Treasury"
VAPID_SUBJECT=mailto:you@example.com           # VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY set by webpush:vapid
```

Then:

```bash
php artisan migrate --force
php artisan finance:owner you@example.com "Your Name"
php artisan config:cache && php artisan route:cache && php artisan event:cache
sudo chown -R www-data:www-data storage bootstrap/cache

cd ../frontend && npm ci && npm run build      # → frontend/dist
```

### 4. nginx + HTTPS

`deploy/nginx.conf` serves the SPA from `frontend/dist` and sends `/api/*` and `/up` to PHP-FPM — one origin, so no CORS. It also sets HSTS and security headers, caches hashed assets for a year and never caches `index.html` / `sw.js`.

```bash
sudo cp /var/www/finance/deploy/nginx.conf /etc/nginx/sites-available/finance
sudo sed -i 's/finance.example.com/your.domain/g' /etc/nginx/sites-available/finance
sudo ln -s /etc/nginx/sites-available/finance /etc/nginx/sites-enabled/ && sudo rm -f /etc/nginx/sites-enabled/default
# First certificate: comment out the 443 server block, reload nginx, then:
sudo certbot --nginx -d your.domain        # obtains the certificate and sets up auto-renewal
# Uncomment the 443 block, then:
sudo nginx -t && sudo systemctl reload nginx
```

### 5. Queue worker (supervisor) and scheduler (cron)

```bash
sudo cp /var/www/finance/deploy/supervisor-queue.conf /etc/supervisor/conf.d/finance-queue.conf
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
sudo crontab -u www-data /var/www/finance/deploy/crontab      # runs `php artisan schedule:run` every minute
```

Scheduled jobs (`php artisan schedule:list`):

| Command | When | What |
| --- | --- | --- |
| `finance:alerts` | hourly | due / overdue alerts (in-app now; email + push via the queue) |
| `finance:heir-check` | hourly | dead-man reminders/activation, confirmed requests whose window ended |
| `finance:auto-pay` | 06:00 | pays due auto-pay bills/subscriptions and records auto-record income |
| `finance:fetch-rates` | 07:00 | updates exchange rates for owners who enabled it |
| `finance:snapshot` | 23:50 | this month's net worth (trend charts, growth) |

### 6. Updates

```bash
cd /var/www/finance && ./deploy/deploy.sh    # pull, composer, migrate, caches, queue restart, SPA build
```

### 7. Backups (recommended)

```bash
# /etc/cron.daily/finance-backup
mysqldump --single-transaction finance | gzip > /var/backups/finance-$(date +%F).sql.gz
find /var/backups -name 'finance-*.sql.gz' -mtime +30 -delete
```

Keep `backend/.env` (it holds `APP_KEY`, which decrypts account numbers, national IDs and notes) backed up separately and securely — without it the encrypted fields cannot be read.

## Security notes

What protects the app (each item is covered by feature tests unless noted):

- **Login** — bcrypt-hashed passwords (min 10 chars); 5 attempts/min per email+IP and 20/min per IP; the same answer and the same work for unknown emails and wrong passwords (no account discovery by message or timing).
- **Sessions** — Sanctum bearer tokens that expire after 30 days (`SANCTUM_EXPIRATION`, pruned daily); logout revokes the token; *Settings → Account & security* changes the password (signs out every other device) and can sign out other devices on demand. Changing the login email needs the current password.
- **Authorization** — every record is scoped to its owner by policy; a test sweeps every module to prove one owner can't read, change or delete another's data or reference it in a write. Heirs are read-only by middleware *and* policy; the only writes they can make are their own display prefs, password and sessions.
- **Input** — every write is validated server-side; mass assignment is restricted to fillable fields; queries go through Eloquent/bound parameters (no SQL built from input).
- **Data at rest** — account numbers and notes, heir national ID / address / notes, debt and project notes are encrypted with `APP_KEY`.
- **Emailed actions** — signed, expiring links; the heir request never reveals whether an email is registered and is rate-limited.
- **Browser** (deploy/nginx.conf) — HTTPS only with HSTS; a strict Content-Security-Policy (only our own scripts, no inline scripts, no third-party hosts, no framing); `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `COOP`; `X-Powered-By` hidden. CORS allows only `FRONTEND_URL`. Verified locally through nginx: no CSP violations, service worker and fonts load.
- **Dependencies** — `composer audit` and `npm audit` report no known vulnerabilities (re-run them on each update).
- **Secrets** — none in the repo; only `.env.example`. Set `APP_DEBUG=false` in production and `expose_php = Off` in `/etc/php/8.3/fpm/php.ini`.

- **Two-factor authentication** — optional TOTP (Google/Microsoft Authenticator, 1Password) from *Settings → Account & security*: the secret is encrypted, the QR is drawn in the browser (never sent elsewhere), each code works once (no replay), 8 single-use recovery codes are stored hashed, the second login step expires after 5 minutes and is limited to 5 tries/min. Turning it off needs the password and a code.
