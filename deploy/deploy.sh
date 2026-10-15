#!/usr/bin/env bash
# Pull and release the latest code on the VPS. Run as the deploy user from /var/www/finance.
set -euo pipefail
cd "$(dirname "$0")/.."

git pull --ff-only

cd backend
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan queue:restart          # supervisor restarts the worker with the new code

cd ../frontend
npm ci
npm run build                      # type-checks; fails the deploy on TypeScript errors

sudo chown -R www-data:www-data ../backend/storage ../backend/bootstrap/cache
sudo systemctl reload php8.3-fpm
echo "Deployed $(git rev-parse --short HEAD)"
