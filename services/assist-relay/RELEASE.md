# Releasing the Live Assist relay

The first release of the relay on its VPS, in six steps, in this order. Each step says what to type, what you should
see, and what to do if you don't. Don't skip ahead: until step 6, no one but you can reach Live Assist, so a mistake
on the way costs nothing.

DEPLOY.md has the full reference (every setting, the hardening, rotation, rollback). This page is the path through it.

**Before you start**

- The VPS (Ubuntu 24.04, 2 vCPU, 4–8 GB) is running, and you can log in to it over SSH as a user with `sudo`.
- The relay's name (in this page `<relay host>`, for example `relay.<your domain>`) points at the VPS's address in
  DNS. Check from your computer: `ping <relay host>` shows the VPS's address.
- You can sign in to the console as the super admin.
- The Anthropic and Groq keys are saved in Admin → API Credentials, and you can copy them again from the providers'
  websites (step 4 needs them for one command each).

**Rules for every step**

- Wherever this page says `<relay host>` or `<region>`, type your own value, without the `< >`.
- A secret or a key is only ever pasted at a hidden prompt (`read -rs …`): nothing shows while you paste. Press Enter
  once after pasting. Never put one in a command line, a file, a ticket or a chat.
- If a check doesn't show what this page says, stop there and send the last 30 lines of the screen to the developer.

## 1. Install

On the VPS, install the tools, the relay's user and its folders (DEPLOY.md section 1):

```
sudo apt update && sudo apt install -y nginx certbot python3-certbot-nginx build-essential pkg-config git curl
sudo useradd --system --no-create-home --shell /usr/sbin/nologin assist-relay
sudo mkdir -p /opt/assist-relay/releases /etc/assist-relay /etc/credstore.encrypted
sudo chmod 0700 /etc/credstore.encrypted
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable
```

Build the relay on the VPS (about 10 minutes). The developer gives you the repository's address and the release's
tag:

```
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y && . "$HOME/.cargo/env"
git clone <repository address> ~/echoledger && cd ~/echoledger && git checkout <release tag>
cd services/assist-relay
cargo test --locked && cargo build --release --locked
```

You should see `test result: ok` several times and no `FAILED`, then `Finished release`. Install it:

```
REL=$(git rev-parse --short HEAD)
sudo install -D -m 0755 target/release/assist-relay /opt/assist-relay/releases/$REL/assist-relay
sudo ln -sfn /opt/assist-relay/releases/$REL /opt/assist-relay/current
```

Create the settings file `/etc/assist-relay/relay.env` (`sudo nano /etc/assist-relay/relay.env`) with these three
lines, the server's address in the second:

```
RELAY_BIND=127.0.0.1:8787
RELAY_SERVER_URL=https://<your server's address>
RELAY_PROVIDERS=mock
```

`mock` is on purpose: the relay answers with canned text until step 4 proves the real models work.

Create the service file `/etc/systemd/system/assist-relay.service` with the text in DEPLOY.md section 5, word for
word, then `sudo systemctl daemon-reload`. Don't start it yet: it needs the secret.

## 2. The secret from Admin

1. In the console: Admin → API Credentials → **Live Assist relay** → **Generate**, then **Save credentials**.
2. Press the copy button next to **Relay secret**.
3. On the VPS, paste it at the hidden prompt this command opens. It goes straight into an encrypted file that only
   this VPS can read:

```
read -rs RELAY_SECRET_INPUT && printf '%s' "$RELAY_SECRET_INPUT" \
  | sudo systemd-creds encrypt --name=relay_secret - /etc/credstore.encrypted/relay_secret; unset RELAY_SECRET_INPUT
```

Start the relay and check it:

```
sudo systemctl enable --now assist-relay
curl -fsS http://127.0.0.1:8787/healthz
journalctl -u assist-relay -n 20 -o cat
```

You should see `{"status":"ok",…}`, and in the log a `listening` line, then `providers_fetched`. If you see
`providers_refused` instead, the secret on the VPS isn't the one saved in Admin: repeat this step.

## 3. The certificate and its pins

The apps only connect to a relay whose key they recognise (its "pins"). You make the key now, keep it for good, and
give the console its fingerprint, plus a spare key's fingerprint for the day the first one must change.

**The certificate.** `--reuse-key` keeps the same key at every renewal, so the pins stay valid. Never request a
certificate for this name without it.

```
sudo certbot certonly --nginx -d <relay host> --reuse-key
sudo grep reuse_key /etc/letsencrypt/renewal/<relay host>.conf
```

You should see `Successfully received certificate`, then `reuse_key = True`.

**The web server.** Create `/etc/nginx/sites-available/assist-relay` with the text in DEPLOY.md section 6 (with your
`<relay host>` in every place it appears), then:

```
sudo ln -s /etc/nginx/sites-available/assist-relay /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
curl -fsS https://<relay host>/healthz
```

You should see `syntax is ok`, then `{"status":"ok",…}` again, this time over the internet.

**The spare key.** Made once, kept on the VPS where only root can read it. Also save a copy in your password manager
(as a file attachment): it is the only way to move the relay to a new VPS without updating the apps.

```
sudo mkdir -p -m 0700 /etc/letsencrypt/relay-backup
sudo openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out /etc/letsencrypt/relay-backup/backup.key
sudo chmod 0600 /etc/letsencrypt/relay-backup/backup.key
```

**The two pins.** Each command prints one line of 44 characters ending in `=`:

```
sudo openssl pkey -in /etc/letsencrypt/live/<relay host>/privkey.pem -pubout -outform der | openssl dgst -sha256 -binary | openssl base64
sudo openssl pkey -in /etc/letsencrypt/relay-backup/backup.key -pubout -outform der | openssl dgst -sha256 -binary | openssl base64
```

Check the first one from outside. This must print the same line as the first command:

```
openssl s_client -connect <relay host>:443 -servername <relay host> </dev/null 2>/dev/null \
  | openssl x509 -pubkey -noout | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | openssl base64
```

**In the console:** Admin → API Credentials → **Live Assist relay**:

- **Relay address**: `<relay host>` (the name only, no `https://`).
- **Relay certificate pins (SPKI SHA-256)**: add two entries, `sha256/` followed by each line above, current key
  first, spare key second.
- **Save credentials.**

The pins are public fingerprints, not secrets. The spare key file itself is a secret: it never leaves the VPS
except into your password manager.

## 4. The smoke-record gate

This proves each real model answers through the relay's own code before anyone relies on it. Each command sends one
or two small paid requests (a fraction of a cent). On the VPS, in the build folder:

```
cd ~/echoledger/services/assist-relay && mkdir -p ~/smoke-records
read -rs ANTHROPIC_API_KEY && export ANTHROPIC_API_KEY
target/release/assist-relay-smoke --model claude-haiku-5-5 --warm --yes --record ~/smoke-records/anthropic_answer.sse && echo GATE-OK
target/release/assist-relay-smoke --model claude-sonnet-5-5 --yes --record ~/smoke-records/sonnet.sse && echo GATE-OK
target/release/assist-relay-smoke --model claude-opus-5-5 --yes --record ~/smoke-records/opus.sse && echo GATE-OK
unset ANTHROPIC_API_KEY
read -rs GROQ_API_KEY && export GROQ_API_KEY
target/release/assist-relay-smoke --model openai/gpt-oss-120b --yes --record ~/smoke-records/groq_answer.sse && echo GATE-OK
unset GROQ_API_KEY
```

At each hidden prompt, paste that provider's key (the same one saved in Admin → API Credentials). It is used for these
commands only and never saved.

The gate passes when **every** command ends with `GATE-OK`, after a `first token … ms` line, an answer, and a line
`input … output …; cost about $…`. For the first one (`--warm`), in the second request's line the `cached` number is
larger than the `input` number before it (most of the request was read from the cache). If the console's model catalog enables another model, run the same command for it with its provider's key.

If any command doesn't end with `GATE-OK`, stop: the relay stays on `mock`, and the developer needs the screen.

When all pass, send the four files in `~/smoke-records` to the developer (they hold the models' answers and no key),
then switch the relay to the real models:

```
sudo sed -i 's/^RELAY_PROVIDERS=mock$/RELAY_PROVIDERS=live/' /etc/assist-relay/relay.env
sudo systemctl restart assist-relay
journalctl -u assist-relay -n 5 -o cat
```

You should see `listening` and `providers_fetched` again.

## 5. The latency run

This measures how fast each model starts answering, from the VPS, where the relay's calls start. About 12 requests
per model, a few cents in all.

```
cd ~/echoledger/services/assist-relay
read -rs ANTHROPIC_API_KEY && export ANTHROPIC_API_KEY
read -rs GROQ_API_KEY && export GROQ_API_KEY
target/release/assist-relay-latency --from "VPS, <region>"
target/release/assist-relay-latency --from "VPS, <region>" --yes
unset ANTHROPIC_API_KEY GROQ_API_KEY
```

The first run only lists the models it will measure. The second writes `~/echoledger/docs/LATENCY_V1_9.md`.

Then, from your own computer in Pakistan, on the connection you'll use for calls, measure the trip to the relay. Run
it five times and note the middle `connect` value:

```
curl -o /dev/null -s -w 'connect %{time_connect}s  tls %{time_appconnect}s\n' https://<relay host>/healthz
```

Every suggestion pays this trip once on top of the model's first token. The targets are, with the trip added: the
middle (p50) first token at most 1.5 s in Fast, 2.0 s in Balanced and 3.0 s in Deep. Send `LATENCY_V1_9.md` and your
five `connect` lines to the developer. If a target is missed, don't go on to step 6: tell the developer first.

## 6. Live Assist on, for you only

1. In the console: Admin → System → **Interface flags** → the **Live Assist** section (`live_assist`):
   - **On for everyone**: off.
   - **Share of accounts**: `0`.
   - **Workspaces**: empty.
   - **People**: your own email only.
   - **Save**.
2. Close and reopen the app on your phone or desktop (it reads the setting when it starts). Live Assist now appears
   for you, and for no one else.
3. Start a Practice session and answer a question. A suggestion should appear within a couple of seconds. On the VPS,
   `journalctl -u assist-relay -n 20 -o cat` shows `attached`, then a `suggestion` line.

If the app says it can't verify the relay's certificate, the pins in step 3 don't match: check both entries, and
that the first one equals the `s_client` line.

**To switch it off again:** remove your email under **People** and save. Live Assist disappears from your apps the
next time they start; recording and everything else carry on as before. To go back to an earlier relay version, see
DEPLOY.md section 10.

Opening Live Assist to others (a workspace, a share of accounts, or everyone) is a later decision, made in the same
section, once you have used it on real calls.
