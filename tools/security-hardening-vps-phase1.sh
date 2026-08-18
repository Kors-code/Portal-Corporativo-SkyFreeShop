#!/usr/bin/env bash
set -euo pipefail

SSH_PORT="${SSH_PORT:-22}"
APP_DIR="${APP_DIR:-/var/www/skyfree}"
BACKUP_ROOT="/root/security-hardening-backups/phase1-$(date -u +%Y%m%dT%H%M%SZ)"

log() {
  printf '[phase1] %s\n' "$*"
}

backup_file() {
  local path="$1"
  if [ -e "$path" ]; then
    mkdir -p "$BACKUP_ROOT$(dirname "$path")"
    cp -a "$path" "$BACKUP_ROOT$path"
  fi
}

require_file() {
  local path="$1"
  if [ ! -s "$path" ]; then
    log "ERROR: required file is missing or empty: $path"
    exit 1
  fi
}

log "creating backups in $BACKUP_ROOT"
mkdir -p "$BACKUP_ROOT"
backup_file /etc/ssh/sshd_config
backup_file /etc/nginx/nginx.conf
backup_file /etc/fail2ban/jail.d/sshd.local

log "checking root key access before disabling password login"
require_file /root/.ssh/authorized_keys

log "installing security packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get -y install ufw fail2ban unattended-upgrades

log "configuring SSH: keep root key login, disable all password/challenge logins"
mkdir -p /etc/ssh/sshd_config.d
cat >/etc/ssh/sshd_config.d/99-skyfree-phase1-hardening.conf <<'EOF'
PermitRootLogin prohibit-password
PasswordAuthentication no
KbdInteractiveAuthentication no
ChallengeResponseAuthentication no
PubkeyAuthentication yes
MaxAuthTries 3
X11Forwarding no
EOF
sshd -t
systemctl reload ssh || systemctl reload sshd

log "rotating root password to a random locked-away value"
root_password="$(openssl rand -base64 48)"
printf 'root:%s\n' "$root_password" | chpasswd
unset root_password

log "configuring firewall for SSH, HTTP and HTTPS"
ufw allow "$SSH_PORT/tcp"
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

log "configuring fail2ban for SSH"
mkdir -p /etc/fail2ban/jail.d
cat >/etc/fail2ban/jail.d/sshd.local <<EOF
[sshd]
enabled = true
port = $SSH_PORT
maxretry = 5
findtime = 10m
bantime = 1h
backend = systemd
EOF
systemctl enable --now fail2ban
systemctl restart fail2ban

log "fixing .env permissions"
for base in "$APP_DIR" /var/www/skyfreeBackup "$APP_DIR/.codex-backups"; do
  if [ -e "$base" ]; then
    find "$base" -type f -name '.env' -exec chown root:www-data {} \; -exec chmod 640 {} \;
  fi
done

log "limiting backup directories to root/www-data traversal"
for dir in /var/www/skyfreeBackup "$APP_DIR/.codex-backups"; do
  if [ -d "$dir" ]; then
    chown root:www-data "$dir" || true
    chmod 750 "$dir" || true
  fi
done

log "disabling obsolete TLS protocols in nginx.conf"
if [ -f /etc/nginx/nginx.conf ]; then
  perl -0pi -e 's/ssl_protocols\s+[^;]+;/ssl_protocols TLSv1.2 TLSv1.3;/g' /etc/nginx/nginx.conf
fi
nginx -t
systemctl reload nginx

log "verification"
printf '\n--- sshd effective settings ---\n'
sshd -T | grep -E '^(permitrootlogin|passwordauthentication|kbdinteractiveauthentication|pubkeyauthentication|maxauthtries|x11forwarding) '

printf '\n--- ufw ---\n'
ufw status verbose

printf '\n--- fail2ban sshd ---\n'
fail2ban-client status sshd || true

printf '\n--- tls protocols ---\n'
grep -R --line-number 'ssl_protocols' /etc/nginx/nginx.conf /etc/nginx/conf.d /etc/nginx/sites-enabled 2>/dev/null || true

printf '\n--- env permissions ---\n'
for base in "$APP_DIR" /var/www/skyfreeBackup "$APP_DIR/.codex-backups"; do
  if [ -e "$base" ]; then
    find "$base" -type f -name '.env' -printf '%m %u:%g %p\n'
  fi
done

printf '\n--- security updates still pending ---\n'
apt list --upgradable 2>/dev/null | grep -i security || true

printf '\n--- reboot flag ---\n'
if [ -f /var/run/reboot-required ]; then
  cat /var/run/reboot-required
else
  echo 'no reboot required flag'
fi

log "completed"
