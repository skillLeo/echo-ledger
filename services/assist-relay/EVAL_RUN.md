# Running the L9 eval and latency check

Two runs that sign off Live Assist's quality (plan L9): the golden eval (ten call transcripts with traps, answered by
the real models and scored) and the latency benchmark. Both call paid APIs, so they need keys, and both run on the
relay's VPS, in the build folder that RELEASE.md step 1 made.

**Keys.** The keys saved in Admin → API Credentials stay where they are: these tools never read the server. Instead,
on the Anthropic and Groq websites, create a test key named `relay-eval` at each, paste them only at the hidden
prompts below (nothing shows while you paste; press Enter once), and delete both when you're done. Never put a key in
a command, a file or a chat.

## 1. The golden eval

```
cd ~/echoledger/services/assist-relay
target/release/assist-relay-eval --ci && echo CI-OK
read -rs ANTHROPIC_API_KEY && export ANTHROPIC_API_KEY
read -rs GROQ_API_KEY && export GROQ_API_KEY
target/release/assist-relay-eval --live --yes --judge --out ~/eval-balanced.md; echo "exit code $?"
unset ANTHROPIC_API_KEY GROQ_API_KEY
```

The first command is free and uses no key: it checks the set itself. It must print `10 transcripts and 3 practice
cases: …` and then `CI-OK`. If it doesn't, stop there.

The live run answers every transcript in Balanced, then has a second model (the judge) check each answer for invented
facts. It sends a few hundred small requests, one after another, so give it time; check each provider's usage page
afterwards for the cost. It writes its report to `~/eval-balanced.md`.

| You see | It means |
|---|---|
| `exit code 0` | **Pass.** The report's row "Overall with the judge: the L9 verdict" says `pass` (90 % or more). |
| `exit code 1` | Below the 90 % target. Not a pass: send the report to the developer. |
| `exit code 3` | No verdict: the judge didn't run. Run it again with `--judge` exactly as above. |
| `exit code 2` | Nothing was scored: a typing mistake in the command, or the set didn't load. Copy the command again; if it repeats, send the screen. |

Also look at the report's **Practice questions** table. A pass is `0` in both "Inventing" columns for every case.
Any other number is a question the practice mode made up something for; send the report either way.

If the report ends with "Turns that got no suggestion or an error" and they say `provider_not_configured`, a key was
missing at its prompt: run the commands from `read -rs` on again.

## 2. The latency benchmark

```
cd ~/echoledger/services/assist-relay
read -rs ANTHROPIC_API_KEY && export ANTHROPIC_API_KEY
read -rs GROQ_API_KEY && export GROQ_API_KEY
target/release/assist-relay-latency --from "VPS, <region>" --yes
unset ANTHROPIC_API_KEY GROQ_API_KEY
```

It takes a few minutes and writes `~/echoledger/docs/LATENCY_V1_9.md`. Then, on your own computer in Pakistan, on the
connection you use for calls, run this five times and note the middle `connect` value (in seconds):

```
curl -o /dev/null -s -w 'connect %{time_connect}s\n' https://<relay host>/healthz
```

**Pass**, in the report's table "Against the targets":

- the **Result** column says `pass` for fast, balanced and deep (this includes at least 80 % of input read from the
  cache), and
- for each of them, **Measured p50** plus your `connect` value is still at most the **Target p50** (1500 ms for fast,
  2000 ms for balanced, 3000 ms for deep).

## 3. Afterwards

1. Delete both `relay-eval` keys on the Anthropic and Groq websites.
2. Send the developer the three results: `~/eval-balanced.md`, the latency report from step 2, and your five
   `connect` lines. They contain the models' answers and timings, and no key.

L9 is signed off when the eval passes (exit code 0, practice columns at 0) and every latency row passes with the trip
from Pakistan added.
