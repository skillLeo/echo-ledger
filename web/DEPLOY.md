# Deploying the EchoLedger server (shared hosting / cPanel)

The server is a standard Laravel 13 app with no daemons: one cron entry drives the queue, the webhook
reconciler and the 30-day retention cleanup.

## Requirements
- PHP **≥ 8.4.1** (the lock file enforces it) with `sodium`, `pdo_mysql` (or `pdo_sqlite`), `mbstring`, `intl`,
  `fileinfo`, `zip`, `curl`, `openssl`, and since v1.5 `gd` with WebP (screen moment thumbnails). `sodium` and `openssl`
  are also what the Claude and ChatGPT connector signs its tokens with (`lcobucci/jwt`): check both with
  `php -m | grep -E 'sodium|openssl'`.
- MySQL 8 / MariaDB 10.6+ (or SQLite for single-user installs).
- HTTPS on a public domain (providers deliver webhooks to `APP_URL`; devices pin its certificate). Passkeys are tied
  to the host of `APP_URL`: changing the domain later means adding passkeys again (passwords keep working).
- Recommended PHP limits: `post_max_size >= 8M` (chunks are ≤ 5 MiB raw bodies), `upload_max_filesize >= 4M`
  (since v1.5 a screen moment is a multipart upload of up to 3 MB), `memory_limit >= 256M` (playback decrypts in
  memory; a large screen is decoded by GD for its thumbnail), `max_execution_time >= 60` for web requests.

## First deploy
1. Upload the `web/` folder above `public_html` and point the domain's document root at `web/public`.
   On hosts that force `public_html`, symlink it: `ln -s ~/echoledger/web/public ~/public_html`.
2. Locally run `composer install --no-dev --optimize-autoloader` and `npm ci && npm run build` (`package-lock.json`
   pins the build tools; the build also makes the console theme), then upload `vendor/` and `public/build/` (or run
   them on the host if SSH + Composer are available). Composer also publishes the console's own scripts,
   styles and fonts into `public/` (`php artisan filament:upgrade`); the console loads nothing from other hosts.
3. Create `.env` from `.env.example` and set at least:
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://ledger.example.com
   DB_CONNECTION=mysql   # + DB_HOST / DB_DATABASE / DB_USERNAME / DB_PASSWORD
   QUEUE_CONNECTION=database
   DB_QUEUE_RETRY_AFTER=960
   SESSION_SECURE_COOKIE=true
   ```
   **`APP_URL` must be exactly the address people and the apps use.** Every link this server makes starts with it, and
   since v1.5 it answers only that host (no subdomains): with any other address, `www.` included, every request fails
   with 400. Serving a second name? Redirect it to this one at the web server.
4. `php artisan key:generate --force`
5. `php artisan echoledger:key` — writes `ECHOLEDGER_RECORDINGS_KEY`.
   **Back this key up offline (password manager).** Without it every stored recording is unreadable. It is
   deliberately separate from `APP_KEY`; never rotate it while recordings exist.
6. `php artisan migrate --force`
7. `php artisan echoledger:make-admin --email=you@example.com` (min 12-char password).
   Two-factor sign-in is optional and recommended. Turn it on from the profile page after the first sign-in; it
   shows the recovery codes once — store them safely. Prefer this command to `db:seed` on a server; outside
   `APP_ENV=local` the seeder never uses the demo password (it needs `ADMIN_PASSWORD` with 12+ characters, or it
   prints a random one once).
8. `php artisan passport:keys && chmod 600 storage/oauth-private.key storage/oauth-public.key`: the keys of the Claude
   and ChatGPT connector, see below. Once only.
9. `php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan filament:optimize`
10. In **Admin → API Credentials** paste the AssemblyAI API key and press **Generate** for the webhook secret.
   In **Admin → Settings** pick providers per use-case and add the certificate pins (see below).

## Cron (the only background process)
```
* * * * * cd /home/USER/echoledger/web && php artisan schedule:run >> /dev/null 2>&1
```
The scheduler (`routes/console.php`) runs:
- `queue:work --stop-when-empty --max-time=55 --tries=3 --timeout=900` every minute (`withoutOverlapping(15)`)
- `stt:reconcile` every 5 minutes (recovers missed webhooks, retries provider-side deletes)
- `recordings:retention` hourly (recordings past their retention go to the 48-hour undo bin; the bin erases what has
  waited 48 hours) and `model:prune` daily (webhook events, old tombstones and other expired rows)
- `sanctum:prune-expired --hours=24` daily
- `echoledger:erase-accounts` hourly (since v1.5: accounts past their 7-day deletion grace are erased with every file
  only they own) and `echoledger:prune-exports` hourly ("Export my data" ZIPs go 24 hours after they are made)

Check what the daily clean-up would delete without deleting: `php artisan model:prune --pretend`. Deleted recordings can
be restored from the console's Recently deleted page for 48 hours.
`--timeout` is enforced only when `pcntl` exists; every provider HTTP call also has its own timeout.

## Provider dashboards
- **AssemblyAI:** upgrade to a paid plan, enable *Data Controls → Opt out of training*, set transcript TTL to 1 day
  as a backstop. Webhook URLs are sent per request automatically (`/api/webhooks/stt/assemblyai?rid=…`).
- **ElevenLabs:** create a workspace webhook pointing to `https://YOUR_DOMAIN/api/webhooks/stt/elevenlabs`, enable
  retries, copy its HMAC secret + webhook ID into API Credentials. Use a service-account key restricted to STT.
- **Azure:** set region + endpoint in Settings and the resource key in API Credentials.
- **whisper.cpp:** run `whisper-server` behind an HTTPS reverse proxy that checks `Authorization: Bearer <token>`.

## Email and Google sign-in (since v1.5)
- **Email:** Settings → Email takes the SMTP server, port, encryption, user, password and From address; Send a test
  email checks them. Without a server there, the `.env` mailer (`MAIL_MAILER`, `MAIL_HOST`, …) is used. Add the SPF,
  DKIM and DMARC records of `docs/EMAIL_DNS.md` to the From address's domain, or the emails land in spam.
- **Google sign-in:** in the Google Cloud console (APIs & Services → Credentials) create an OAuth client ID of type
  *Web application* with the authorised redirect URI `https://YOUR_DOMAIN/auth/google/callback` (API Credentials →
  Google sign-in shows the exact one), then paste its client ID and secret there. The consent screen asks only for
  `openid`, `email` and `profile`.
- **Sign-up:** Settings → Privacy & security → Who can sign up stays *Nobody* until accounts are opened to the public.

## Plans, roles and privacy (since v1.5)
- **Migration `2026_11_22_000001`:** plans get price, retention days, seats, AI credits and features; Personal (every
  workspace made before) loses the B20 limits it still had at their defaults and has every feature; a Free plan is
  added for new sign-ups (placeholder numbers: 300 minutes, 5 GB, 30 days, 1 seat, US$1 of AI, no Live Translate and
  no AI notes). Filing rules become each person's. In a team workspace, a recording, folder or request link one
  person put in another person's project moves (with its notes and memory lines) to a new top-level project of its
  own maker named like that one ("Acme", or "Acme (2)"); nothing is deleted. Rollback (`migrate:rollback
  --step=1`) moves Free workspaces to Personal, gives Personal its B20 limits back, turns admins and viewers into
  members, keeps those new projects, and refuses while two people of one workspace have the same filing rule (it
  names them).
- **Privacy:** after this deploy owners and admins no longer see their members' recordings and projects. On a
  one-person server nothing changes.
- **Plans page and Server usage:** System → Plans makes, edits and assigns plans; System → Server usage has the
  costs per provider, workspace and person with its own price table (Prices). Check the Free plan's numbers before
  opening sign-up.
- **Members' console:** Settings → Privacy & security → Members can use the console (on by default); off, members
  and viewers are pointed to the apps.

## The security review of v1.5.0
- **`APP_URL` is now enforced.** It must be exactly the address people and the apps use, before this deploy (step 3 above).
  On a VPS, add the first block of the Nginx section in `docs/DEPLOY_VPS.md` too.
- **Migration `2026_11_25_000001`:** a sent project keeps the list of recordings it holds (`sent_items.session_ids`).
  Projects sent before this keep what they hold at that moment; nothing is deleted. Rollback (`migrate:rollback
  --step=1`) drops the column, and a sent project shows whatever is filed in it again.
- **Restart the workers on a VPS** (`php artisan queue:restart`; on shared hosting they restart by themselves). Account
  emails are now encrypted in the queue; those already waiting are still sent.
- **Usage reports count only after a token.** The record of which device was given a token is in the cache: clearing the
  cache (`cache:clear`) makes the reports of streams open at that moment count as 0, until the device's next token.

## Claude and ChatGPT connector (since v1.5)
People add `https://YOUR_DOMAIN/mcp` in Claude or ChatGPT and read their recordings there (`docs/CONNECTOR.md`).
- **Migrations `2026_11_23_000001` to `000005`:** Passport's four tables (clients, codes, access and refresh tokens) and
  `connected_apps`. Nothing else changes. Rollback (`migrate:rollback --step=5`) drops them, so every app connected has
  to be added again; the library is not touched.
- **Keys, once, on the server:** `php artisan passport:keys`, then `chmod 600 storage/oauth-private.key
  storage/oauth-public.key`. Run it as the user PHP runs as, or `chown` the two files to it: Passport refuses a key it
  cannot read and one others can read. They are in `.gitignore`, never commit or upload them from elsewhere. Keep them
  when you update. `--force` makes new ones, which signs every connected app out (nothing is lost; they sign in again).
  Without the keys `/mcp` and the sign-in answer 500 and everything else works as before.
- **`/.well-known/` on shared hosting:** the discovery addresses are answered by the app, and hosts often keep that
  folder for themselves. When the project folder is the web root and a root `.htaccess` rewrites into `public/`, put this
  next to the App Links line below:
  ```
  RewriteRule ^\.well-known/(oauth-protected-resource|oauth-authorization-server)(/.*)?$ public/.well-known/$1$2 [L]
  ```
- **Which apps may register:** only those that send the browser back to Claude's or ChatGPT's own sign-in address
  (`https://claude.ai/api/mcp/auth_callback`, the same on `claude.com`,
  `https://chatgpt.com/connector_platform_oauth_redirect`, `https://chatgpt.com/connector/oauth/<id>`) or to this
  machine (`localhost`, for Claude Code). Other pages of those sites are refused, because they carry other people's
  content. If an app moves, registering answers `400 invalid_redirect_uri`: `MCP_REDIRECT_DOMAINS` in `.env` replaces
  the list (comma-separated, each with its scheme: an exact address, `.../*` for one more path segment, or no path for a
  whole site, which is the last resort), followed by `php artisan config:cache`.
- **A used sign-in token that comes back** ends that app's connection for that person, because someone else may hold a
  copy: Security shows "App disconnected: used token came back", and the person connects again. A real app that resends
  a refresh token more than 10 seconds later (say, after losing the answer) ends the same way, so if people see this
  often, that app is the first thing to check.
- **The plan:** the `connector` feature on System → Plans. AI notes (project notes, memory, Ask, the brief's remake) are
  a feature of their own, off in a new plan; Roman Urdu and the other views, suggested fixes and screen descriptions
  are never switched off.
- **Clean-up:** the scheduler removes used and expired tokens (`passport:purge`) and apps that registered over a day
  ago and never connected (`echoledger:prune-oauth-clients`), daily. An app that was connected once keeps its
  registration after Disconnect, so it can connect again.
- **Checks:**
  ```
  php -m | grep -E 'sodium|openssl'
  ls -l storage/oauth-*.key                                                # -rw------- for both
  curl -s https://YOUR_DOMAIN/.well-known/oauth-authorization-server       # JSON, https addresses, S256 and mcp:use
  curl -si -X POST https://YOUR_DOMAIN/mcp -H 'Accept: application/json' -H 'Content-Type: application/json' -d '{}' | head -n 12
  ```
  The last one answers `401` with a `WWW-Authenticate` header naming `/.well-known/oauth-protected-resource/mcp`.

## Certificate pins for the device apps
Devices pin the server's public key (SPKI SHA-256), current + backup:
```
openssl s_client -connect ledger.example.com:443 -servername ledger.example.com </dev/null 2>/dev/null \
  | openssl x509 -pubkey -noout | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | base64
```
Enter each as `sha256/<base64>` in Settings → Device security. Let's Encrypt renewals keep the pin only with
`--reuse-key`; always keep a pre-generated backup key's pin in the list so rotation never locks devices out.

## Android App Links (since v1.5)
The phone app opens this server's `/s/`, `/p/` and `/r/` links itself when the server answers
`/.well-known/assetlinks.json` (made by the app from `ECHOLEDGER_ANDROID_FINGERPRINTS`). Hosts often pass every
`/.well-known/` request straight through for certificate renewals, so the app never sees it. When the project folder
itself is the web root and a root `.htaccess` rewrites into `public/`, put this line before that pass-through:
```
RewriteRule ^\.well-known/assetlinks\.json$ public/.well-known/assetlinks.json [L]
```
Check: `curl -s https://<server>/.well-known/assetlinks.json` returns JSON naming `com.echoledger.mobile`.

## Updating
Upload the new code, then: `php artisan migrate --force && php artisan optimize:clear && php artisan optimize`. The first
update to v1.5 with the connector also needs its keys, once: see "Claude and ChatGPT connector" above.

## Local development (this repo)
```
php artisan migrate:fresh --seed      # APP_ENV=local: owner@echoledger.local / change-me-now-please + demo library
php artisan serve
php artisan queue:work --stop-when-empty
```
