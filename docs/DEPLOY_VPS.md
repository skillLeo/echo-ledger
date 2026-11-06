# Moving the EchoLedger server to a VPS

The server runs on shared hosting today (web/DEPLOY.md), which is fine for one person and a few teammates. Before
the public launch it moves to a VPS. Nothing has been moved yet. The move needs Hassam's go-ahead, a VPS and about
two hours with a short maintenance window.

Why move:

- **Workers.** Shared hosting allows no long-running processes. Background jobs (transcription hand-offs, Roman Urdu
  views, screen descriptions, exports) run from cron in 55-second slices, one at a time.
- **No Redis.** The cache, sessions and the queue live in MariaDB tables.
- **One disk.** Recordings, screens and exports share the hosting account's disk, with no separate backups.
- **Neighbours.** Other sites on the same account share its CPU, memory and limits.

## What to rent

- **VPS:** 2 vCPU, 4 GB RAM, 80 GB SSD to start (about $10–20/month). Use Ubuntu 24.04 LTS, in a region near the
  people who record (Europe or the Gulf for Pakistan; latency to the speech providers matters less).
- **Object storage (optional):** S3-compatible storage for recordings once they outgrow the disk (Backblaze B2,
  Cloudflare R2, Wasabi or the VPS provider's own). Recordings are encrypted before they are stored, so the storage
  company never sees audio.
- **Backups:** a second provider for nightly copies, for example a storage box or another bucket.

## Software

| Piece | Version | Notes |
|---|---|---|
| PHP-FPM | 8.4 | Extensions: `sodium`, `pdo_mysql`, `mbstring`, `intl`, `fileinfo`, `zip`, `curl`, `openssl`, `gd` (with WebP), `redis`, `pcntl` |
| Nginx | current | HTTPS with Let's Encrypt (`--reuse-key`, see the pins below) |
| MariaDB | 10.11 or 11.x | `max_allowed_packet=64M` |
| Redis | 7 | Bound to localhost, with a password |
| Supervisor | current | Keeps the queue workers running |
| ffmpeg | current | Audio preparation for the speech engines |
| Composer, Node 22 | current | Only to build; Node is not needed on the server if `public/build` is uploaded |

## Settings that change on a VPS

The rest of `.env` is copied from the shared host unchanged. Keep `APP_KEY` and `ECHOLEDGER_RECORDINGS_KEY` exactly
as they are: they unlock the stored recordings.

```
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=<the Redis password>
REDIS_PORT=6379
# The scheduler stops starting a worker every minute; Supervisor runs them instead.
QUEUE_VIA_SCHEDULER=false
```

For object storage, recordings, screens and exports move off the disk:

```
RECORDINGS_DRIVER=s3
RECORDINGS_S3_KEY=<access key>
RECORDINGS_S3_SECRET=<secret key>
RECORDINGS_S3_REGION=<region>
RECORDINGS_S3_BUCKET=<bucket>
RECORDINGS_S3_ENDPOINT=https://<the provider's S3 endpoint>
RECORDINGS_S3_PATH_STYLE=true
```

Leave `RECORDINGS_DRIVER` unset (or `local`) to keep them on the VPS disk. The region is what the provider says (`auto`
on Cloudflare R2), and `_ENDPOINT` can stay empty on Amazon S3. Keep `_PATH_STYLE=true` unless the provider asks for the
bucket's name in the host (`<bucket>.<endpoint>`).

Check the settings before anything depends on them. This writes, reads, lists and deletes a small test file:

```
php artisan config:cache
php artisan recordings:check-storage
```

- **The bucket stays private.** Files are never served from it: every download goes through the server, which decrypts
  it. Give the key reading, writing, deleting and listing, on this one bucket only.
- **What the storage company can see:** encrypted files, their sizes and their dates. Upload parts, which wait
  unencrypted on the server's own disk until the whole file is in, are encrypted one by one on the way to a bucket. The
  keys (`files/2026/11/<id>.elr`, `screens/...`, `client-files/...`, `exports/...`) hold only ids.
- **Temp space:** the server still needs room in `storage/app/private/tmp`. A file is copied there to be decrypted, played
  or sent to a speech engine, and goes as soon as that is done. Plan for twice the largest recording per queue worker
  (2 GB by default), and for an account export, twice the account's size.
- **Speed:** playing a recording fetches and decrypts the whole file for each request, also for a range, as it does from
  the disk; from a bucket that adds the download. Choose a bucket in the VPS's region.
- **Failed deletes:** a delete the bucket refuses after three tries is retried by `stt:reconcile` from
  the `storage_deletions` table (5 minutes, doubling up to 6 hours) until it goes. Upload parts a failed delete left are
  removed by the same command after 24 hours.
- **Add one rule at the provider:** abort unfinished multipart uploads after a day. A file from 64 MB goes up in parts; a
  server that dies halfway leaves them in the bucket, billed but not visible.
- **The console's "Server disk" meter disappears,** and `GET /api/storage` answers `null` for the disk fields: a bucket has
  no size to report. What the recordings take is still counted, from the database.

Moving existing files: keep the keys, and leave the unfinished upload parts out (they are not encrypted):

```
rclone copy storage/app/private/recordings remote:<bucket> --exclude "parts/**" --exclude "request-parts/**"
```

Run it once while the site works, once more after setting the site to maintenance, then switch `RECORDINGS_DRIVER` and
check a recording plays. Uploads that were under way ask for their parts again and carry on by themselves. Keep the old
folder until a backup of the bucket exists.

Back up the bucket as well: `APP_KEY` and `ECHOLEDGER_RECORDINGS_KEY` never go there, so a copy of it is as safe as the
bucket itself, and useless without those two keys.

## Claude and ChatGPT connector

The connector signs its tokens with a key pair in `storage/` (web/DEPLOY.md, "Claude and ChatGPT connector"). They do
not come with `storage/app/private`. Copy them from the old server when you move, so nobody has to connect again:

```
rsync -a old-server:/path/to/web/storage/oauth-private.key old-server:/path/to/web/storage/oauth-public.key /var/www/echoledger/web/storage/
chown www-data:www-data /var/www/echoledger/web/storage/oauth-*.key
chmod 600 /var/www/echoledger/web/storage/oauth-*.key
```

On a new server with nothing to copy, run this once instead: `sudo -u www-data php artisan passport:keys`, then the same
`chmod`. Never commit them. Nginx below already lets `/.well-known/` through to the app.

## Queue workers (Supervisor)

`/etc/supervisor/conf.d/echoledger-worker.conf`:

```
[program:echoledger-worker]
command=/usr/bin/php /var/www/echoledger/web/artisan queue:work redis --sleep=1 --tries=3 --timeout=900 --max-time=3600
user=www-data
numprocs=2
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopwaitsecs=960
stdout_logfile=/var/log/echoledger-worker.log
```

Start them with `supervisorctl reread && supervisorctl update`. Every deploy ends with `php artisan queue:restart`,
so the workers pick up the new code.

## Scheduler (cron)

The same single line as on shared hosting, for the `www-data` user:

```
* * * * * cd /var/www/echoledger/web && php artisan schedule:run >> /dev/null 2>&1
```

With `QUEUE_VIA_SCHEDULER=false` it still runs the reconciler, retention, account deletions and the other clean-ups,
but no longer starts workers.

## Nginx

```
server {
    listen 443 ssl http2;
    server_name <console host>;
    root /var/www/echoledger/web/public;
    index index.php;
    client_max_body_size 8m;          # upload chunks are at most 5 MiB
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_read_timeout 120;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

## Backups

- **Database:** every night, `mysqldump --single-transaction --routines --triggers`, compressed, copied to the
  backup provider and kept for 30 days.
- **Recordings, screens and files** (when on the disk): every night with `restic` or `borg` to the backup provider.
  They are already encrypted. Object storage gets bucket versioning instead.
- **Keys:** keep `APP_KEY` and `ECHOLEDGER_RECORDINGS_KEY` offline in a password manager. A backup without the
  recordings key cannot be played. Losing `storage/oauth-*.key` only means making new ones: connected apps sign in again.
- Restore one backup to a scratch database once a month, to know the backups work.

## Monitoring

- **Uptime:** a check on `https://<console host>/up` every minute (UptimeRobot, Better Stack or similar), with
  email or SMS alerts.
- **Disk and memory:** alerts at 80 % from the VPS provider's panel, or a small agent.
- **Jobs:** the console's Usage page shows failed jobs. `php artisan queue:failed` lists them on the server.
- **Logs:** `storage/logs/laravel.log` with daily rotation (`LOG_STACK=daily`, kept 14 days).

## Moving, step by step

1. **Two days before:** lower the DNS TTL of the console host to 5 minutes.
2. **Certificate pins:** the phone and desktop apps pin the server's public key (web/DEPLOY.md, "Certificate pins
   for the device apps"). Make the VPS's TLS key first, add its pin to Settings → Device security on the **current**
   server, and wait until every device has synced once (a day). Otherwise the apps refuse the new server.
3. Set up the VPS: the software above, the code at the same commit as production, `.env` (copied, with the changes
   above), `composer install --no-dev --optimize-autoloader`, `public/build` uploaded, the connector's keys (above),
   Nginx, Supervisor and cron.
4. **Rehearsal:** copy the database and `storage/app/private` from the shared host and run `php artisan migrate
   --force`, which should have nothing to do. Open the console through a hosts-file entry and play an old
   recording: that proves the recordings key works.
5. **The switch (about 15 minutes):** `php artisan down` on the shared host; a final database dump and recordings
   sync; import them on the VPS; `php artisan optimize`; `php artisan queue:restart`; point DNS at the VPS; `php
   artisan up` on the VPS.
6. **Check:** sign in to the console, play a recording, record a short voice note on the phone and watch it get
   transcribed, and confirm that a provider webhook arrived (Activity).
7. Keep the shared host untouched (in maintenance mode) for 7 days. To go back, point DNS at it again and run
   `php artisan up`.

## Costs

| What | About |
|---|---|
| VPS | $10–20/month |
| Object storage | $0.005–0.02 per GB/month (optional) |
| Backups | $3–5/month |
| Uptime monitoring | Free tier |
