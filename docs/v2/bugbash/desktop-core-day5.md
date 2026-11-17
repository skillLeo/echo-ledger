# Desktop core bug bash, Day 5 (2026-11-17)

The core under the v2 screens: a long recording, a computer that sleeps mid-call, the Live Assist card across two
monitors, and the Live Assist audio side. Each check was made in the test harness where one exists, otherwise by
reading the code path end to end; nothing was run on a person's computer.

## What was checked

**60-minute recording soak.** `soak_an_hour_long_recording_stays_aligned_and_whole` in
`desktop/src-tauri/tests/recording_lifecycle.rs` (ignored by default; run it with
`cargo test --test recording_lifecycle soak -- --ignored`). It pushes one hour of 10 ms packets on both channels through
the real recorder, the WAV writers and the FLAC encoder. The system device's clock runs 50 ppm fast, the microphone's
packets arrive with up to 3 ms of jitter, and there is a pause halfway. It checks that both channels have the same
number of samples, the length is one hour within 2 s, and the upload file is written. Result: passed. 3,600,259 ms of audio were written and encoded, with both channels aligned. It took 10 minutes in a debug build, and memory peaked at 145 MB for the whole run: the encoder streams from the WAVs, so memory stays flat however long the call.

**Sleep and wake during a recording.** Traced through the recorder's timeline, the recording clock, the relay client
and the screen moments. A device clock that keeps running through sleep sent the recorder one packet stamped hours
after the last one, and the recorder filled the gap with silence (DC-01, fixed). The Live Assist connection is dead
after a long sleep: the client sees 1006 or a 4401 after it wakes, reconnects (with a new token when the old one has
expired) and, on `resumed: false`, sends the whole context again (tested in `assist_client.rs`). Captions reconnect
the same way.

**Two monitors with the Live Assist card.** The `assist` window is placed like the pill: top-centre of the primary
display's work area, or where it was dragged, when that place is still on a connected display. The window is in
`overlay::WINDOWS`, so the every-few-seconds rescue moves it back when its display is unplugged or moved, and its
click-through regions follow it. Its saved place is the window's top-left in the same units the pill uses
(physical pixels on Windows, points on a Mac). No defect found in the code. It still needs the two-monitor run on
the Mac and the Windows laptop (unplug a display with the card on it; drag it across; restart the app).

**Live Assist audio side.** The captions' finals and partials go through `assist::turns`. The other person's partials
go out as `turn_partial` with `silence_ms`, and their finals as `turn_final` with `start_ms` / `end_ms` on the
recording's clock. The microphone's partials never reach the relay; its finals go as context only. Tested in
`assist_client.rs` (the frames as the relay receives them) and `turns.rs`. `silence_ms` is approximated from unchanged
words (noted in the code).

**D-141** (from the desktop UI bug bash): the pill and the after-call card had fixed window sizes.

## Findings

| Id | Sev | Where | What | Status |
|---|---|---|---|---|
| DC-01 | S1 | Recorder, sleep and wake | A device clock that runs through sleep made the recorder write the whole sleep as silence: hours of empty audio in the file and the upload | Fixed: a gap over 60 s re-anchors the timeline like a resume, both channels evened out; a shorter gap is still kept as silence |
| D-141 | S3 | Pill and after-call card windows | At 200 % text they need about twice their fixed sizes | Fixed: sized from Windows' text size (Accessibility › Text size), up to twice the 100 % size; tested at 100, 150, 200 and 225 % |
| DC-02 | S2 | Recording clock after sleep, Windows | The pill's time and the moments of bookmarks and screens come from the app's clock. On Windows this clock may count the sleep; the file does not. A screen taken after a long sleep could then be stamped past the recording's end, and the server refuses a screen more than 60 s past it | To check on the Windows laptop (sleep 5 minutes mid-recording, then take a screen and a bookmark); fix the clock if it counts the sleep |
| DC-03 | S3 | Live Assist card, two monitors | No defect in the code; the two-monitor behaviour is unverified on a device | To check on both computers |
| DC-04 | S4 | Live Assist card position | The card's place is saved after any move, also the rescue's, so a card moved back from an unplugged display stays where it was moved to | Logged; by design for now |

No S0. DC-02 needs a device to confirm; it is not fixed blind.
