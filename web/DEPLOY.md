# Deploying the EchoLedger server (shared hosting / cPanel)

The server is a standard Laravel 13 app with no daemons: one cron entry drives the queue, the webhook
reconciler and the 30-day retention cleanup.

## Requirements
- PHP **≥ 8.4.1** (the lock file enforces it) with `sodium`, `pdo_mysql` (or `pdo_sqlite`), `mbstring`, `intl`,
  `fileinfo`, `zip`, `curl`, `openssl`.
- MySQL 8 / MariaDB 10.6+ (or SQLite for single-user installs).
- HTTPS on a public domain (providers deliver webhooks to `APP_URL`; devices pin its certificate).
- Recommended PHP limits: `post_max_size >= 8M` (chunks are ≤ 5 MiB raw bodies), `memory_limit >= 256M`
  (playback decrypts in memory), `max_execution_time >= 60` for web requests.

## First deploy
1. Upload the `web/` folder above `public_html` and point the domain's document root at `web/public`.
   On hosts that force `public_html`, symlink it: `ln -s ~/echoledger/web/public ~/public_html`.
2. Locally run `composer install --no-dev --optimize-autoloader` and `npm install && npm run build` (the repo
   has no npm lock file, so a clean install uses `npm install`), then upload `vendor/` and `public/build/` (or run
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
8. `php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan filament:optimize`
9. In **Admin → API Credentials** paste the AssemblyAI API key and press **Generate** for the webhook secret.
   In **Admin → Settings** pick providers per use-case and add the certificate pins (see below).

## Cron (the only background process)
```
* * * * * cd /home/USER/echoledger/web && php artisan schedule:run >> /dev/null 2>&1
```
The scheduler (`routes/console.php`) runs:
- `queue:work --stop-when-empty --max-time=55 --tries=3 --timeout=900` every minute (`withoutOverlapping(15)`)
- `stt:reconcile` every 5 minutes (recovers missed webhooks, retries provider-side deletes)
- `model:prune` daily (deletes recordings, transcripts, webhook events and tombstones older than the retention days)
- `sanctum:prune-expired --hours=24` daily

Check what retention would delete without deleting: `php artisan model:prune --pretend`.
`--timeout` is enforced only when `pcntl` exists; every provider HTTP call also has its own timeout.

## Provider dashboards
- **AssemblyAI:** upgrade to a paid plan, enable *Data Controls → Opt out of training*, set transcript TTL to 1 day
  as a backstop. Webhook URLs are sent per request automatically (`/api/webhooks/stt/assemblyai?rid=…`).
- **ElevenLabs:** create a workspace webhook pointing to `https://YOUR_DOMAIN/api/webhooks/stt/elevenlabs`, enable
  retries, copy its HMAC secret + webhook ID into API Credentials. Use a service-account key restricted to STT.
- **Azure:** set region + endpoint in Settings and the resource key in API Credentials.
- **whisper.cpp:** run `whisper-server` behind an HTTPS reverse proxy that checks `Authorization: Bearer <token>`.

## Certificate pins for the device apps
Devices pin the server's public key (SPKI SHA-256), current + backup:
```
openssl s_client -connect ledger.example.com:443 -servername ledger.example.com </dev/null 2>/dev/null \
  | openssl x509 -pubkey -noout | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | base64
```
Enter each as `sha256/<base64>` in Settings → Device security. Let's Encrypt renewals keep the pin only with
`--reuse-key`; always keep a pre-generated backup key's pin in the list so rotation never locks devices out.

## Updating
Upload the new code, then: `php artisan migrate --force && php artisan optimize:clear && php artisan optimize`.

## Local development (this repo)
```
php artisan migrate:fresh --seed      # APP_ENV=local: owner@echoledger.local / change-me-now-please + demo library
php artisan serve
php artisan queue:work --stop-when-empty
```
