# Testing EchoLedger on a rented Mac

For testing the Mac app on a real Mac when you don't own one. It takes about a day and costs about €6. I drive the
Mac over SSH; you click the permission dialogs over screen sharing, because macOS only accepts those from a person.

Prices and steps were checked on 2026-11-06. Scaleway's documentation could not be read by my tools, so anything marked
*(check)* comes from memory: go by what the Scaleway console shows.

## 1. What it costs

Scaleway rents Mac minis by the hour with a **24-hour minimum**, because of Apple's licence. Its M4 Mac mini is listed
from **€0.22 an hour**, so a day is about **€5.30** plus tax. Billing goes on after the first 24 hours until you delete
the machine, so delete it when we are done (step 7).

- [Scaleway Mac mini M4](https://scaleway.com/en/mac-mini-m4/)
- [Scaleway Apple silicon FAQ](https://www.scaleway.com/en/docs/apple-silicon/faq)

Choose a time when you can answer within a few minutes for about two hours. The rest of the day I work alone.

## 2. Rent the Mac (you, about 10 minutes)

1. Make an account at [console.scaleway.com](https://console.scaleway.com) and add a payment method.
2. **Apple silicon → Create a Mac mini.** Choose the cheapest M-series model and the newest macOS on offer. Any region.
3. Wait until it says **Ready**. Its page shows the SSH address, the screen-sharing (VNC) address and a password *(check)*.
4. On the same page, add this Mac's SSH public key if it asks for one. I give you the key when you say "Mac rented".

## 3. Tell me (one message)

Say **"Mac rented"** and send the **IP address and user name only**. Keep the password to yourself: type it into your
own screen-sharing window, never into chat. I add the machine to `~/.ssh/config` and reply with the exact next steps.

## 4. Connect screen sharing (you)

- On a Mac: Finder → Go → Connect to Server → `vnc://<IP address>`.
- On Windows: any VNC viewer (TigerVNC, RealVNC), address `<IP address>:5900` *(check)*.
- Sign in with the user name and password from the Scaleway page.

Leave the window open. When I say "click Allow", the dialog is in that window.

## 5. What I set up over SSH

```sh
brew install blackhole-2ch           # a virtual sound device: a rented Mac has no speakers or microphone
sudo killall -9 coreaudiod           # makes macOS see it
```

The newest DMG from GitHub Actions goes to `/Applications`. BlackHole stands in for the speakers and the microphone:
what a meeting plays goes into it, and a speech file played into it counts as your voice
([BlackHole](https://github.com/ExistentialAudio/BlackHole)). In Audio MIDI Setup both of its volumes must be all the
way up, or it stays silent.

## 6. The test (about 2 hours, together)

1. First launch, as a new user would: System Settings → Privacy & Security → **Open Anyway**. You click it.
2. Onboarding: one permission at a time. You click **Allow** for the microphone, system audio and screen recording.
3. **Help & diagnostics → Run Mac check.** Every line should pass. I copy the report and send it to the server.
4. A simulated Meet call: Chrome on the Mac joins a Google Meet that you start on your phone or laptop.
   - You talk or play something on your side, and share your screen with a few slides.
   - I play a speech file into BlackHole on the Mac as "my" voice.
5. Afterwards we check: both sides in the recording, screen moments at the right times, and the meeting pack opened in
   Claude.
6. The Mac rows of [MANUAL_QA.md](MANUAL_QA.md): "Mac app", "Screen moments v1.5" and "Accounts on the computer".

Anything that fails, I fix, push, wait for the new DMG and repeat while the Mac is still rented.

## 7. Finish (you, 2 minutes)

1. Scaleway console → your Mac mini → **Delete**. It can only be deleted once the 24 hours are over.
2. Check that the list is empty. Billing stops when the machine is gone.

## What a rented Mac cannot prove

- Real speakers, a real microphone and Bluetooth headsets: BlackHole stands in for them.
- The notch: rented Mac minis have none, so the pill's position under a notch stays a check for a real MacBook.
- Intel Macs: CI runs the tests there, but no one has clicked through the app on one.
- A Developer ID build: without the $99/year Apple account, macOS may forget the permissions after each update
  ([SIGNING.md](SIGNING.md)).
