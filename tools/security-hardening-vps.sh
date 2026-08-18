#!/usr/bin/env bash
set -euo pipefail

ADMIN_USER="${ADMIN_USER:-skyadmin}"
SSH_PORT="${SSH_PORT:-22}"
APP_DIR="${APP_DIR:-/var/www/skyfree}"
BACKUP_ROOT="/root/security-hardening-backups/$(date -u +%Y%m%dT%H%M%SZ)"

log() {
  printf '[hardening] %s\n' "$*"
}

backup_file() {
  local path="$1"
  if [ -e "$path" ]; then
    mkdir -p "$BACKUP_ROOT$(dirname "$path")"
    cp -a "$path" "$BACKUP_ROOT$path"
  fi
}

log "creating backup directory: $BACKUP_ROOT"
mkdir -p "$BACKUP_ROOT"

log "ensuring admin user exists: $ADMIN_USER"
if ! id "$ADMIN_USER" >/dev/null 2>&1; then
  useradd -m -s /bin/bash -G sudo "$ADMIN_USER"
fi

install -d -m 700 -o "$ADMIN_USER" -g "$ADMIN_USER" "/home/$ADMIN_USER/.ssh"
if [ -s /root/.ssh/authorized_keys ]; then
  cp /root/.ssh/authorized_keys "/home/$ADMIN_USER/.ssh/authorized_keys"
  chown "$ADMIN_USER:$ADMIN_USER" "/home/$ADMIN_USER/.ssh/authorized_keys"
  chmod 600 "/home/$ADMIN_USER/.ssh/authorized_keys"
else
  log "ERROR: /root/.ssh/authorized_keys is empty or missing; refusing to disable root/password SSH"
  exit 1
fi

cat >"/etc/sudoers.d/90-$ADMIN_USER" <<EOF
$ADMIN_USER ALL=(ALL) NOPASSWD:ALL
EOF
chmod 440 "/etc/sudoers.d/90-$ADMIN_USER"
visudo -cf "/etc/sudoers.d/90-$ADMIN_USER" >/dev/null

log "installing security updates and packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get -y upgrade
apt-get -y install ufw fail2ban unattended-upgrades

log "configuring SSH hardening"
backup_file /etc/ssh/sshd_config
mkdir -p /etc/ssh/sshd_config.d
cat >/etc/ssh/sshd_config.d/99-skyfree-hardening.conf <<'EOF'
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
ChallengeResponseAuthentication no
PubkeyAuthentication yes
MaxAuthTries 3
X11Forwarding no
EOF
sshd -t
systemctl reload ssh || systemctl reload sshd

log "locking root password"
passwd -l root >/dev/null || true

log "configuring firewall"
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

for dir in /var/www/skyfreeBackup "$APP_DIR/.codex-backups"; do
  if [ -d "$dir" ]; then
    chown root:www-data "$dir" || true
    chmod 750 "$dir" || true
  fi
done

log "disabling obsolete TLS protocols in nginx"
if [ -f /etc/nginx/nginx.conf ]; then
  backup_file /etc/nginx/nginx.conf
  perl -0pi -e 's/ssl_protocols\s+[^;]+;/ssl_protocols TLSv1.2 TLSv1.3;/g' /etc/nginx/nginx.conf
fi

nginx -t
systemctl reload nginx

log "running verification"
printf '\n--- SSH effective settings ---\n'
sshd -T | grep -E '^(permitrootlogin|passwordauthentication|kbdinteractiveauthentication|pubkeyauthentication|maxauthtries|x11forwarding) '

printf '\n--- UFW status ---\n'
ufw status verbose

printf '\n--- fail2ban status ---\n'
fail2ban-client status sshd || true

printf '\n--- nginx TLS protocols ---\n'
grep -R --line-number 'ssl_protocols' /etc/nginx/nginx.conf /etc/nginx/conf.d /etc/nginx/sites-enabled 2>/dev/null || true

printf '\n--- .env permissions ---\n'
for base in "$APP_DIR" /var/www/skyfreeBackup "$APP_DIR/.codex-backups"; do
  if [ -e "$base" ]; then
    find "$base" -type f -name '.env' -printf '%m %u:%g %p\n'
  fi
done

printf '\n--- pending reboot ---\n'
if [ -f /var/run/reboot-required ]; then
  cat /var/run/reboot-required
else
  echo 'no reboot required flag'
fi

log "completed"
