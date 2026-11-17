# Deploying the Live Assist relay to a VPS

The relay holds the apps' WebSockets during a call, so it runs on a VPS. Shared hosting can't keep long connections
open. These notes are for **Ubuntu 24.04 LTS, 2 vCPU, 4–8 GB RAM**, with nginx in front for TLS. The relay is a single
binary with its settings and instruction pack built in. It keeps no files and no database: sessions live in memory.

```
app ──wss──▶ nginx :443 (TLS) ──ws──▶ assist-relay 127.0.0.1:8787 ──https──▶ server internal API (HMAC)
                                                                   ──https──▶ model providers
```

**Status.** With `RELAY_PROVIDERS=live` the relay streams from Anthropic, Groq, OpenAI and Gemini, using the keys
the server sends. A provider without a key in Admin -> API Credentials answers `provider_not_configured` ("Live Assist
is not set up on the server yet"). The clients are tested only against hand-written stand-ins of the providers' documented
formats, so the release gate below (section 11) must pass before live serves anyone. The mocked models
(`RELAY_PROVIDERS=mock`) serve canned answers for an end-to-end check without keys.

## Secrets and keys

- **Provider keys are never on the VPS.** They live only in the server's Admin → API Credentials. The relay fetches
  them over the signed internal API at start and every 10 minutes (`GET /api/internal/assist/providers`), keeps them
  in memory only, and never logs them (docs/API_ASSIST.md, "Internal").
- **The relay's only secret is the `assist_relay` secret.** It is generated in Admin → API Credentials and given to
  the relay at deploy time, as the contract says. It verifies the apps' assist tokens and signs the relay's requests
  to the server. On the VPS it is stored as an **encrypted systemd credential**, never as a plain file or an
  environment line. systemd decrypts it for the service at start into a private in-memory folder, and the relay reads
  it there (`$CREDENTIALS_DIRECTORY/relay_secret`).
- Nothing in this folder, the unit or the environment file holds a secret. Don't paste the secret into a shell
  command line, a ticket or a chat.

## 1. Prepare the VPS (once)

```
sudo apt update && sudo apt install -y nginx certbot python3-certbot-nginx
sudo useradd --system --no-create-home --shell /usr/sbin/nologin assist-relay
sudo mkdir -p /opt/assist-relay/releases /etc/assist-relay /etc/credstore.encrypted
sudo chmod 0700 /etc/credstore.encrypted
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable   # 8787 stays closed: loopback only
```

The relay's DNS name (`<relay host>`, for example `relay.<your domain>`) points at the VPS. Choose the region with
`tools/latency-check.sh` from Pakistan: each suggestion pays one round trip to it.

## 2. The secret, as an encrypted credential

In the console: Admin → API Credentials → `assist_relay` → generate (or copy the current one). On the VPS, type or
paste it at the hidden prompt. It goes from the prompt to `systemd-creds` and is never written in the clear:

```
read -rs RELAY_SECRET_INPUT && printf '%s' "$RELAY_SECRET_INPUT" \
  | sudo systemd-creds encrypt --name=relay_secret - /etc/credstore.encrypted/relay_secret; unset RELAY_SECRET_INPUT
```

The file is encrypted with this machine's credential key (`/var/lib/systemd/credential.secret`, or the TPM if the
VPS has one). A copy of it is useless on another machine. A new VPS gets the secret from the console again.

**Rotating the secret** (in a quiet hour: each relay restart reconnects the live calls). The relay goes first, the
server second:

> **More than one relay instance:** give EVERY instance the new secret as `relay_secret` and the old one as
> `relay_secret_previous` before the first instance restarts. The server switches to the new secret on the first
> request signed with it from any instance, so an instance still holding only the old secret would be refused.

1. In the console, make the new `assist_relay` secret **without switching the server to it yet**: the server keeps
   signing tokens and checking internal requests with the old one.
2. On the relay, encrypt the new secret as `relay_secret` and the old one as `relay_secret_previous`
   (`--name=relay_secret_previous`, file `/etc/credstore.encrypted/relay_secret_previous`), uncomment the second
   `LoadCredentialEncrypted=` line in the unit, `systemctl daemon-reload` and restart. The relay now accepts tokens
   signed with either secret.
3. Switch the server to the new secret in the console. New tokens are signed with it from now on.
4. Wait 15 minutes, the longest a token lives: tokens signed with the old secret before the switch stay valid until
   they expire, and the relay accepts them until step 5.
5. On the relay, delete `relay_secret_previous`, comment its line out again, reload and restart.

**Which secret signs the relay's internal requests during the overlap:** the one the server accepts. After step 2
the relay first signs with the new secret, gets `401` (the server still checks the old one), signs the same request
again with the old one and keeps using it. After step 3 the old one gets `401` once, and the relay moves to the new
one. Each move costs one refused request and nothing is lost; the journal shows `internal_secret_switched` with
`secret: previous`, then `secret: current`. The relay accepts tokens signed with either secret for as long as both are
installed, which is why step 5 must not be skipped.

## 3. Environment variables

`/etc/assist-relay/relay.env` (owner root, mode `0644`). It holds no secret, only these names:

| Name | Required | What it holds |
|---|---|---|
| `RELAY_BIND` | yes | The loopback address and port nginx proxies to (the default is the loopback on port 8787). Never a public address. |
| `RELAY_SERVER_URL` | yes | The server's `https://` address for the internal API. Without it the relay fetches no keys and reports no usage. |
| `RELAY_PROVIDERS` | yes | `live` in production (`mock` only for a smoke test). |
| `RELAY_LOG` | no | The relay's log level: `info` (default), `debug`, `warn`… Libraries stay at `warn` whatever it says. |
| `RELAY_TOKEN_ISSUER` | no | The tokens' `iss`; leave unset (the default matches the server). |
| `RELAY_CONFIG` | no | A settings file replacing the built-in `config/relay.toml` (same shape, no secrets). Normally unset: the server's catalog sets models and modes. |
| `RELAY_PACK_DIR` | no | An instruction-pack folder replacing the built-in pack. Normally unset. |
| `RELAY_SECRET`, `RELAY_SECRET_PREVIOUS` | **not here** | Given as the encrypted credentials `relay_secret` and `relay_secret_previous` (step 2). The environment would win over a credential, so leave them out. |

## 4. Build and install a release

Build on a machine with the same system as the VPS (Ubuntu 24.04, the same CPU architecture), or on the VPS itself,
with Rust 1.90 or newer (rustup):

```
cd services/assist-relay
cargo test --locked && cargo clippy --locked --all-targets -- -D warnings
cargo build --release --locked
REL=$(git rev-parse --short HEAD)
scp target/release/assist-relay <vps>:/tmp/assist-relay-$REL
```

On the VPS, each release goes in its own folder, and `current` points at the one in use:

```
REL=<the short commit>
sudo install -D -o root -g root -m 0755 /tmp/assist-relay-$REL /opt/assist-relay/releases/$REL/assist-relay
sudo ln -sfn /opt/assist-relay/releases/$REL /opt/assist-relay/current
sudo systemctl restart assist-relay
```

Keep the last three releases and delete older folders.

## 5. The systemd unit

`/etc/systemd/system/assist-relay.service`:

```
[Unit]
Description=EchoLedger Live Assist relay
Wants=network-online.target
After=network-online.target

[Service]
Type=exec
User=assist-relay
Group=assist-relay
EnvironmentFile=/etc/assist-relay/relay.env
LoadCredentialEncrypted=relay_secret:/etc/credstore.encrypted/relay_secret
# Only during a secret rotation (section 2, steps 2 to 5):
# LoadCredentialEncrypted=relay_secret_previous:/etc/credstore.encrypted/relay_secret_previous
ExecStart=/opt/assist-relay/current/assist-relay
Restart=on-failure
RestartSec=2
# Exit code 2 is a settings error: restarting will not fix it.
RestartPreventExitStatus=2
# SIGTERM: every app gets close 1012 and reconnects; the relay waits up to 5 s for them, then exits 0.
KillSignal=SIGTERM
TimeoutStopSec=15
LimitNOFILE=65536
MemoryMax=2G
StandardOutput=journal
StandardError=journal
SyslogIdentifier=assist-relay

# Hardening: the relay only needs to listen, connect out over TCP and read the system's CA certificates.
NoNewPrivileges=yes
CapabilityBoundingSet=
AmbientCapabilities=
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
PrivateDevices=yes
ProtectClock=yes
ProtectHostname=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectKernelLogs=yes
ProtectControlGroups=yes
ProtectProc=invisible
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
RestrictNamespaces=yes
RestrictRealtime=yes
RestrictSUIDSGID=yes
LockPersonality=yes
MemoryDenyWriteExecute=yes
SystemCallArchitectures=native
SystemCallFilter=@system-service
UMask=0077

[Install]
WantedBy=multi-user.target
```

```
sudo systemctl daemon-reload
sudo systemctl enable --now assist-relay
sudo systemd-analyze security assist-relay        # should rate it "OK" or better
journalctl -u assist-relay -n 20 -o cat           # "listening", then "providers_fetched"
```

Memory: the load test measured about 200 KiB per session. The worst case is **estimated**, not measured, at about
1 MiB per session (the longest turns, the transcript at its cap and the most documents). `MemoryMax=2G` leaves the
rest of a 4 GB VPS to nginx and the system. See LOADTEST.md.

## 6. nginx and TLS

`/etc/nginx/sites-available/assist-relay` (link it into `sites-enabled`). If the console runs on the same VPS, keep
its `default_server` block from docs/DEPLOY_VPS.md, which refuses unknown host names.

```
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

# Per address: open relay connections, and new connections a minute (a WebSocket is one request when it opens).
limit_conn_zone $binary_remote_addr zone=relay_conn:10m;
limit_req_zone  $binary_remote_addr zone=relay_open:10m rate=30r/m;

server {
    listen 80;
    server_name <relay host>;
    return 301 https://<relay host>$request_uri;
}

server {
    # HTTP/1.1 only: the apps' WebSockets upgrade over HTTP/1.1.
    listen 443 ssl;
    server_name <relay host>;
    ssl_certificate     /etc/letsencrypt/live/<relay host>/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/<relay host>/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    add_header Strict-Transport-Security "max-age=31536000" always;

    location = /v1/assist {
        limit_conn relay_conn 20;
        limit_req zone=relay_open burst=20 nodelay;
        limit_conn_status 429;
        limit_req_status 429;
        proxy_pass http://127.0.0.1:8787;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_set_header Host $host;
        proxy_buffering off;
        # The relay pings every 20 s and closes a socket silent for 60 s; nginx must not close it first.
        proxy_read_timeout 90s;
        proxy_send_timeout 90s;
        # Assist tokens travel in the Authorization header or the first message, never in the URL,
        # so the access log holds no token.
    }

    location = /healthz {
        proxy_pass http://127.0.0.1:8787;
        access_log off;
    }

    location / {
        return 404;
    }
}
```

```
sudo certbot --nginx -d <relay host> --reuse-key
sudo nginx -t && sudo systemctl reload nginx
```

- **Limits per address.** One app holds one connection per call and reconnects now and then, so 20 open
  connections and 30 new ones a minute (bursts of 20) per address leave room for an office or a mobile network
  where many people share one public address (carrier NAT is common in Pakistan), while one address can't hold the
  relay's capacity. Beyond them nginx answers `429` before the relay sees anything. The relay has its own ceiling for
  everyone together, `max_connections` in `[limits]` (1000; `503 relay_busy` beyond it). Each session needs about
  0.2 MiB measured and, estimated, at most about 1 MiB, so 1000 sessions fit in the unit's `MemoryMax=2G`. Raise both
  together, with the VPS's memory, if real traffic comes close.
- nginx sets `TCP_NODELAY` on WebSocket proxying by default (`tcp_nodelay on`). Keep it on. Without it, small frames
  wait for acknowledgements and add tens of milliseconds per suggestion (LOADTEST.md).
- `--reuse-key` keeps the TLS key across renewals, so a public-key pin stays valid. If the apps pin the relay's key as
  they pin the server's, add its pins the same way as in web/DEPLOY.md ("Certificate pins for the device apps"),
  including a backup key, *before* the apps first connect.
- Certbot's timer renews the certificate on its own (`systemctl list-timers | grep certbot`).

## 7. Point the server at the relay

The server's `assist/start` and token refresh return the relay's address (`relay.url`,
`wss://<relay host>/v1/assist`). Set it in the server's assist settings, and make sure the server and the relay use
the same `assist_relay` secret. Then start a practice session from the console or an app and watch the journal:
`attached`, then a `suggestion` record per answer.

## 8. Health check

- **On the VPS:** `curl -fsS http://127.0.0.1:8787/healthz` → `{"status":"ok","version":"…"}`, or
  `/opt/assist-relay/current/assist-relay --check-health` (exit code 0 when healthy; it reads `RELAY_BIND`).
- **From outside:** an uptime check on `https://<relay host>/healthz` every minute (the same service as the
  console's `/up`), with alerts. The answer holds no session data and needs no token.
- **What to watch in the journal:** `providers_fetch_failed` or `providers_refused` (the server link or the secret),
  `report_dropped` or `report_queue_full` (usage not reaching the server), many `auth_rejected` (tokens signed with
  another secret, or clocks apart: keep `systemd-timesyncd` on).

## 9. Logs and rotation

The relay writes one JSON line per event to standard output, and journald keeps it. The lines hold ids, numbers and
fixed labels only: no words anyone said, no model text, no tokens, no keys (the load test checks this). Cap the journal
so it can't fill the disk. `/etc/systemd/journald.conf.d/relay.conf`:

```
[Journal]
SystemMaxUse=1G
MaxRetentionSec=14day
```

`sudo systemctl restart systemd-journald`. At full load (50 sessions answering all day), the relay writes about
150 MB a day, so the 14 days fit unless the load is steady for days at a time. Then the 1 GB cap wins.
nginx's own logs rotate with the stock `/etc/logrotate.d/nginx` (daily, 14 kept).

Reading the logs: `journalctl -u assist-relay --since "1 hour ago" -o cat | jq -c 'select(.event=="suggestion")'`.

## 10. Rollback

Every release stays in `/opt/assist-relay/releases/<commit>`. To go back:

```
ls -t /opt/assist-relay/releases                       # newest first
sudo ln -sfn /opt/assist-relay/releases/<previous commit> /opt/assist-relay/current
sudo systemctl restart assist-relay
curl -fsS http://127.0.0.1:8787/healthz && journalctl -u assist-relay -n 5 -o cat
```

- A restart, forward or back, closes every live connection with `1012`. The apps reconnect with their token. The
  sessions live in memory, so they start fresh (`ready.resumed: false`) and the app sends its `context_update` again.
  Restart outside busy hours when you can.
- If `/etc/assist-relay/relay.env` or a `RELAY_CONFIG` file changed with the release, put the previous one back too.
  Keep a dated copy next to it before every change.
- A release that exits with code 2 (a settings error) is not restarted by systemd: `systemctl status assist-relay`
  and the journal's `startup_failed` line say which setting is wrong.

## 11. Release gate: before `RELAY_PROVIDERS=live` serves anyone

The provider clients have been tested only against hand-written stand-ins of the providers' documented streaming
formats. Before the relay serves a real person on live providers, once the keys are in Admin -> API Credentials, run
the smoke binary once for **every enabled model** in the settings (today `claude-haiku-5-5`, `claude-sonnet-5-5`,
`claude-opus-5-5`, `openai/gpt-oss-120b`; add any model the server's catalog enables), from any machine with the
release build, with that provider's key set for the one command and never written to a file:

```
read -rs ANTHROPIC_API_KEY && export ANTHROPIC_API_KEY
target/release/assist-relay-smoke --model claude-haiku-5-5 --warm --yes --record tests/fixtures/sse/anthropic_answer.sse
target/release/assist-relay-smoke --model claude-sonnet-5-5 --yes --record /tmp/sonnet.sse
target/release/assist-relay-smoke --model claude-opus-5-5 --yes --record /tmp/opus.sse
unset ANTHROPIC_API_KEY
read -rs GROQ_API_KEY && export GROQ_API_KEY
target/release/assist-relay-smoke --model openai/gpt-oss-120b --yes --record tests/fixtures/sse/groq_answer.sse
unset GROQ_API_KEY
```

The gate passes when every run prints a first token, a text in the output contract's shape, token counts and a cost,
and exits 0; with `--warm`, the second request reads most of its input from the cache. Then read each recorded file
(it holds the model's text and no key), replace the hand-written fixtures with the recordings, run `cargo test`, and
commit them. If a run fails, the relay stays on `RELAY_PROVIDERS=mock` (or off) until the client is fixed. Repeat the
gate when a model id, the `anthropic-version`, or a provider's API changes.

## Checklist for a release

1. `cargo test --locked` and `cargo clippy --locked --all-targets -- -D warnings` green; the load test passes
   (LOADTEST.md); for the first live release, and after any provider change, the release gate (section 11).
2. Build, copy, install into `releases/<commit>`, switch `current`, restart.
3. `/healthz` answers. The journal shows `listening` and `providers_fetched`, and no `startup_failed`.
4. A practice session gets a suggestion.
5. If anything is wrong, roll back (step 10).
