#!/usr/bin/env bash
set -euo pipefail

SSH_PORT="${SSH_PORT:-22}"
MYSQL_USER="${MYSQL_USER:-dev}"
BACKUP_ROOT="/root/security-hardening-backups/phase2-$(date -u +%Y%m%dT%H%M%SZ)"

CF_IPV4=(
  173.245.48.0/20
  103.21.244.0/22
  103.22.200.0/22
  103.31.4.0/22
  141.101.64.0/18
  108.162.192.0/18
  190.93.240.0/20
  188.114.96.0/20
  197.234.240.0/22
  198.41.128.0/17
  162.158.0.0/15
  104.16.0.0/13
  104.24.0.0/14
  172.64.0.0/13
  131.0.72.0/22
)

CF_IPV6=(
  2400:cb00::/32
  2606:4700::/32
  2803:f800::/32
  2405:b500::/32
  2405:8100::/32
  2a06:98c0::/29
  2c0f:f248::/32
)

log() {
  printf '[phase2] %s\n' "$*"
}

backup_file() {
  local path="$1"
  if [ -e "$path" ]; then
    mkdir -p "$BACKUP_ROOT$(dirname "$path")"
    cp -a "$path" "$BACKUP_ROOT$path"
  fi
}

mysql_exec() {
  mysql --batch --raw --skip-column-names "$@"
}

log "creating backups in $BACKUP_ROOT"
mkdir -p "$BACKUP_ROOT"
backup_file /etc/nginx/nginx.conf
backup_file /etc/php/8.3/fpm/php.ini
backup_file /etc/php/8.3/cli/php.ini
ufw status numbered >"$BACKUP_ROOT/ufw-status-before.txt" || true
mysql -e "SHOW GRANTS FOR '$MYSQL_USER'@'%';" >"$BACKUP_ROOT/mysql-${MYSQL_USER}-percent-grants.txt" 2>/dev/null || true

log "restricting HTTP/HTTPS origin access to Cloudflare IP ranges"
ufw allow "$SSH_PORT/tcp"
ufw --force delete allow 80/tcp >/dev/null 2>&1 || true
ufw --force delete allow 443/tcp >/dev/null 2>&1 || true
for cidr in "${CF_IPV4[@]}" "${CF_IPV6[@]}"; do
  ufw allow proto tcp from "$cidr" to any port 80 comment 'Cloudflare HTTP'
  ufw allow proto tcp from "$cidr" to any port 443 comment 'Cloudflare HTTPS'
done
ufw default deny incoming
ufw --force enable

log "disabling Nginx version tokens"
cat >/etc/nginx/conf.d/00-skyfree-security.conf <<'EOF'
server_tokens off;
EOF
nginx -t
systemctl reload nginx

log "disabling PHP X-Powered-By header"
mkdir -p /etc/php/8.3/fpm/conf.d /etc/php/8.3/cli/conf.d
cat >/etc/php/8.3/fpm/conf.d/99-skyfree-security.ini <<'EOF'
expose_php = Off
EOF
cat >/etc/php/8.3/cli/conf.d/99-skyfree-security.ini <<'EOF'
expose_php = Off
EOF
systemctl restart php8.3-fpm

log "restricting MySQL user '$MYSQL_USER' from '%' to localhost only"
if mysql_exec -e "SELECT COUNT(*) FROM mysql.user WHERE User='$MYSQL_USER' AND Host='%';" | grep -qx '1'; then
  plugin="$(mysql_exec -e "SELECT plugin FROM mysql.user WHERE User='$MYSQL_USER' AND Host='%';")"
  auth_string="$(mysql_exec -e "SELECT authentication_string FROM mysql.user WHERE User='$MYSQL_USER' AND Host='%';")"
  grants_file="$(mktemp)"
  mysql --batch --raw --skip-column-names -e "SHOW GRANTS FOR '$MYSQL_USER'@'%';" >"$grants_file"

  for host in localhost 127.0.0.1; do
    mysql -e "CREATE USER IF NOT EXISTS '$MYSQL_USER'@'$host' IDENTIFIED WITH \`$plugin\` AS '$auth_string';"
    while IFS= read -r grant_line; do
      grant_line="${grant_line/\`$MYSQL_USER\`@\`%\`/\`$MYSQL_USER\`@\`$host\`}"
      mysql -e "$grant_line"
    done <"$grants_file"
  done

  mysql -e "DROP USER '$MYSQL_USER'@'%'; FLUSH PRIVILEGES;"
  rm -f "$grants_file"
else
  log "MySQL user '$MYSQL_USER'@'%' not found; skipping drop"
fi

log "verification"
printf '\n--- UFW ---\n'
ufw status verbose

printf '\n--- Nginx server_tokens ---\n'
nginx -T 2>/dev/null | grep -n 'server_tokens' || true

printf '\n--- PHP expose_php ---\n'
php -i | grep -i '^expose_php'

printf '\n--- MySQL dev hosts ---\n'
mysql -e "SELECT User, Host FROM mysql.user WHERE User='$MYSQL_USER' ORDER BY Host;"

printf '\n--- HTTP headers local ---\n'
curl -I --max-time 10 http://127.0.0.1/ 2>/dev/null | sed -n '1,20p' || true

log "completed"
