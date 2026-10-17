# Dinar · دينار

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
| + | Planning: monthly budgets per category (80% / 100% alerts), savings goals, zakat calculator with reminder | ✅ |
| + | Debts hub: installment plans (taqseet) and gameyas next to personal debts | ✅ |
| + | Smart: month-end forecast, unusual-expense alerts, optional daily gold/silver spot prices | ✅ |
| + | Data: CSV export, printable report, encrypted backup + restore, bank statement import, receipt photos | ✅ |
| + | Security & app: optional 2FA (trusted devices), sign-in activity with new-device alerts, installable PWA with offline page | ✅ |

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
php artisan vault:key                # data master key: put the printed line in .env as DATA_MASTER_KEY=
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
cd backend && php artisan test                         # 148 feature tests, SQLite in memory
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
- **Budgets** — a monthly limit per expense category; spending is converted to base currency. A notice goes out once per month at 80% and again when the limit is passed (checked after each transaction and hourly). Each card shows what is left per day and a straight-line month-end projection.
- **Goals** — progress is either a linked account's balance (money really set aside) or amounts added by hand. Shows the monthly amount needed to reach the target date and whether you're on track (saved ≥ 95% of the straight-line share for the time elapsed).
- **Zakat** (a helper, not a fatwa) — zakatable wealth = positive balances + gold / silver / jewelry / foreign cash + zakatable investments + money owed to you + gameya money paid in, minus (optional) debts you owe, card/negative balances and installments due within a year. Homes, cars, devices and rental real estate are excluded by default; any asset or investment can be ticked in or out. Nisab is 85 g of 24k gold or 595 g of silver at the settings price; the rate is 2.5% for a lunar year or 2.577% for a solar year. A reminder comes at least 7 days before the zakat day; recording the payment creates an expense and moves the day to the next hawl.
- **Installments** — a plan has an amount, a count and a first due date; due dates never drift at month ends (31 Jan → 28 Feb → 31 Mar). Each paid installment is an expense on the chosen account (cash basis); what is left counts as a liability in net worth.
- **Gameya** — members, my payout turn and the amount per round. Payments and the payout are savings, not spending or income, so they're kept out of reports (like debts); the net position (paid in − taken out) counts in net worth. Alerts for each round and for my turn.
- **Forecast** — spent so far + everyday spending at this month's pace (the 3-month pace during the first week) for the days left + bills, subscriptions and installments still due this month, against income received and still expected. Shown on the dashboard; reports warn when it ends negative.
- **Unusual expense** — a hand-entered expense more than 3× its category's 90-day median (with at least 5 past expenses) sends a notice.
- **Backup** — *Settings → Your data → Encrypted backup* (password re-check) downloads the whole ledger as gzip'd JSON sealed with AES-256-GCM under a PBKDF2-SHA256 key (210k iterations) from a passphrase you choose; the server never keeps it. Restore with `php artisan finance:restore <file> <owner-email>` into a new, empty owner (ids are remapped and encrypted columns re-encrypted with that server's `APP_KEY`). Receipt files aren't included — back up `storage/app/private/receipts` with the server backup.
- **Bank import** — the browser reads the CSV (`, ; tab`, quotes), guesses the date / description / amount (or debit + credit) columns in English or Arabic, and you confirm. The server skips rows already recorded in that account (same date, direction, amount and description — counted, so two identical coffees stay two), files each description under the category it had before, shows a dry run, then imports through the ledger.
- **App on the phone (PWA)** — over HTTPS the browser offers to install it (*More → Install app*, or *Settings → Your data*; on iPhone: Share → *Add to Home Screen*). The home-screen icon has an *Add expense* shortcut that opens quick-add directly. Offline, the service worker shows a small offline page; no financial data is cached on the device.

## API (v1)

All under `/api/v1`, JSON, `Authorization: Bearer <token>` unless marked public.

| Method | Path | Notes |
| --- | --- | --- |
| POST | `/auth/login` | public, rate-limited → `{token, user}` |
| POST | `/auth/logout` · GET/PATCH `/auth/me` | PATCH saves `theme`, `locale`, `list_mode` |
| GET/POST/DELETE | `/admin/users`, `/admin/invites`, `/admin/invites/{id}/resend`, `/admin/invites/{id}` | admin only (`finance:admin`): list owners, invite by email, resend / cancel pending |
| POST | `/auth/forgot-password` · `/auth/reset-password` | **public**, rate-limited; same answer for any email; the reset signs out every device and emails a notice |
| POST | `/auth/two-factor` | **public**, login step 2: `{challenge, code \| recovery_code}` → `{token, user}` |
| PATCH/PUT/POST | `/auth/profile`, `/auth/password`, `/auth/logout-others` | name/email, password (signs out other devices), other devices |
| GET/POST | `/auth/two-factor`, `/auth/two-factor/{setup,confirm,disable,recovery-codes}` | 2FA status and management |
| GET | `/dashboard` | net worth (+ change vs last month), liquid, debts, balances, 6-month spending, upcoming |
| GET | `/reports?period=month&month=YYYY-MM` · `?period=year&year=YYYY` | totals, comparison, growth, categories, series, trend, advice |
| CRUD | `/accounts`, `/categories`, `/transactions` | `?month=YYYY-MM&account_id&category_id&type`; `GET /transactions/quick-meta` |
| — | transfers | optional `fee` (sending account currency) — booked as its own expense in *Fees*, kept in step with the transfer |
| POST/GET/DELETE | `/transactions/{id}/receipt` | receipt photo / PDF (≤ 5 MB, private disk, owner + heirs can view) |
| GET | `/export/transactions?from&to` | CSV (UTF-8 BOM, headers in the user's language) |
| POST | `/import/transactions` | `{account_id, rows: [{date, amount (− = expense), note, category_id?}], dry_run?}` → `{rows, new, duplicates, categorized, imported?}` |
| POST | `/backup` | `{current_password, passphrase}` → encrypted backup file |
| GET | `/auth/activity` | last 50 sign-ins and failed attempts |
| GET/PATCH/DELETE | `/budgets?month=YYYY-MM` | spent, remaining, pct, status ok/near/over, projected, per day left |
| CRUD | `/goals` | `POST /goals/{id}/contributions` (negative = withdrawal), `DELETE /goals/{id}/contributions/{cid}` |
| GET | `/zakat` | parts, nisab, due, days left, payments; `PATCH /zakat/settings`, `POST /zakat/pay` (`complete` rolls the date), `DELETE /zakat/payments/{id}` |
| CRUD | `/installments` | `POST /installments/{id}/pay`, `DELETE /installments/{id}/payments/{pid}` (latest only) |
| CRUD | `/gameyas` | `POST /gameyas/{id}/pay` (next round), `POST /gameyas/{id}/receive` (payout), `DELETE /gameyas/{id}/payments/{pid}` |
| CRUD | `/debts` | `POST /debts/{id}/payments`, `DELETE /debts/{id}/payments/{pid}` |
| CRUD | `/bills`, `/subscriptions` | `POST /{type}/{id}/pay` — expense + roll due date |
| GET | `/income/summary?month=YYYY-MM` | received this month by recurring / projects / other, expected, outstanding, 6-month series |
| CRUD | `/income-sources` | recurring income (salary…); `POST /income-sources/{id}/receive` records the income and rolls the next date |
| CRUD | `/projects` | freelance work; `POST /projects/{id}/payments` (income into an account), `DELETE /projects/{id}/payments/{pid}` |
| CRUD | `/investments` | `POST/DELETE /investments/{id}/values` |
| CRUD | `/assets` | gold/silver valued from settings price × grams × karat/24 |
| GET/PATCH | `/settings` | metal prices, alert days, email alerts, auto rates / metal prices; `POST /settings/metal-prices` (fetch now); `POST/PATCH/DELETE /exchange-rates` |
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

### Free trial on a server that already hosts other sites

No domain or paid email needed. Everything below sits next to your other projects without touching them.

1. **A free hostname with HTTPS.** Either use the VPS's own hostname (hPanel → VPS → Overview, e.g. `srv123456.hstgr.cloud`, if no other site uses it) or make a free one at <https://www.duckdns.org> (sign in, create `myfinance.duckdns.org`, set its IP to the VPS IP). Use it wherever this guide says `finance.example.com`, then `sudo certbot --nginx -d myfinance.duckdns.org`.
2. **Its own folder, database and names** — `/var/www/finance`, database + user `finance`, supervisor program `finance-queue`, nginx file `/etc/nginx/sites-available/finance`. Don't edit the other sites' files or the `default` site; run `sudo nginx -t` before every reload.
3. **PHP 8.3.** Check `php -v`. If the other projects use another version, install `php8.3-fpm` alongside it (both run at once); our nginx file already points at `php8.3-fpm.sock`.
4. **Email without a domain: Gmail SMTP** (free, ~500 emails/day). Turn on 2-Step Verification on the Google account, create an *App password* (Google Account → Security → App passwords), then:

   ```dotenv
   MAIL_MAILER=smtp
   MAIL_HOST=smtp.gmail.com
   MAIL_PORT=587
   MAIL_SCHEME=smtp
   MAIL_USERNAME=yourname@gmail.com
   MAIL_PASSWORD=<16-char app password>
   MAIL_FROM_ADDRESS=yourname@gmail.com
   MAIL_FROM_NAME="دينار"
   ```

   Use a **separate Gmail account for the app** (e.g. `dinar.app@gmail.com`), not your personal one: the sender's name and profile photo show in every recipient's inbox, and mail sent to yourself shows as "me". Set `MAIL_FROM_NAME="دينار"` and give that account the app icon as its photo. Emails are written in each user's language (Arabic ones right-to-left).

   Test it: `php artisan tinker --execute "Mail::raw('test', fn (\$m) => \$m->to('you@example.com')->subject('Dinar test'));"`. Later, with your own domain, only these lines change.
5. **Clients and testers** — make yourself the admin once: `php artisan finance:admin you@gmail.com`. Then *Settings → Your data → Users & invitations → Invite user*: they get an email (in their language) with a link valid for 7 days to choose their own password, and the account activates. You can resend or cancel pending invitations; the page shows names, emails and dates only — never anyone's financial data. (CLI alternative: `php artisan finance:owner tester@example.com "Name"`, then they use *Forgot password?*.) Every account's data is fully separate.

Cost: nothing. When you buy a domain, point it at the server, add it to `server_name`, run certbot for it, and update `APP_URL` / `FRONTEND_URL`.

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

Create the **data master key** (see *Data encryption* below). It lives outside the project, readable by PHP but not part of any backup:

```bash
sudo usermod -aG www-data $USER                # the deploy user can run artisan with the key
sudo mkdir -p /etc/finance
php artisan vault:key                          # prints base64:... and its fingerprint — copy both NOW
sudo sh -c 'printf "%s" "base64:...paste..." > /etc/finance/master.key'
sudo chown root:www-data /etc/finance/master.key && sudo chmod 0640 /etc/finance/master.key
```

Save the key in **two safe places away from the server** (e.g. a password manager and a printed copy in a safe). Then run `php artisan vault:check --ask` and paste each saved copy to prove it is correct.

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
CACHE_STORE=file                               # rate limits; keeps DB queries off every request
SESSION_DRIVER=database
MAIL_MAILER=smtp                               # e.g. Hostinger mail or any SMTP provider
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=465
MAIL_USERNAME=alerts@example.com
MAIL_PASSWORD=<mail-password>
MAIL_FROM_ADDRESS=alerts@example.com
MAIL_FROM_NAME="دينار"
VAPID_SUBJECT=mailto:you@example.com           # VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY set by webpush:vapid
DATA_MASTER_KEY_FILE=/etc/finance/master.key   # never put the key itself in .env on the server
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
| `finance:fetch-metals` | 07:05 | gold/silver per gram from the global spot price × the owner's USD rate (opt-in; needs a USD rate) |
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

Also copy `backend/storage/app/private/receipts` (receipt photos) with the same job, and keep an in-app *Encrypted backup* file somewhere off the server.

The dumps contain only encrypted financial data. **Never store the master key with them** — whoever has both can read everything. Keep `backend/.env` backed up separately too (it holds `APP_KEY` for sessions and signed links).

**Restore** (new server or after a failure): install as above, put the saved master key back in `/etc/finance/master.key`, import the dump (`gunzip -c finance-YYYY-MM-DD.sql.gz | mysql finance`), then `php artisan vault:check` must report that every user key opens.

## Data encryption

Users' financial data is encrypted in the database, so it can't be read from MySQL, phpMyAdmin, a dump or a backup — by an attacker or by the server admin in normal work.

- **What is encrypted:** account names, balances, limits, numbers and notes; transaction amounts, fees and notes, and receipt files; debts (person, phone, amounts, notes) and their payments; bill and subscription names, amounts and notes; income sources and freelance projects (client, name, amounts, notes) and their payments; budgets; savings goals and contributions; zakat payments; installment plans and gameyas (name, provider/organizer, amounts, notes) and their payments; investments (name, platform, amounts, quantity, return, notes) and their value history; assets (name, prices, grams, location, notes); net-worth snapshots; heirs and trusted contacts (name, phone, relation, national ID, address, notes); in-app notifications. Alerts waiting in the queue carry their names and amounts encrypted too.
- **What stays readable** (needed for queries, scheduling and login): ids, owner links, dates, currencies, types, statuses, categories, exchange rates, user settings, emails.
- **How:** every user has a random data key (AES-256-GCM), stored in `users.data_key` wrapped by the master key. One user's key can't open another's rows. The API decrypts only while handling that user's request, calculates on the server, and returns results to that user alone.
- **Password reset by email keeps working.** The keys don't depend on passwords, so users never lose data by forgetting a password, and the admin can restore backups at any time.
- **Data is lost only if** the master key and every copy of it are lost (all users), or all database backups are lost. When the admin deletes a user (*Admin → delete*, needs the admin's password and the user's email typed in), their rows, receipts, heir logins and data key are removed from the live server; copies inside older database dumps stay readable with the master key until those dumps expire (30 days with the backup cron above).
- **Limit:** whoever controls the running server (root plus the code) could technically decrypt. The protection is against database and backup access, not against a hostile root user.

Key commands:

```bash
php artisan vault:key            # generate a new master key (prints it, changes nothing)
php artisan vault:check          # live key opens every user key (prints its fingerprint)
php artisan vault:check --ask    # same, for a saved copy you type in (hidden)
```

Rotating the master key: generate a new one, put it in `/etc/finance/master.key`, set `DATA_MASTER_KEY_PREVIOUS=<old key>` in `.env`, `php artisan config:cache`, run `php artisan vault:rotate`, check with `vault:check`, then remove `DATA_MASTER_KEY_PREVIOUS`, cache the config again, and destroy the old key's copies only after new copies are saved.

**Upgrading an existing install** to this version: take a backup first, create the master key file as above and set `DATA_MASTER_KEY_FILE`, then deploy. `php artisan migrate` encrypts all existing data in place (it uses `APP_KEY` to read the fields that were encrypted the old way).

## Vault

*Vault* keeps passwords, bank and card details, documents, insurance, crypto recovery phrases and secret notes. Items are encrypted **in the browser** (WebCrypto AES-256-GCM) with a random vault key; the server never receives an item in clear. The owner picks how that key is kept:

| Mode | Opens with | Heirs | Server/admin could read |
|---|---|---|---|
| **PIN + copy for heirs** (default) | the PIN | yes, once heir access is active | technically, like the rest of the financial data |
| PIN only | the PIN | no | never |
| No PIN | sign-in | yes, once heir access is active | technically, like the rest of the financial data |

- The PIN wraps the key with PBKDF2-SHA256 (600,000 rounds); at least 8 characters, letters and digits mixed (a short numeric PIN could be guessed offline by whoever holds the database). The key copy is stored encrypted with the owner's data key and is only released to the owner of a no-PIN vault, or to an heir once heir access is active.
- With a PIN the vault locks itself after 5 idle minutes and on reload. *Forgot the PIN?* wipes the vault (needs the account password); with the heir copy nothing else is lost. Changing the mode or PIN also needs the account password.
- Documents and insurance can carry a renewal date; that date alone is stored readable so the alert can fire ("A vault item needs renewal"), without the item's name.

## Security notes

What protects the app (each item is covered by feature tests unless noted):

- **Login** — bcrypt-hashed passwords (min 10 chars); 5 attempts/min per email+IP and 20/min per IP; the same answer and the same work for unknown emails and wrong passwords (no account discovery by message or timing).
- **Sessions** — Sanctum bearer tokens that expire after 30 days (`SANCTUM_EXPIRATION`, pruned daily); logout revokes the token; *Settings → Account & security* changes the password (signs out every other device) and can sign out other devices on demand. Changing the login email needs the current password.
- **Authorization** — every record is scoped to its owner by policy; a test sweeps every module to prove one owner can't read, change or delete another's data or reference it in a write. Heirs are read-only by middleware *and* policy; the only writes they can make are their own display prefs, password and sessions.
- **Input** — every write is validated server-side; mass assignment is restricted to fillable fields; queries go through Eloquent/bound parameters (no SQL built from input).
- **Data at rest** — financial data is encrypted per user with keys held outside the database (see *Data encryption*); 2FA secrets use `APP_KEY`.
- **Emailed actions** — signed, expiring links; the heir request never reveals whether an email is registered and is rate-limited.
- **Browser** (deploy/nginx.conf) — HTTPS only with HSTS; a strict Content-Security-Policy (only our own scripts, no inline scripts, no third-party hosts, no framing); `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `COOP`; `X-Powered-By` hidden. CORS allows only `FRONTEND_URL`. Verified locally through nginx: no CSP violations, service worker and fonts load.
- **Dependencies** — `composer audit` and `npm audit` report no known vulnerabilities (re-run them on each update).
- **Secrets** — none in the repo; only `.env.example`. Set `APP_DEBUG=false` in production and `expose_php = Off` in `/etc/php/8.3/fpm/php.ini`. For receipts set `upload_max_filesize = 8M` and `post_max_size = 8M` there too (nginx allows 8 MB).
- **Forgot password** — the emailed link (`FRONTEND_URL/reset-password?token=…`) works once and for 60 minutes; requests are limited to 5/hour per email and 20/hour per IP; the response never reveals whether an email is registered. A reset signs out every device, forgets trusted 2FA devices and emails the owner. Two-step verification is still asked at the next login.
- **Sign-in activity** — every sign-in and every failed password / code on a real account is logged (method, browser + OS, IP; last 200 kept) and listed in *Settings → Account & security*. A sign-in from a browser/device not seen in 180 days notifies the owner in-app, by push and always by email.
- **Receipts** — stored outside the web root on the private disk with random names, served only through the authorized API (never a public URL), `no-store`, `nosniff`; deleted with their transaction. Photos are shrunk to ≤ 1600 px in the browser before upload.
- **Exports** — CSV cells starting with `= + - @` are prefixed so spreadsheets don't run them as formulas.

- **Two-factor authentication** — optional TOTP (Google/Microsoft Authenticator, 1Password) from *Settings → Account & security*: the secret is encrypted, the QR is drawn in the browser (never sent elsewhere), each code works once (no replay), 8 single-use recovery codes are stored hashed, the second login step expires after 5 minutes and is limited to 5 tries/min. Turning it off needs the password and a code.
