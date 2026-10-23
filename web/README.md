# EchoLedger Server

The EchoLedger server is the system of record for the platform: it stores recordings (encrypted at rest),
orchestrates speech-to-text providers, serves the unified library to every paired device, and hosts the
administration console.

Built with **Laravel 13**, **Filament 5** and **Laravel Sanctum**. Designed to run on standard shared hosting:
no daemons, no Redis, no WebSocket server — a single cron entry drives the queue, webhook reconciliation and
retention.

## Responsibilities

| Area | What it does |
|---|---|
| Device API | Pairing with one-time codes, scoped device tokens, resumable chunked uploads, library sync with tombstones |
| Speech-to-text | Provider abstraction (AssemblyAI, ElevenLabs, Azure Speech, self-hosted whisper.cpp, on-device Parakeet); short-lived streaming tokens; multichannel final transcripts with per-client keyterms |
| Security | Provider keys encrypted at rest and never exposed to clients; recordings encrypted with XChaCha20-Poly1305 (libsodium secretstream) under a dedicated key; signed webhooks; mandatory TOTP two-factor sign-in |
| Administration | Dashboard, sessions library with synced transcript player, clients and keyterms, devices and pairing, settings, API credentials |
| Retention | Recordings, transcripts and webhook payloads purge automatically after the configured number of days (default 30) |

## Quick start (local)

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan echoledger:key          # dedicated recordings encryption key
php artisan migrate:fresh --seed    # demo library + owner@echoledger.local / change-me-now-please
php artisan serve
php artisan queue:work --stop-when-empty
```

Sign in at `http://localhost:8000/admin`. Two-factor enrolment is required on first sign-in.

## Key commands

| Command | Purpose |
|---|---|
| `php artisan echoledger:make-admin` | Create or promote the super administrator |
| `php artisan echoledger:key` | Generate the recordings encryption key (back it up offline) |
| `php artisan stt:reconcile` | Recover transcripts whose provider webhook never arrived |
| `php artisan model:prune` | Apply the retention policy |
| `php artisan test` | Run the test suite |

## Documentation

- [Deployment guide (shared hosting / cPanel)](DEPLOY.md)
- [REST API contract](../docs/API.md)
- [Architecture](../docs/ARCHITECTURE.md)
- [Speech-to-text providers](../docs/PROVIDERS.md)
