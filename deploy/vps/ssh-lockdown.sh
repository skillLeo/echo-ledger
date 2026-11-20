#!/usr/bin/env bash
# SSH keys only, root sign-in off, on the relay VPS (deploy/vps/README.md, "SSH lockdown").
# Only after the root password has been changed. Two runs, so nobody is locked out:
#
#   1. sudo bash ssh-lockdown.sh --admin elops
#      Makes the admin account (sudo, signs in with the keys root already accepts) and stops. From the Mac, in a NEW
#      terminal, prove it:   ssh -i <key> elops@<vps> sudo -n true && echo OK
#   2. sudo bash ssh-lockdown.sh --admin elops --i-tested-key-login
#      Turns off root sign-in and passwords, checks the settings with sshd -t, and reloads SSH. Open sessions stay.
#
# Undo (from Hostinger's browser terminal if SSH no longer lets you in):
#   rm /etc/ssh/sshd_config.d/00-echoledger.conf && systemctl reload ssh
set -euo pipefail

admin="" tested=0
while [ $# -gt 0 ]; do
  case "$1" in
    --admin) admin="$2"; shift 2 ;;
    --i-tested-key-login) tested=1; shift ;;
    *) echo "Unknown option: $1" >&2; exit 2 ;;
  esac
done
[ "$(id -u)" -eq 0 ] || { echo "Run as root." >&2; exit 2; }
[[ "$admin" =~ ^[a-z][a-z0-9_-]{1,30}$ ]] || { echo "Name the admin account: --admin <name> (lower case)." >&2; exit 2; }
[ "$admin" != root ] || { echo "The admin account cannot be root." >&2; exit 2; }

keys="/home/$admin/.ssh/authorized_keys"
if ! id "$admin" >/dev/null 2>&1; then
  [ -s /root/.ssh/authorized_keys ] || { echo "root has no SSH key to copy (/root/.ssh/authorized_keys): add the Mac's public key first." >&2; exit 3; }
  adduser --disabled-password --gecos "EchoLedger ops" "$admin" >/dev/null
  echo "  made the account $admin (no password: it signs in with a key)"
fi
usermod -aG sudo "$admin"
if [ ! -s "$keys" ]; then
  install -d -m 0700 -o "$admin" -g "$admin" "/home/$admin/.ssh"
  install -m 0600 -o "$admin" -g "$admin" /root/.ssh/authorized_keys "$keys"
  echo "  copied root's authorized keys to $admin"
fi
sudoers=/etc/sudoers.d/90-echoledger-admin
if [ ! -f "$sudoers" ]; then
  # The account has no password (key sign-in only), so sudo cannot ask for one.
  tmp=$(mktemp)
  echo "$admin ALL=(ALL) NOPASSWD:ALL" >"$tmp"
  visudo -cf "$tmp" >/dev/null
  install -m 0440 "$tmp" "$sudoers"
  rm -f "$tmp"
  echo "  $admin may use sudo"
fi

if [ "$tested" = 0 ]; then
  cat <<EOF

Step 1 done. Before going on, from the Mac in a NEW terminal (keep this one open):
  ssh $admin@<vps> sudo -n true && echo OK
When it prints OK, run:
  sudo bash $0 --admin $admin --i-tested-key-login
EOF
  exit 0
fi

[ -s "$keys" ] || { echo "$admin has no authorized keys: stopping." >&2; exit 3; }
conf=/etc/ssh/sshd_config.d/00-echoledger.conf
# 00- so these come first: sshd keeps the first value it reads (cloud-init's 50-cloud-init.conf sets passwords on).
cat >"$conf.new" <<'EOF'
# EchoLedger relay VPS (deploy/vps/ssh-lockdown.sh): keys only, no root.
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
AuthenticationMethods publickey
PermitEmptyPasswords no
MaxAuthTries 3
LoginGraceTime 30
X11Forwarding no
EOF
# Put back exactly what was there before (a file from an earlier run, or none) when anything below fails.
[ -f "$conf" ] && cp -p "$conf" "$conf.prev"
undo() { if [ -f "$conf.prev" ]; then mv "$conf.prev" "$conf"; else rm -f "$conf"; fi; }
mv "$conf.new" "$conf"
chmod 0644 "$conf"
if ! sshd -t; then
  undo
  echo "sshd refused the settings; nothing changed." >&2
  exit 4
fi
effective=$(sshd -T 2>/dev/null)
for want in "permitrootlogin no" "passwordauthentication no" "kbdinteractiveauthentication no"; do
  grep -qx "$want" <<<"$effective" || { undo; echo "Another setting wins over '$want' (check /etc/ssh/sshd_config.d/); nothing changed." >&2; exit 4; }
done
rm -f "$conf.prev"
systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true
echo "  SSH: keys only, root sign-in off. Open sessions are untouched."
echo "  Check from the Mac:  ssh root@<vps>  must now be refused;  ssh $admin@<vps>  must work."
