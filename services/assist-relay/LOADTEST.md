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
target/release/assist-relay-loadtest --relay-threads 2                 # the relay on 2 worker threads (a 2-vCPU VPS)
target/release/assist-relay-loadtest --relay-threads 2 --sessions 500 --questions 4 --ramp-ms 10000
```

Options: `--sessions N` (50), `--questions N` (8), `--modes fast,balanced,deep`, `--think-ms N` (800, the pause after
my reply), `--ramp-ms N` (2000, the connects spread over this long), `--relay PATH` (default: the `assist-relay`
binary next to the load test), `--relay-threads N` (`TOKIO_WORKER_THREADS` for the relay only). The exit code is 0
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
| First token | The app's clock: sending `turn_final` to receiving the first `token`. |
| Relay overhead (relay's measure) | `suggestion_end.latency.relay_overhead_ms`: end of turn to the first token sent, minus the model's share (`request_to_first_token_ms`). |
| Relay overhead (app side) | The app's first-token time minus the model's share: the relay's overhead plus both sockets and the app's own scheduling. The stricter of the two. |
| Memory per session | The relay's resident memory (`ps -o rss`), sampled every 100 ms: the peak with every session open, minus the idle relay, divided by the sessions. |
| Clean shutdown | SIGTERM with every session open: every app must get close `1012`, the relay must log `stopped` and exit 0 within 6 s (it waits up to 5 s for sessions). |
| Nothing unasked | Any `suggestion_start` that no question of the other person asked for (my own channel included). |
| Redaction | The relay's whole log (JSON lines on standard output) must hold none of the questions, my replies, the CV's marker word or the run's secret. |

## Machine

| | |
|---|---|
| Computer | MacBook Pro (MacBookPro18,1), Apple M1 Pro, 10 cores (8 performance, 2 efficiency), 32 GB |
| System | macOS 27.3, rustc 1.99.1, release build (`lto = "thin"`) |
| Load | Shared with other work: load average 25 to 70 during the runs |
| Network | Loopback (127.0.0.1); the apps and the relay on the same machine, competing for the same cores |

Run on 2026-11-17 at commit `ebd2d790`.

## Results

All four runs passed every target.

### The plan's 50 sessions, relay on one thread per core

50 sessions, 8 questions each (400 answers), think 800 ms, ramp 2 s.

| Mode | Answers | First token p50 / p95 ms | Relay overhead p50 / p95 ms | App-side overhead p50 / p95 ms |
|---|---|---|---|---|
| Fast | 136 | 351.8 / 356.4 | 0.10 / 0.10 | 0.38 / 4.06 |
| Balanced (draft) | 136 | 351.9 / 357.9 | 0.10 / 0.20 | 0.43 / 3.96 |
| Deep | 128 | 701.8 / 708.6 | 0.10 / 0.10 | 0.34 / 5.79 |

### Against the targets (50 sessions, relay on 2 threads, as on a 2-vCPU VPS)

| Target | Plan | Result | Pass |
|---|---|---|---|
| Concurrent sessions | 50, every question answered, no errors | 50 ready, 400/400 answered, 0 errors | pass |
| End of turn to first word, Fast | p50 ≤ 1.5 s (mocked) | p50 351.9 ms, p95 359.4 ms | pass |
| End of turn to first word, Balanced draft | p50 ≤ 2.0 s (mocked) | p50 351.6 ms, p95 357.3 ms | pass |
| End of turn to first word, Deep | p50 ≤ 3.0 s (mocked) | p50 701.7 ms, p95 704.6 ms | pass |
| Relay overhead (relay's measure) | p95 ≤ 30 ms | p95 0.20 ms | pass |
| Relay overhead (app side, sockets included) | p95 ≤ 30 ms | p95 4.74 ms | pass |
| Memory per session | no target in the plan | 7.6 MiB idle, 18.3 MiB with 50 open: about 219 KiB per session | reported |
| Clean shutdown | every app gets 1012, exit 0 | 50 × 1012, exit 0, 70 ms after SIGTERM, `stopped` logged | pass |
| Zero suggestions from my own channel | 0 | 0 | pass |
| No content in the relay's log | 0 lines | 0 lines (400 suggestion records checked) | pass |

### Headroom, relay on 2 threads

| Sessions × questions | Answers | First token p50 / p95 ms (Fast · Deep) | Relay overhead p95 ms | App-side overhead p95 ms | Memory (open, per session) | Shutdown |
|---|---|---|---|---|---|---|
| 50 × 8 | 400/400 | 351.9 / 359.4 · 701.7 / 704.6 | 0.20 | 4.74 | 18.3 MiB, 219 KiB | 50 × 1012, exit 0, 70 ms |
| 200 × 5 | 1000/1000 | 351.6 / 356.8 · 701.7 / 709.3 | 0.10 | 5.64 | 45.7 MiB, 200 KiB | 200 × 1012, exit 0, 67 ms |
| 500 × 4 | 2000/2000 | 351.4 / 354.4 · 701.3 / 703.5 | 0.10 | 2.07 | 100.8 MiB, 191 KiB | 500 × 1012, exit 0, 67 ms |

The first token is the mock's first-token delay (350 ms Haiku, 700 ms Sonnet) plus well under a millisecond at p50:
the relay adds almost nothing, and that does not change between 50 and 500 sessions.

### What changed because of this test

The first run showed the app-side overhead at 21–33 ms p95 while the relay's own share was 0.2 ms. A suggestion is a
run of small frames (`suggestion_start`, then a `token` per word), and with Nagle's algorithm on, a frame written
right after another waited for the first one's acknowledgement. The relay now sets `TCP_NODELAY` on every connection
(`e2e90a38`); the gap fell to 3–12 ms p95, and the rest is scheduling on a busy machine. **The apps should set
`TCP_NODELAY` on their side too** (the load test's client does): a partial and a final sent back to back hit the same
delay in the other direction.

## What this does not measure

- **Real models.** In this version the relay's live providers answer `provider_not_configured`; the streaming
  clients for Anthropic, OpenAI, Gemini and Groq are not built yet. TLS to the providers, their stream parsing and
  their real first-token times are not in these numbers. The real first-token times come from the L9 latency
  benchmark run from Pakistan (docs/LATENCY_V1_9.md).
- **The network between the app and the relay**, and TLS at the reverse proxy: one round trip from Pakistan to the
  chosen region (see `tools/latency-check.sh`) is added to every first token.
- **Long calls.** Each session here lasts about 20 seconds. In an hour-long call the transcript grows (about 60 KB of
  text for an hour of speech) and the documents can reach their limit (about 60,000 tokens, roughly 240 KB), so plan
  on about 1 MiB per session for the worst case (an estimate, not measured), still far inside 4 GB.
- **The server's internal API** (provider keys, usage and suggestion reports): no server in the run, so the report
  queue is off.
- **The speculative start and the Balanced upgrade under load**: covered by the session tests, not by this run.
