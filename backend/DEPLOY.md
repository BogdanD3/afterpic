# Deployment (Debian 12/13)

Target: Nginx + PHP-FPM 8.4 + MariaDB + Supervisor (queue worker). The app lives in `/var/www/photo-gallery/current/backend` (the Laravel folder inside the release).

## 1. Packages

```bash
sudo apt update
sudo apt install -y nginx mariadb-server supervisor git unzip certbot python3-certbot-nginx \
  php8.4-fpm php8.4-cli php8.4-gd php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl \
  php8.4-zip php8.4-intl php8.4-exif composer
```

On Debian 12, PHP 8.4 comes from the Sury repository (`packages.sury.org/php`). Debian 13 ships 8.4 directly.

Check that GD has WebP and EXIF is loaded:

```bash
php -m | grep -E '^(gd|exif|fileinfo|pdo_mysql)$'
php -r 'var_dump(gd_info()["WebP Support"]);'   # must be bool(true)
```

## 2. PHP limits

Copy `deploy/php-photo-gallery.ini` to `/etc/php/8.4/fpm/conf.d/99-photo-gallery.ini` and to `/etc/php/8.4/cli/conf.d/99-photo-gallery.ini`, then:

```bash
sudo systemctl restart php8.4-fpm
```

`upload_max_filesize` and `post_max_size` must stay above the 50 MB app limit.

## 3. Database

```sql
CREATE DATABASE photo_gallery CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'photo_gallery'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON photo_gallery.* TO 'photo_gallery'@'localhost';
FLUSH PRIVILEGES;
```

Run `sudo mysql_secure_installation` once. The app must never connect as root.

## 4. Code and permissions

```bash
sudo mkdir -p /var/www/photo-gallery/releases /var/log/photo-gallery
sudo git clone <repo-url> /var/www/photo-gallery/releases/first
sudo ln -sfn /var/www/photo-gallery/releases/first /var/www/photo-gallery/current
cd /var/www/photo-gallery/current/backend   # the Laravel app folder
sudo composer install --no-dev --optimize-autoloader
sudo cp deploy/.env.production.example .env
sudo nano .env                              # fill DB_PASSWORD, APP_URL, FRONTEND_URL
sudo php artisan key:generate --force
sudo chown -R www-data:www-data storage bootstrap/cache /var/log/photo-gallery
sudo chmod 640 .env
sudo chown root:www-data .env
```

`.env` holds secrets and must not be world-readable. `APP_KEY` is generated on the server and never committed.

## 5. First deploy

```bash
php artisan migrate --force
php artisan storage:link
php artisan app:check-photo-environment
php artisan config:cache
php artisan route:cache
php artisan event:cache
```

## 6. Nginx and HTTPS

```bash
sudo cp deploy/nginx.conf /etc/nginx/sites-available/photo-gallery
sudo ln -s /etc/nginx/sites-available/photo-gallery /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d example.com
sudo certbot renew --dry-run
```

Replace `example.com` in the config with the real domain.

## 7. Queue worker (Supervisor)

```bash
sudo cp deploy/supervisor-photo-worker.conf /etc/supervisor/conf.d/photo-gallery-worker.conf
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status
```

The worker uses `--memory=1024`. Photos up to 60 MP need roughly 400–500 MB while GD decodes and resizes them. Raise the limit on servers with less RAM only after checking that the largest expected photo still fits.

`DB_QUEUE_RETRY_AFTER` (180) must stay above the job timeout (120).

## 8. Scheduler

```bash
sudo crontab -u www-data -e
```

Add:

```
* * * * * cd /var/www/photo-gallery/current/backend && php artisan schedule:run >> /dev/null 2>&1
```

The schedule marks photos stuck in `pending` for over an hour as `failed`, and prunes failed jobs older than 7 days.

## 9. Backups

```bash
sudo chmod +x /var/www/photo-gallery/current/backend/deploy/backup.sh
sudo crontab -e
```

Add: `0 3 * * * /var/www/photo-gallery/current/backend/deploy/backup.sh`

Restore test (do it once before relying on backups):

```bash
gunzip -c /var/backups/photo-gallery/db_<stamp>.sql.gz | mysql -u photo_gallery -p photo_gallery_restore_test
```

## 10. Firewall

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
```

## 11. Deploying an update

```bash
cd /var/www/photo-gallery/current/backend
sudo git pull
sudo composer install --no-dev --optimize-autoloader
sudo php artisan migrate --force
sudo php artisan config:cache && sudo php artisan route:cache
sudo php artisan queue:restart
```

`queue:restart` makes running workers pick up the new code. Rollback: point the `current` symlink at the previous release and repeat the steps above.

## 12. Monitoring

- Health: `GET /up` returns 200 when the app boots.
- Disk: originals can be up to 50 MB each. Watch `df -h` and `/var/www/photo-gallery/current/backend/storage/app/private`.
- Logs: `/var/log/photo-gallery/worker.log` and Laravel logs in `storage/logs/`.
- Failed jobs: `php artisan queue:failed`.

## 13. Frontend

The React app lives in `../frontend`. It is a static build, so it can be hosted on any static host (or a separate Nginx server block).

```bash
cd /var/www/photo-gallery/current/frontend
npm ci
VITE_API_URL=https://example.com npm run build
```

Serve `dist/` as static files. Set `FRONTEND_URL` in the backend `.env` to the frontend origin, otherwise CORS blocks the API calls.
