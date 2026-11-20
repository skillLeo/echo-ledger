#!/usr/bin/env bash
# Hardens the Live Assist relay VPS (Ubuntu 24.04) and installs its ops checks: deploy/vps/README.md.
# Run as root on the VPS, from a copy of deploy/vps/:   sudo bash harden.sh [--dry-run] [--yes]
# --yes: go on although something else listens on a public port (the firewall will close it).
# Safe to run again: each step checks first and changes only what differs.
#
# It never changes how anyone signs in over SSH: that is ssh-lockdown.sh, run only after the root password is changed
# and key sign-in is proven.
set -euo pipefail

dry=0 yes=0
for arg in "$@"; do
  case "$arg" in
    --dry-run) dry=1 ;;
    --yes) yes=1 ;;
    *) echo "Unknown option: $arg (use --dry-run or --yes)" >&2; exit 2 ;;
  esac
done
here=$(cd "$(dirname "$0")" && pwd)
[ "$(id -u)" -eq 0 ] || { echo "Run as root (sudo bash harden.sh)." >&2; exit 2; }
. /etc/os-release
[ "${ID:-}" = ubuntu ] || { echo "This is for Ubuntu (found ${ID:-unknown})." >&2; exit 2; }

say() { printf '\n== %s\n' "$*"; }
run() { if [ "$dry" = 1 ]; then echo "  (dry run) $*"; else "$@"; fi; }
# put <file> <mode>: writes standard input to the file when it differs, keeping a dated copy of the old one.
put() {
  local file="$1" mode="$2" new
  new=$(mktemp)
  cat >"$new"
  if [ -f "$file" ] && cmp -s "$new" "$file"; then
    rm -f "$new"
    echo "  unchanged $file"
    return 1
  fi
  if [ "$dry" = 1 ]; then
    echo "  (dry run) would write $file"
    rm -f "$new"
    return 0
  fi
  mkdir -p "$(dirname "$file")"
  [ -f "$file" ] && cp -p "$file" "$file.before-echoledger-$(date +%Y%m%d%H%M%S)"
  install -m "$mode" "$new" "$file"
  rm -f "$new"
  echo "  wrote $file"
}

say "Packages"
need=()
for pkg in unattended-upgrades ufw fail2ban curl jq; do
  dpkg -s "$pkg" >/dev/null 2>&1 || need+=("$pkg")
done
if [ ${#need[@]} -gt 0 ]; then
  run apt-get update -q
  run env DEBIAN_FRONTEND=noninteractive apt-get install -y -q "${need[@]}"
else
  echo "  all present"
fi

say "Automatic security updates (no automatic reboot)"
put /etc/apt/apt.conf.d/20auto-upgrades 0644 <<'EOF' || true
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
EOF
put /etc/apt/apt.conf.d/52echoledger-unattended 0644 <<'EOF' || true
// EchoLedger relay VPS (deploy/vps/harden.sh): security updates only, every day.
Unattended-Upgrade::Allowed-Origins {
        "${distro_id}:${distro_codename}-security";
        "${distro_id}ESMApps:${distro_codename}-apps-security";
        "${distro_id}ESM:${distro_codename}-infra-security";
};
Unattended-Upgrade::Remove-Unused-Kernel-Packages "true";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
// A reboot closes every live call: never on its own. echoledger-resources alerts when one is waiting.
Unattended-Upgrade::Automatic-Reboot "false";
EOF
run systemctl enable --now unattended-upgrades.service >/dev/null 2>&1 || true

say "Clock (assist tokens are checked against it)"
run timedatectl set-ntp true || true

say "Firewall: SSH (rate limited), HTTP, HTTPS; everything else closed, the relay's 8787 included"
ssh_port=$(sshd -T 2>/dev/null | awk '$1 == "port" { print $2; exit }')
ssh_port=${ssh_port:-22}
# What listens on a public address besides SSH, 80 and 443: the firewall would close it, so stop and say so.
others=$(ss -H -tlnp 2>/dev/null | awk -v p="$ssh_port" '{ split($4, a, ":"); port = a[length(a)]; addr = substr($4, 1, length($4) - length(port) - 1)
  if (addr !~ /^(127\.|\[::1\]|::1)/ && port != p && port != 80 && port != 443) print "  " $4 "  " $6 }')
if [ -n "$others" ]; then
  echo "  Also listening on a public address (the firewall will close these):"
  echo "$others"
  [ "$yes" = 1 ] || { echo "  Stopped: check these are not needed, then run again with --yes." >&2; exit 3; }
fi
if ufw status | grep -q "Status: active"; then
  echo "  active"
fi
run ufw default deny incoming >/dev/null
run ufw default allow outgoing >/dev/null
# SSH first, so enabling the firewall never cuts the session running this.
run ufw limit "$ssh_port/tcp" comment 'SSH (rate limited)' >/dev/null
if [ -f /etc/ufw/applications.d/nginx ] || ufw app list 2>/dev/null | grep -q "Nginx Full"; then
  run ufw allow 'Nginx Full' >/dev/null
else
  run ufw allow 80/tcp >/dev/null
  run ufw allow 443/tcp >/dev/null
fi
# Anything that opened the relay's port by hand is taken back: it listens on loopback only.
while ufw status numbered 2>/dev/null | grep -q '8787'; do
  num=$(ufw status numbered | grep '8787' | head -1 | sed -E 's/^\[ *([0-9]+)\].*/\1/')
  run ufw --force delete "$num" >/dev/null
  [ "$dry" = 1 ] && break
done
run ufw --force enable >/dev/null
[ "$dry" = 1 ] || ufw status verbose | sed 's/^/  /'

say "fail2ban for SSH"
put /etc/fail2ban/jail.d/echoledger-sshd.conf 0644 <<'EOF' && run systemctl restart fail2ban || true
# EchoLedger relay VPS (deploy/vps/harden.sh): 5 failed sign-ins in 10 minutes ban the address for an hour.
[sshd]
enabled = true
backend = systemd
maxretry = 5
findtime = 10m
bantime = 1h
EOF
run systemctl enable --now fail2ban >/dev/null 2>&1 || true

say "Journal: capped, so logs never fill the disk (the relay's lines hold no content: services/assist-relay/DEPLOY.md)"
put /etc/systemd/journald.conf.d/relay.conf 0644 <<'EOF' && run systemctl restart systemd-journald || true
[Journal]
SystemMaxUse=1G
MaxRetentionSec=14day
EOF

say "Ops checks: relay watchdog (1 min), resources (5 min), settings backup (daily)"
run install -d -m 0755 /usr/local/lib/echoledger-ops
for f in alert.sh resource-check.sh relay-watchdog.sh config-backup.sh; do
  put "/usr/local/lib/echoledger-ops/$f" 0755 <"$here/ops/$f" || true
done
run install -d -m 0755 /etc/echoledger-ops
run install -d -m 0700 /var/lib/echoledger-ops
if [ ! -f /etc/echoledger-ops/ops.env ]; then
  put /etc/echoledger-ops/ops.env 0644 <<'EOF' || true
# EchoLedger relay VPS ops limits (deploy/vps/README.md). Not secret.
CPU_PCT=80
MEM_PCT=80
DISK_PCT=80
DISK_PATHS="/"
FAILS_BEFORE_RESTART=3
BACKUP_KEEP=14
EOF
fi
if [ ! -f /etc/echoledger-ops/alert.env ]; then
  # Where alerts go. Filled in on the VPS by whoever runs ops, never in the repo: deploy/vps/README.md, "Alerts".
  put /etc/echoledger-ops/alert.env 0600 <<'EOF' || true
ALERT_URL=
ALERT_KIND=ntfy
EOF
fi

unit() {
  local name="$1" description="$2" script="$3" calendar="$4"
  put "/etc/systemd/system/$name.service" 0644 <<EOF || true
[Unit]
Description=$description
After=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/local/lib/echoledger-ops/$script
Nice=10
EOF
  put "/etc/systemd/system/$name.timer" 0644 <<EOF || true
[Unit]
Description=$description

[Timer]
OnCalendar=$calendar
Persistent=true
RandomizedDelaySec=${5:-0}

[Install]
WantedBy=timers.target
EOF
}
unit echoledger-relay-watchdog "EchoLedger: restart the relay when it stops answering" relay-watchdog.sh "*-*-* *:*:00"
unit echoledger-resources "EchoLedger: CPU, memory, disk and reboot alerts" resource-check.sh "*-*-* *:00/5:00"
unit echoledger-backup "EchoLedger: daily relay settings backup" config-backup.sh "*-*-* 03:30:00" 15m
run systemctl daemon-reload
for t in echoledger-relay-watchdog echoledger-resources echoledger-backup; do
  run systemctl enable --now "$t.timer" >/dev/null 2>&1
done

say "First run"
run /usr/local/lib/echoledger-ops/config-backup.sh || true
run /usr/local/lib/echoledger-ops/resource-check.sh || true

say "Done. Check everything with: sudo bash $here/check.sh"
