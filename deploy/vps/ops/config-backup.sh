#!/usr/bin/env bash
# Daily (echoledger-backup.timer): the relay VPS's settings in one dated archive, BACKUP_KEEP days kept (14).
# The relay keeps no recordings, words or database, so its settings are all there is to back up.
#
# In:  the relay's environment file and config, its systemd unit, nginx's sites, journald and ops settings, the firewall
#      rules, certbot's renewal settings, and the current relay binary (a fast way back).
# Out: anything secret: the encrypted relay secret and the host key that decrypts it, TLS private keys, SSH keys,
#      alert.env. A restore issues a new relay secret in Admin -> API Credentials and a new certificate with certbot
#      (deploy/vps/README.md, "Restore").
set -euo pipefail
here=$(dirname "$(readlink -f "$0" 2>/dev/null || echo "$0")")
# shellcheck source=alert.sh
. "$here/alert.sh"

DEST=${BACKUP_DIR:-/var/backups/echoledger-relay}
KEEP=${BACKUP_KEEP:-14}
ROOT=${BACKUP_ROOT:-/}
umask 077
mkdir -p "$DEST"
chmod 700 "$DEST"

items=(
  etc/assist-relay
  etc/systemd/system/assist-relay.service
  etc/systemd/system/assist-relay.service.d
  etc/nginx/nginx.conf
  etc/nginx/sites-available
  etc/nginx/conf.d
  etc/systemd/journald.conf.d
  etc/echoledger-ops/ops.env
  etc/ufw/user.rules
  etc/ufw/user6.rules
  etc/fail2ban/jail.d
  etc/apt/apt.conf.d/52echoledger-unattended
  etc/ssh/sshd_config.d
  etc/letsencrypt/renewal
)
present=()
for item in "${items[@]}"; do
  [ -e "$ROOT$item" ] && present+=("$item")
done
current=$(readlink -f "${ROOT}opt/assist-relay/current" 2>/dev/null || true)
[ -n "$current" ] && [ -e "$current" ] && present+=("${current#"$ROOT"}")

stamp=$(date -u +%Y%m%dT%H%M%SZ)
archive="$DEST/relay-config-$stamp.tar.gz"
if ! tar -C "$ROOT" -czf "$archive.part" --exclude='*.key' --exclude='*.pem' --exclude='alert.env' "${present[@]}" 2>/dev/null; then
  rm -f "$archive.part"
  alert backup firing "daily settings backup failed"
  exit 1
fi
mv "$archive.part" "$archive"
(cd "$DEST" && sha256sum "$(basename "$archive")" >"$(basename "$archive").sha256" 2>/dev/null || shasum -a 256 "$(basename "$archive")" >"$(basename "$archive").sha256")

# Keep the newest KEEP archives.
ls -1t "$DEST"/relay-config-*.tar.gz 2>/dev/null | tail -n +"$((KEEP + 1))" | while read -r old; do
  rm -f "$old" "$old.sha256"
done
alert backup ok "daily settings backup made"
ops_log "backup $(basename "$archive") ($(du -h "$archive" | cut -f1), ${#present[@]} items)"
