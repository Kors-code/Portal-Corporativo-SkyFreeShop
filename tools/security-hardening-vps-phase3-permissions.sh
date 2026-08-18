#!/usr/bin/env bash
set -euo pipefail

APP="${APP:-/var/www/skyfree}"
BACKUP="/root/security-hardening-backups/perms-$(date -u +%Y%m%dT%H%M%SZ)"

run_as_www_data() {
  if command -v runuser >/dev/null 2>&1; then
    runuser -u www-data -- "$@"
  else
    sudo -u www-data "$@"
  fi
}

printf '%s\n' "[perms] backing up permission report to $BACKUP"
mkdir -p "$BACKUP"

printf '%s\n' '--- before world-writable app paths ---'
find "$APP" -xdev \( -type f -perm /002 -o -type d -perm /002 \) -printf '%m %u:%g %p\n' \
  | sort \
  | tee "$BACKUP/world-writable-before.txt"

printf '%s\n' '--- before monarx-agent.conf ---'
if [ -e /etc/monarx-agent.conf ]; then
  stat -c '%a %U:%G %n' /etc/monarx-agent.conf | tee "$BACKUP/monarx-before.txt"
fi

printf '%s\n' '[perms] applying app ownership and permissions'
chown -R root:www-data "$APP"
find "$APP" -xdev -type d -exec chmod 750 {} +
find "$APP" -xdev -type f -exec chmod 640 {} +

printf '%s\n' '[perms] restoring Laravel writable paths'
for dir in "$APP/storage" "$APP/bootstrap/cache"; do
  if [ -d "$dir" ]; then
    chown -R www-data:www-data "$dir"
    find "$dir" -xdev -type d -exec chmod 770 {} +
    find "$dir" -xdev -type f -exec chmod 660 {} +
  fi
done

for file in "$APP/artisan" "$APP/composer.phar"; do
  if [ -f "$file" ]; then
    chmod 750 "$file"
  fi
done

printf '%s\n' '[perms] hardening monarx-agent.conf'
if [ -e /etc/monarx-agent.conf ]; then
  chown root:root /etc/monarx-agent.conf
  chmod 600 /etc/monarx-agent.conf
fi

printf '%s\n' '[perms] clearing Laravel caches as www-data'
if [ -f "$APP/artisan" ]; then
  cd "$APP"
  run_as_www_data php artisan config:clear
  run_as_www_data php artisan route:clear
  run_as_www_data php artisan view:clear
fi

printf '%s\n' '[perms] restarting php-fpm and reloading nginx'
systemctl restart php8.3-fpm
nginx -t
systemctl reload nginx

printf '%s\n' '--- after world-writable app paths ---'
find "$APP" -xdev \( -type f -perm /002 -o -type d -perm /002 \) -printf '%m %u:%g %p\n' | sort

printf '%s\n' '--- key app permissions ---'
stat -c '%a %U:%G %n' "$APP" "$APP/.env" "$APP/artisan" "$APP/storage" "$APP/bootstrap/cache" 2>/dev/null || true

printf '%s\n' '--- monarx-agent.conf after ---'
if [ -e /etc/monarx-agent.conf ]; then
  stat -c '%a %U:%G %n' /etc/monarx-agent.conf
fi

printf '%s\n' '--- services ---'
systemctl is-active nginx php8.3-fpm mysql fail2ban ufw

printf '%s\n' '--- local HTTP ---'
curl -I --max-time 10 http://127.0.0.1/ 2>/dev/null | sed -n '1,10p' || true

printf '%s\n' '[perms] completed'
