# Live Assist relay: load test

The plan's relay targets (docs/PLAN_V1_9.md, L2 and L9) checked against the relay binary with mocked models. The
models are the deterministic mocks (`RELAY_PROVIDERS=mock`) with each model's latency from `config/relay.toml`
(Haiku: first token after 350 ms, then a word every 12 ms; Sonnet: 700 ms, then 18 ms). There is no server and no
provider: nothing leaves the machine, and no key exists anywhere in the run.

## Running it

```
cd services/assist-relay
cargo build --release
target/release/assist-relay-loadtest                                   # the plan's 50 sessions
target/release/assist-relay-loadtest --relay-threads 2                 # the relay on 2 worker threads (about a 2-vCPU VPS)
target/release/assist-relay-loadtest --relay-threads 2 --sessions 500 --questions 4 --ramp-ms 10000
```

Options: `--sessions N` (50), `--questions N` (8), `--modes fast,balanced,deep`, `--think-ms N` (800, the pause after
my reply), `--ramp-ms N` (2000, the connects spread over this long), `--relay PATH` (default: the `assist-relay`
binary next to the load test), `--relay-threads N` (`TOKIO_WORKER_THREADS` for the relay only: an approximation of a
small VPS, not a CPU limit, see below). The exit code is 0
when every target passes. It needs a Unix system (`ps` for memory, `kill -TERM` for the shutdown).

What one session does, like an app in a short interview:

1. Opens `/v1/assist` with `Authorization: Bearer` (an Ultra token signed with a secret made for this run, kept in
   memory and in the relay's environment only), reads `ready`.
2. Sends `context_update`: an interview brief, a CV of about 6,000 characters, memory, a fact, and its mode. Sessions
   take Fast, Balanced and Deep in turn.
3. For each question: a `turn_partial` with half the words (40 ms of silence, so no speculative start), the
   `turn_final`, then reads up to `suggestion_end`. Then my reply as a `me` final, and a pause during which nothing
   may arrive. The questions are short, so Balanced sends no upgrade and every question gets exactly one suggestion.
4. Stays connected. When every session is done, the relay gets SIGTERM with all of them still open.

What it measures:

| Measure | How |
|---|---|
| First token | The app's clock: sending `turn_final` to receiving the first `token`. **Held models count full-answer time:** Sonnet 5.5 (Deep) and Opus 5.5 are held until complete (`hold_until_complete`, so a refusal is never shown), so their first word on the card comes with their last. Deep's first token is the mock's whole answer: 700 ms, then a word every 18 ms. |
| **Relay overhead** | **The app's first token minus the model's share**: Haiku's time to its first word (the mock's 350 ms) in Fast and Balanced, Sonnet's time to its last word in Deep. Everything the relay, both sockets, the timers and the app's own scheduling add. This is the figure held against the 30 ms target. |
| Relay overhead, relay's own measure | `suggestion_end.latency.relay_overhead_ms`: end of turn to the first token sent, minus the model's share as the relay saw it (to the first word, or the last for a held answer). Shown for comparison; it leaves out the sockets and the timer slack. |
| Memory per session | The relay's resident memory (`ps -o rss`), sampled every 100 ms: the peak with every session open, minus the idle relay, divided by the sessions. |
| Clean shutdown | SIGTERM with every session open: every app must get close `1012`, the relay must log `stopped` and exit 0 within 6 s (it waits up to 5 s for sessions). |
| Nothing unasked | Any `suggestion_start` that no question of the other person asked for (my own channel included). |
| Redaction | The relay's whole log (JSON lines on standard output) must hold none of the questions, my replies, the CV's marker word or the run's secret. |

## Machine

| | |
|---|---|
| Computer | MacBook Pro (MacBookPro18,1), Apple M1 Pro, 10 cores (8 performance, 2 efficiency), 32 GB |
| System | macOS 27.3, rustc 1.99.1, release build (`lto = "thin"`) |
| Load | Shared with other work: load average 40 to 52 during these runs |
| Network | Loopback (127.0.0.1); the apps and the relay on the same machine, competing for the same cores |

Run on 2026-11-17 at commit `49287a77` (Deep's Sonnet held until complete). The first runs, at `ebd2d790`, had Deep
streaming; their Fast and Balanced numbers were the same.

## Results

All four runs passed every target.

### The plan's 50 sessions, relay on one thread per core

50 sessions, 8 questions each (400 answers), think 800 ms, ramp 2 s.

| Mode | Answers | First token p50 / p95 ms | Relay overhead p50 / p95 ms (first token − model's share) | Relay's own measure p50 / p95 ms |
|---|---|---|---|---|
| Fast | 136 | 352.3 / 369.1 | 0.53 / 17.97 | 0.10 / 0.20 |
| Balanced (draft) | 136 | 352.2 / 367.6 | 0.50 / 16.14 | 0.10 / 0.20 |
| Deep (held: full-answer time) | 128 | 1318.8 / 1360.3 | 0.52 / 14.89 | 0.10 / 0.20 |

### Against the targets (50 sessions, relay on 2 threads, approximating a 2-vCPU VPS)

| Target | Plan | Result | Pass |
|---|---|---|---|
| Concurrent sessions | 50, every question answered, no errors | 50 ready, 400/400 answered, 0 errors | pass |
| End of turn to first word, Fast | p50 ≤ 1.5 s (mocked) | p50 352.0 ms, p95 360.4 ms | pass |
| End of turn to first word, Balanced draft | p50 ≤ 2.0 s (mocked) | p50 351.8 ms, p95 354.7 ms | pass |
| End of turn to first word, Deep (held: the full answer) | p50 ≤ 3.0 s (mocked) | p50 1314.9 ms, p95 1342.8 ms | pass |
| Relay overhead (first token − model's share), Fast | p95 ≤ 30 ms | p50 0.34 ms, p95 9.40 ms | pass |
| Relay overhead (first token − model's share), Balanced | p95 ≤ 30 ms | p50 0.34 ms, p95 2.74 ms | pass |
| Relay overhead (first token − model's share), Deep | p95 ≤ 30 ms | p50 0.44 ms, p95 11.13 ms | pass |
| Relay overhead, relay's own measure | p95 ≤ 30 ms | p95 0.20 ms | pass |
| Memory per session | no target in the plan | 7.7 MiB idle, 18.5 MiB peak with 50 open: about 222 KiB per session | reported |
| Clean shutdown | every app gets 1012, exit 0 | 50 × 1012, exit 0, 80 ms after SIGTERM, `stopped` logged | pass |
| Zero suggestions from my own channel | 0 | 0 | pass |
| No content in the relay's log | 0 lines | 0 lines (400 suggestion records checked) | pass |

### Headroom, relay on 2 threads (approximation, see above)

| Sessions × questions | Answers | Relay overhead p95 ms, first token − model's share (Fast · Balanced · Deep) | Relay's own measure p95 ms | Deep first word p50 ms (held) | Memory (open, per session) | Shutdown |
|---|---|---|---|---|---|---|
| 50 × 8 | 400/400 | 9.40 · 2.74 · 11.13 | 0.20 | 1314.9 | 18.5 MiB, 222 KiB | 50 × 1012, exit 0, 80 ms |
| 200 × 5 | 1000/1000 | 10.29 · 8.67 · 9.76 | 0.10 | 1313.7 | 46.0 MiB, 196 KiB | 200 × 1012, exit 0, 68 ms |
| 500 × 4 | 2000/2000 | 6.52 · 6.36 · 13.19 | 0.20 | 1318.4 | 100.5 MiB, 190 KiB | 500 × 1012, exit 0, 106 ms |

In Fast and Balanced the first word is the mock's first-token delay (350 ms) plus under a millisecond at p50 and under
about 10 ms at p95. In Deep it is Sonnet's whole answer (about 1.3 s with the mock's 700 ms and 18 ms a word) plus the
same small overhead: the price of never showing a refusal. Neither grows between 50 and 500 sessions. With a real
model, Deep's first word is its full-answer time, which the latency benchmark (docs/LATENCY_V1_9.md) measures as
"Answer p50" and holds against the 3 s target.

### What changed because of this test

The first run showed the app-side overhead at 21–33 ms p95 while the relay's own share was 0.2 ms. A suggestion is a
run of small frames (`suggestion_start`, then a `token` per word), and with Nagle's algorithm on, a frame written
right after another waited for the first one's acknowledgement. The relay now sets `TCP_NODELAY` on every connection
(`e2e90a38`); the gap fell to 3–12 ms p95, and the rest is scheduling on a busy machine. **The apps should set
`TCP_NODELAY` on their side too** (the load test's client does): a partial and a final sent back to back hit the same
delay in the other direction.

## What this does not measure

- **Real models.** The run uses the mocked models; the streaming clients for Anthropic, Groq, OpenAI and Gemini are
  tested separately against a local stand-in (tests/providers.rs) and are not part of these numbers. TLS to the providers, their stream parsing and
  their real first-token times are not in these numbers. The real first-token times come from the L9 latency
  benchmark run from Pakistan (docs/LATENCY_V1_9.md).
- **The network between the app and the relay**, and TLS at the reverse proxy: one round trip from Pakistan to the
  chosen region (see `tools/latency-check.sh`) is added to every first token.
- **Long calls.** Each session here lasts about 20 seconds. A session's transcript is capped (`max_transcript_tokens`,
  40,000 tokens, about 160 KB, plus at most about 100 recent turns), and the documents can reach their limit (about
  60,000 tokens, roughly 240 KB). The worst case is **estimated**, not measured, at about 1 MiB per session, still far
  inside 4 GB.
- **The server's internal API** (provider keys, usage and suggestion reports): no server in the run, so the report
  queue is off.
- **The speculative start and the Balanced upgrade under load**: covered by the session tests, not by this run.
