#!/usr/bin/env bash
# SSH keys only, root sign-in off, on the relay VPS (deploy/vps/README.md, "SSH lockdown").
# Only after the root password has been changed. Two runs, so nobody is locked out:
#
#   Keep one interactive root shell open on the VPS from start to end (ssh relay-vps, then run these in it): if
#   anything goes wrong, that shell is the way to put it right.
#   1. bash ssh-lockdown.sh --admin elops
#      Makes the admin account (sudo, signs in with the keys root already accepts) and stops. From the Mac, in a NEW
#      terminal, prove it:   ssh -o IdentitiesOnly=yes -i <key> elops@<vps> 'sudo -n true && echo OK'
#   2. bash ssh-lockdown.sh --admin elops --i-tested-key-login
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
# The account has no password (key sign-in only), so sudo cannot ask for one. Written again when it names another account.
want="$admin ALL=(ALL) NOPASSWD:ALL"
if [ "$(cat "$sudoers" 2>/dev/null)" != "$want" ]; then
  tmp=$(mktemp)
  echo "$want" >"$tmp"
  visudo -cf "$tmp" >/dev/null
  install -m 0440 "$tmp" "$sudoers"
  rm -f "$tmp"
  echo "  $admin may use sudo"
fi

# While the key sign-in is being tested, the address this runs from is not banned for a mistyped key (which would cut
# this very session): fail2ban's running state only, never its files, and taken back at the end of step 2. A restart
# of fail2ban forgets it too.
conn=${SSH_CONNECTION:-}
tester=${conn%% *}
[[ "$conn" != "" && "$tester" =~ ^[0-9a-fA-F:.]+$ ]] || tester=""

if [ "$tested" = 0 ]; then
  if [ -n "$tester" ] && fail2ban-client status sshd >/dev/null 2>&1; then
    fail2ban-client set sshd addignoreip "$tester" >/dev/null && echo "  $tester is not banned while you test (until step 2 or a fail2ban restart)"
  fi
  cat <<EOF

Step 1 done. Before going on, from the Mac in a NEW terminal (keep this one open):
  ssh -o IdentitiesOnly=yes -i <key> $admin@<vps> 'sudo -n true && echo OK'
When it prints OK, run in this same shell:
  bash $0 --admin $admin --i-tested-key-login
EOF
  exit 0
fi

[ -s "$keys" ] || { echo "$admin has no authorized keys: stopping." >&2; exit 3; }
# Without root sign-in, the admin's sudo is the only way to run anything as root: prove it before changing SSH.
su -s /bin/sh "$admin" -c 'sudo -n true' >/dev/null 2>&1 || { echo "$admin cannot use sudo without a password: stopping before any SSH change." >&2; exit 3; }
conf=/etc/ssh/sshd_config.d/00-echoledger.conf
# A copy left by a run that was killed half way: someone must look before anything else changes.
[ ! -e "$conf.prev" ] || { echo "$conf.prev exists (a run stopped half way): compare it with $conf, put back the right one, delete the .prev, run again." >&2; exit 3; }
# Put back exactly what was there before (a file from an earlier run, or none) on any failure from here to the end.
[ -f "$conf" ] && cp -p "$conf" "$conf.prev"
undo() {
  if [ -f "$conf.prev" ]; then mv "$conf.prev" "$conf"; else rm -f "$conf"; fi
  rm -f "$conf.new"
}
failed() { undo; echo "$1; nothing changed." >&2; exit 4; }
trap 'failed "Stopped by an error"' ERR
trap 'failed "Stopped (interrupted)"' INT TERM HUP
# 00- so these come first: sshd keeps the first value it reads (cloud-init's 50-cloud-init.conf sets passwords on).
# MaxAuthTries stays at sshd's 6: an agent offering a few keys before the right one must still get in.
cat >"$conf.new" <<'EOF'
# EchoLedger relay VPS (deploy/vps/ssh-lockdown.sh): keys only, no root.
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
AuthenticationMethods publickey
PermitEmptyPasswords no
LoginGraceTime 30
X11Forwarding no
EOF
mv "$conf.new" "$conf"
chmod 0644 "$conf"
sshd -t 2>/dev/null || failed "sshd refused the settings"
# What sshd will really do, Match blocks included: for root and for a password from anywhere, and for the admin.
as_root=$(sshd -T -C user=root,host=lockdown.invalid,addr=203.0.113.9 2>/dev/null) || failed "sshd -T did not answer for root"
as_admin=$(sshd -T -C "user=$admin,host=lockdown.invalid,addr=203.0.113.9" 2>/dev/null) || failed "sshd -T did not answer for $admin"
for want in "permitrootlogin no" "passwordauthentication no" "kbdinteractiveauthentication no"; do
  grep -qx "$want" <<<"$as_root" || failed "Another setting (or a Match block) wins over '$want' for root (check /etc/ssh/sshd_config and sshd_config.d/)"
  grep -qx "$want" <<<"$as_admin" || failed "Another setting (or a Match block) wins over '$want' for $admin"
done
grep -qx "pubkeyauthentication yes" <<<"$as_admin" || failed "Key sign-in would be off for $admin"
grep -Eqx "authenticationmethods (publickey|any)" <<<"$as_admin" || failed "$admin would need more than a key to sign in"
if ! { systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null; }; then
  failed "SSH did not reload"
fi
trap - ERR INT TERM HUP
rm -f "$conf.prev"
if [ -n "$tester" ] && fail2ban-client status sshd >/dev/null 2>&1; then
  fail2ban-client set sshd delignoreip "$tester" >/dev/null 2>&1 || true
fi
echo "  SSH: keys only, root sign-in off. Open sessions are untouched."
echo "  Check from the Mac, in a new terminal (keep this one open):"
echo "    ssh -o IdentitiesOnly=yes -i <key> $admin@<vps> 'sudo -n true && echo OK'   must print OK"
echo "    ssh root@<vps>                                                                must be refused"
