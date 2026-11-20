#!/usr/bin/env bash
# On the Mac: copies the relay VPS's newest settings backup into ~/echoledger-backups/relay/ and checks its sha256.
#   deploy/vps/pull-backup.sh <ssh host alias>
# The account needs sudo without a password (the admin account from ssh-lockdown.sh, or root before it).
set -euo pipefail
host="${1:?usage: pull-backup.sh <ssh host alias>}"
dest="$HOME/echoledger-backups/relay"
mkdir -p "$dest"
chmod 700 "$dest"
name=$(ssh "$host" 'sudo -n sh -c "ls -1t /var/backups/echoledger-relay/relay-config-*.tar.gz | head -1"' | xargs basename)
[ -n "$name" ] || { echo "No backup on $host." >&2; exit 1; }
ssh "$host" "sudo -n cat /var/backups/echoledger-relay/$name" >"$dest/$name.part"
ssh "$host" "sudo -n cat /var/backups/echoledger-relay/$name.sha256" >"$dest/$name.sha256"
mv "$dest/$name.part" "$dest/$name"
(cd "$dest" && shasum -a 256 -c "$name.sha256")
chmod 600 "$dest/$name" "$dest/$name.sha256"
# Keep the newest 30 on the Mac.
ls -1t "$dest"/relay-config-*.tar.gz | tail -n +31 | while read -r old; do rm -f "$old" "$old.sha256"; done
echo "Saved $dest/$name"
