# Resume here

Paused **2026-11-18 03:52 PKT** at the owner's request, for 1–2 days. All work is stopped:
- nothing is half-merged and nothing is mid-deploy;
- production is up and not in maintenance mode;
- every worktree is clean and every branch is pushed.

Read this file first, then `docs/HANDOFF.md`, then the local plans (`docs/RUN_MODE.md`, `docs/ROADMAP.md`, `docs/PLAN_V2_PARITY.md`, `docs/PLAN_CALL_RULES.md`). The local plans are excluded from git and live only on this Mac.

## 1. Branches and worktrees

Integration branch: **`v1.1` at `cb7f1d68`** (main checkout). `main` is at `3c42792c`.

| Worktree | Branch | Commit | State |
|---|---|---|---|
| (main checkout) | v1.1 | cb7f1d68 | integration; everything reviewed so far is merged here |
| .claude/worktrees/b-desktop-share-default | b-desktop-share-default | d88e6767 | **approved, not merged**: screen shares per window + caption latency probe; contains v1.1 up to 144c5514 |
| .claude/worktrees/b-desktop-parity | b-desktop-parity | 1a9641bd | merged into v1.1 (893824cf); next item not started |
| .claude/worktrees/b-phone | b-phone-parity-recording | 5ea084d9 | phone v2 recording page + review fixes; **fixes not yet re-reviewed, full suite not finished** |
| .claude/worktrees/b-phone-ui | b-phone-parity | e7b12b6c | phone switch/font/navigation work; WIP commit 25f97084; **status unreported, tests unknown** |
| .claude/worktrees/b-web-l7 | b-web-caption-speed | 1a1e446c | merged and deployed (144c5514); next: web parity audit |
| .claude/worktrees/b-assist-http | b-assist-http | 30198b57 | merged and deployed |
| .claude/worktrees/b-desktop-followup | b-desktop-core-parity | 86a117c3 | merged via b-desktop-parity |
| .claude/worktrees/rel-181 | release/v1.8.1 | 203c581d | tag v1.8.1 |
| /private/tmp/el-200 | release/v2.0.1 | 3d6444cb | local 2.0.1 build source (installed on the Mac and phone) |
| /private/tmp/el-phone | release/v1.9.0 | 799f6d86 | old release worktree |
| .claude/worktrees/review-* | detached | various | read-only review checkouts; safe to remove |
| b-desktop-ui, -ui-flake, -ui-notices, -ui-replace-notice, agent-* | various | various | older, merged or idle |

## 2. What is live

| Where | Version | Notes |
|---|---|---|
| Website (production) | v1.1 at **144c5514** | Classic for everyone. All 11 `ui_v2` flags are Opt-in ("Try the new design"). `live_assist` is on for everyone. Live Assist connection: Dedicated relay (VPS). Caption speed: Fastest, 80 ms pause, Fast English captions OFF. |
| Production backups | `~/echoledger-backups/pre-v1.1-144c5514-20261117-214333` (latest), `pre-v1.1-be5e7c2e-20261117-203055` | Rollback scripts: `~/v12d-rollback.sh` (caption speed), `~/v11d-rollback.sh [db]` (design switch). |
| Live Assist relay | on the VPS (systemd `assist-relay`, nginx TLS) | Pins: current and backup recorded in HANDOFF. |
| Mac (owner) | **2.0.1** (ad-hoc signed, built from release/v2.0.1) | Installed 2026-11-17. |
| Phone S25 (owner) | **2.0.1** (code 21, debug-signed) | Installed over Wi-Fi with `install -r`. |
| Windows | **1.8.2** on GitHub Releases | Not yet tested on the owner's laptop. |
| GitHub releases | v1.8.1, v1.8.2 (Mac DMGs) | 2.0.x is local builds only. |

v1.1 on GitHub (cb7f1d68) is **ahead of production**: it also has desktop v2 Library work and phone Live Assist follow-ups, which are app-side only. The web is unchanged since 144c5514.

## 3. Parity (v2 must match classic)

Source of truth: **`docs/v2/audit/parity.csv`**. Columns: id, platform, screen, feature, how to reach it in classic, where it lives in v2, status, test that proves it. Each branch carries its own rows, so after merging the combined file is the union by id prefix: DT (desktop), PH (phone), WEB (web).

| Platform | Done | Partly there | Missing | Total | Done % |
|---|---|---|---|---|---|
| Desktop (b-desktop-parity) | 372 | 79 | 152 | 603 | 62% |
| Phone (b-phone-parity-recording) | 103 | 114 | 68 | 285 | 36% |
| Web | 1 | 4 | – | audit just started | – |

Desktop by screen (done / partial / missing):

| Screen | Done | Partial | Missing |
|---|---|---|---|
| Settings | 161 | 1 | 0 |
| Library | 89 | 12 | 38 |
| Recording page | 68 | 14 | 64 |
| Project page | 14 | 5 | 1 |
| Command palette | 9 | 8 | 13 |
| Sidebar | 4 | 6 | 10 |
| Top bar | 7 | 6 | 6 |
| Record | 5 | 9 | 4 |
| Activity | 0 | 4 | 7 |
| Other small screens | 15 | 14 | 9 |

Phone by screen (done / partial / missing):

| Screen | Done | Partial | Missing |
|---|---|---|---|
| Recording page | 54 | 17 | 3 |
| Settings | 31 | 59 | 4 |
| Library | 6 | 20 | 50 |
| Projects | 2 | 5 | 0 |
| Record | 0 | 6 | 0 |
| Activity | 0 | 0 | 6 |
| Navigation | 0 | 1 | 4 |
| Other | 10 | 6 | 1 |

Side-by-side pictures: `docs/v2/parity/desktop/`, `docs/v2/parity/web/`, `docs/v2/gate3/phone/`.

## 4. Each line of work and its next step

| Line | Task | % done | Worktree / branch | Exact next step |
|---|---|---|---|---|
| Desktop core | Screen shares per window; caption latency | 100% (approved) | b-desktop-share-default @ d88e6767 | Wait for CI (Desktop macOS + Windows) on d88e6767 → merge into v1.1. Then two small optional test follow-ups (already done in 8a930017). Then build **2.0.2** from v1.1 and install it on the Mac. |
| Desktop UI | v2 parity, desktop | 62% | b-desktop-parity @ 1a9641bd | Merge v1.1 in first. Then real source logos → sidebar (plan §2) → Settings group icons and descriptions → command palette → recording-page editing, find and replace, speakers, history → Library table view and search inside transcripts. |
| Phone core | v2 recording page | 73% of its 74 rows done | b-phone-parity-recording @ 5ea084d9 | Run the full suite on 5ea084d9, then send it for re-review against the earlier points (§5). After approval, merge into v1.1. |
| Phone UI | Design switch, font, bottom bar, Library, Settings | unknown, last counted 36% overall | b-phone-parity @ e7b12b6c | First get a status: diff 25f97084 against its base, run the v2 test package (25-min limit, own runner folder), count PH rows. Then wire `EchoV2Shell.kt:223` (`startAtMs`, `backToSource`, `onCaptureTest`, `onOpenSettings` for V2RecordingScreen). Then Library selection mode, ⋮ menu, filters, bottom bar (Library · Projects · ● Record · Live · Ask). |
| Web | Web parity audit | ~0% (5 rows) | b-web-l7 (b-web-caption-speed) | Merge v1.1 in, write WEB rows into parity.csv, build shared pieces first (selection mode, ⋮, Export, Copy menus), then daily-use rows. |

## 5. Waiting for review, and review points not yet fixed

- **b-phone-parity-recording @ 5ea084d9**: needs re-review. The earlier points it claims to fix:
  - Undo after Delete must work after the page has gone (toast and restore on a scope that outlives the page);
  - the delete test must tap Undo and check `POST …/restore`;
  - PH-REC-016/020 marked partial until the shell is wired;
  - no player and no Share on no-audio recordings;
  - Change language & re-transcribe only with a final transcript and audio;
  - "Transcribing…" while in progress;
  - `failureWords(errorCode)`, never raw server text;
  - one source for retranscribe/restore messages;
  - pictures of the no-audio card, failed banner, steps, backup-engine banner, delete dialog and Undo toast.
- **Phone Live Assist, minors not fixed** (merged; for later):
  - `HttpLiveClient.kt:323,348`: 409 session_active says "Trying again" but never re-sends;
  - `V2AssistStartScreen.kt:183-184`: `Start.Refused`/`Failed` show English text on Urdu screens.
- **Desktop screen share, optional test follow-ups**: done in 8a930017 (included in d88e6767).
- **Desktop parity, minors**: all folded in at 1a9641bd. The ElevenLabs fixture test (`cargo test --test models_fixtures`) did not run locally because the disk was full. CI must confirm it.
- **Web design switch** (deployed): one comment-only minor was folded in. Nothing open.

## 6. Plan from here

| When | What |
|---|---|
| First hour | Merge b-desktop-share-default after CI. Build and install Mac 2.0.2. Re-review and merge the phone recording page. |
| Day 1–2 | Phone: design switch, font, bottom bar, Library selection and menus. Desktop: source logos, sidebar, command palette. Web: full audit, then the shared menus. |
| Day 2–3 | Phone: Library filters, Settings, Activity. Desktop: recording-page editing, speakers, history; Library table view; search inside transcripts. Web: daily-use rows. |
| Day 4–5 | Rare options and the remaining partial rows on all three platforms. |
| Day 5–6 | Final check: every screen side by side; full tests on Mac, Windows and phone; install new builds; **owner approves**. |
| After approval | v2 becomes the default. PLAN_CALL_RULES resumes. Web b-web-call-rules 55080ba9 and desktop 6ad79c71/492e79fb are waiting. |

Order rules (owner):
1. Parity comes before any other v2 module. Classic stays the default until 100% parity and the owner's approval. Only the owner can approve "retired".
2. Daily use first: multi-select, delete, move, copy, language switch, export. Shared parts first: selection mode, ⋮ menu, export menu, copy menu. Rare options last.
3. Report done / partly there / missing counts every day.
4. No v2 merge without parity rows and side-by-side pictures. Every change gets a separate review.
5. Screen share:
   - The main window is shown by default; hiding has durations.
   - The Live Assist card and captions are hidden by default, always. They are shown only from their own badge, for that window's life. The badge shows the real OS state.
6. The Live Assist relay runs only on the VPS. Shared hosting is a manual emergency choice.
7. Captions: target under 1 s from speech to words on screen; measure it.

## 7. Open bugs the owner reported

| Severity | Bug | State |
|---|---|---|
| S1 | Desktop main window black / scrolling not working | Not reproduced. Library scrolling fix is in 2.0.1. Waiting on the owner: which screen, trackpad or mouse. |
| S1 | Captions lag 30–60 s | Fixed. Real provider: p50 0.56 s / p90 1.11 s (English), p50 0.77 s / p90 1.20 s (Urdu+English). Fast English captions (off) can lower English further. |
| S1 | Screen share shown/hidden defaults | Fixed and approved on b-desktop-share-default. Ships in 2.0.2 (not built yet). |
| S2 | Chrome missing from the Live Translate list on Mac | Open, not yet investigated in this run. |
| S2 | "The plan field is required" on promo codes | Fixed and live. |
| S3 | `/pricing` returns 404 for visitors (v2 website page while website flag is Opt-in) | Known side effect; no classic link to it. |
| — | Flaky tests P-101 (WorkspaceTest Files tab), WorkspaceParityTest "40% advance" | P-101 logged in bugs.csv; the second is not logged yet. |

## 8. Waiting on the owner

- Test **1.8.2 on the Windows laptop**.
- **Change the VPS root password.** It was shared in chat. Optionally regenerate the relay secret, and save the relay spare key.
- Try a Live Assist practice session.
- Tell us which desktop screen goes black or won't scroll, and with trackpad or mouse.
- Decide whether bulk Delete in v2 should be one step with Undo (current; asks first above 20) or classic's two-step confirm.
- Approve v2 at 100% parity (later).

## 9. Watch out when restarting

- **Disk space.** The Mac drops to 2–6 GB free several times a day, and builds and tests then fail with "No space left on device". Before starting, check `df -h /`. Safe to clear:
  - `~/.gradle/caches/build-cache-1`;
  - `target/debug` of idle desktop worktrees;
  - `/private/tmp/el-lt` (old);
  - robolectric `nativeruntime*` temp folders.
- **Every git command needs** `GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0='*'`. Commits are by skillLeo only, with no trailers. Never use bare `git stash`.
- **CI cancels older runs on a branch when a newer commit is pushed.** A merge waiting on an older commit's CI will wait forever, so always wait on the branch head.
- **parity.csv conflicts on every merge** (add/add). Resolve as the union of rows: keep the header `…,status,test that proves it`, then PH, WEB and DT rows.
- **Phone builders share a test runner script in the scratchpad.** One overwrote the other's once, so each must use its own runner folder.
- **Phone rules.** `pm install -r` only; never uninstall or clear data. Desktop installs replace `/Applications/EchoLedger.app` after a graceful quit.
- **v1.1 on GitHub is ahead of production** (app-side only). The next web deploy will include nothing new from the web unless web branches are merged first.
- **No temporary tokens are left on the server.** The latency-check token was revoked, and its sessions were deleted.
