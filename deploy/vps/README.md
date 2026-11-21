# The relay VPS: hardening and ops checks

Scripts for the Live Assist relay's VPS (Hostinger KVM 1, Ubuntu 24.04), next to the relay's own install steps in
services/assist-relay/DEPLOY.md. The relay keeps no recordings, words or database (sessions live in memory), so what
needs care here is the machine itself: updates, the firewall, SSH, logs, and knowing quickly when something is wrong.

| Script | Where | What |
|---|---|---|
| `harden.sh` | VPS, as root | Security updates, firewall, fail2ban, clock, journal cap, and the three ops checks below. Safe to run again. |
| `check.sh` | VPS, as root | Read-only: PASS / WARN / FAIL for everything in this file. Exit 1 when anything fails. |
| `ssh-lockdown.sh` | VPS, as root | Keys only, root sign-in off. **Only after the root password has been changed.** Two runs. |
| `pull-backup.sh` | the Mac | Copies the newest settings backup off the VPS and checks its sha256. |
| `ops/*.sh` | installed by `harden.sh` | The checks the timers run. |
| `tests/test-ops.sh`, `tests/container.sh` | the Mac | The checks with fixtures; everything above on a throwaway Ubuntu 24.04 with systemd (Docker). |

## Running them

From the Mac, with the VPS's SSH alias (here `relay-vps`). Before the SSH lockdown, as root:

```
scp -r deploy/vps relay-vps:/root/echoledger-vps
ssh relay-vps 'bash /root/echoledger-vps/check.sh'          # before: what is there now
ssh relay-vps 'bash /root/echoledger-vps/harden.sh --dry-run'
ssh relay-vps 'bash /root/echoledger-vps/harden.sh'
ssh relay-vps 'bash /root/echoledger-vps/check.sh'          # after: expect only the SSH and alert warnings
```

After it, root cannot sign in: copy the folder to the admin account and use sudo:

```
scp -r deploy/vps elops@<vps>:~/echoledger-vps
ssh elops@<vps> 'sudo bash ~/echoledger-vps/check.sh --expect-lockdown'
```

`harden.sh` stops (exit 3) if something besides SSH, 80 and 443 listens on a public address, and names it: the firewall
would close it. Check what it is; run again with `--yes` only if it is not needed from outside.

Every file it replaces is kept in `/var/backups/echoledger-harden/` (never beside it: apt and systemd would read a copy
left in their folders).

## What harden.sh sets up

- **Security updates every day** (unattended-upgrades, the `-security` origins only, Ubuntu's own list cleared first),
  at **03:30 in Pakistan** (22:30 UTC, the quietest hour) instead of Ubuntu's morning slot. **No automatic reboot**, and
  **needrestart never restarts the relay or nginx**: either would close every live call. When an update waits for a
  reboot, or for the relay or nginx to restart, the resources check alerts, and someone restarts outside busy hours
  (`sudo reboot`, or `sudo systemctl restart assist-relay nginx`; the apps reconnect by themselves).
- **Firewall** (ufw): incoming denied by default; SSH rate limited on every port sshd uses (and the one the session
  running it came in on); 80 and 443 for nginx. A plain ALLOW for SSH (services/assist-relay/DEPLOY.md section 1 adds
  `ufw allow OpenSSH`) would let every attempt past the limit, so it is removed once the limit is in. The relay's 8787
  stays closed: it listens on loopback only, behind nginx. A rule that opened 8787 by hand is removed, also while the
  firewall is still off.
- **fail2ban** on SSH: 5 failed sign-ins in 10 minutes ban the address for an hour. No address is let past it for
  good: home and mobile addresses in Pakistan change and are shared through carrier NAT, so an address let through
  today could be anyone's tomorrow. `ssh-lockdown.sh` lets the tester's address through only while the key sign-in is
  tested, in fail2ban's running state (a restart forgets it), and takes it back at the end of step 2. Running
  `harden.sh` with `sudo` (which drops `SSH_CONNECTION`) changes nothing about this.
- **Clock in sync** (systemd-timesyncd): assist tokens are checked against it.
- **Journal capped** at 1 GB and 14 days (services/assist-relay/DEPLOY.md, "Logs").
- **Relay watchdog**, every minute: systemd already restarts the relay when it exits. The watchdog catches a relay that
  runs but no longer answers `/healthz`: after 3 misses in a row it restarts it once and alerts, then waits for health to
  come back (one more restart every 30 minutes while it stays down). A relay stopped on purpose is left alone.
- **Resources**, every 5 minutes: CPU (the 5-minute load against the cores), memory (what the kernel says is available)
  and disk at **80 %**, a waiting reboot, and the relay or nginx waiting for a restart after an update. Each alert is
  sent once when it starts and once when it clears (5 points under its limit, so a value at the limit does not flap).
- **Settings backup**, every day at 03:30 server time (UTC on the VPS, so 08:30 in Pakistan; it is a few seconds of
  work and touches no live call): one archive of the relay's environment file and config, its systemd unit,
  nginx's sites, journald and ops settings, the firewall rules, certbot's renewal settings and the current relay binary,
  in `/var/backups/echoledger-relay/` (root only), 14 kept, each with its sha256.
  **Never in it:** the encrypted relay secret and the host key that opens it, TLS private keys, SSH keys, `alert.env`.

Limits live in `/etc/echoledger-ops/ops.env` (not secret): `CPU_PCT`, `MEM_PCT`, `DISK_PCT`, `DISK_PATHS`,
`FAILS_BEFORE_RESTART`, `BACKUP_KEEP`.

## Alerts

Every alert goes to the journal: `journalctl -t echoledger-ops`. To have them reach a phone too, set a webhook in
`/etc/echoledger-ops/alert.env` (root only, 0600, never in the repo or a chat):

```
ALERT_URL=https://ntfy.sh/<a long random topic>     # or a Slack or Discord incoming webhook
ALERT_KIND=ntfy                                    # ntfy (default), slack or discord
```

ntfy needs no account (install the ntfy app and subscribe to the topic); the topic name is the only secret, so make it
long and random. Which channel to use is the owner's choice. Messages hold the host, the check and numbers only, for
example `[relay] ALERT: memory at 86% used (limit 80%)`: never anything anyone said, no tokens, no keys.

Still needed from outside, because a VPS cannot report that it is down: an uptime check on
`https://<relay host>/healthz` every minute (UptimeRobot, Better Stack or similar), as DEPLOY.md says.

## Logs with no content

- The relay writes ids, numbers and fixed labels only, never words, model text, tokens or keys (its load test checks
  this). `check.sh` looks for tokens, keys or text in the last 24 h of its journal.
- The apps send the assist token in a header or the first message, never in a URL, so nginx's access log holds none.
  `check.sh` fails if one ever appears there. `/healthz` is not logged.
- The ops checks log the same way: host, check, numbers.

## SSH lockdown (after the owner changes the root password)

Not before: until then root's password is the way back in. Then, with the Mac's key already in
`/root/.ssh/authorized_keys`, open **one interactive root shell and keep it open until the end**: it is the way to put
things right if anything goes wrong.

```
ssh relay-vps                                                        # terminal 1: root, stays open
bash /root/echoledger-vps/ssh-lockdown.sh --admin elops              # in terminal 1

ssh -o IdentitiesOnly=yes -i <key> elops@<vps> 'sudo -n true && echo OK'   # terminal 2: must print OK

bash /root/echoledger-vps/ssh-lockdown.sh --admin elops --i-tested-key-login   # in terminal 1

ssh -o IdentitiesOnly=yes -i <key> elops@<vps> 'sudo -n true && echo OK'   # terminal 2: still OK
ssh root@<vps>                                                       # terminal 2: must be refused
```

`IdentitiesOnly` makes ssh offer only that key, as a fresh machine would: an agent holding several keys could otherwise
pass the test with another one.

The first run makes `elops`: no password, root's authorized keys, sudo without a password (it has no password to give).
The second writes `/etc/ssh/sshd_config.d/00-echoledger.conf` (root sign-in off, keys only, 30 s to sign in),
checks it with `sshd -t` and with what sshd will really do for root and for `elops` (`sshd -T -C`, so a `Match` block
cannot keep root or passwords on), and reloads SSH. Open sessions stay. If any step fails, a failed reload included,
it puts back what was there before. Update `~/.ssh/config` on the Mac to `User elops`; from then on the scripts run as
`sudo bash ~/echoledger-vps/<script>` (see "Running them").

**Undo**, from Hostinger's browser terminal (hPanel → VPS → Terminal) if SSH no longer lets you in:
`rm /etc/ssh/sshd_config.d/00-echoledger.conf && systemctl reload ssh`.

## Backups off the VPS

The archives stay on the VPS until copied. Weekly at least, from the Mac:

```
deploy/vps/pull-backup.sh relay-vps        # -> ~/echoledger-backups/relay/, sha256 checked, 30 kept
```

Hostinger's own weekly VPS backup (hPanel) is the other copy.

## Restore

On a fresh Ubuntu 24.04: install the relay as in services/assist-relay/DEPLOY.md, sections 1 and 4, then unpack the
archive over `/` (`tar -xzf relay-config-<time>.tar.gz -C /`), run `harden.sh`, and then:

1. a **new relay secret**: Admin → API Credentials → `assist_relay` → Generate, stored as in DEPLOY.md section 2 (the
   old one stays on the dead machine, encrypted to its key);
2. a **new certificate**: `certbot certonly --nginx -d <relay host>`. If the apps pin the relay's key, its pins must be
   in the apps first (DEPLOY.md section 6);
3. `systemctl daemon-reload && systemctl restart assist-relay nginx`, then `check.sh`.

## Undoing harden.sh

Each file it replaced is in `/var/backups/echoledger-harden/` (its path with `_` for `/`, then the time). The pieces
on their own:
`systemctl disable --now echoledger-relay-watchdog.timer echoledger-resources.timer echoledger-backup.timer`;
`ufw disable`; `rm /etc/fail2ban/jail.d/echoledger-sshd.conf && systemctl restart fail2ban`;
`rm /etc/apt/apt.conf.d/52echoledger-unattended /etc/needrestart/conf.d/echoledger.conf
/etc/systemd/system/apt-daily-upgrade.timer.d/echoledger.conf && systemctl daemon-reload`;
`rm /etc/systemd/journald.conf.d/relay.conf`.
