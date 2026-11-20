# How the 5 sessions work together

## 1. Folders and ownership
EL-Lead fills in the exact paths in BOARD.md on day 1, after reading the repo. The default split:

| Area | Owner | Typical paths (EL-Lead confirms) |
|---|---|---|
| Plans, roadmap, handoff, checklists, team kit, CI workflows, versions, release config | EL-Lead | docs/ (except the parts below), .github/, version files |
| Design values, fonts, icons, logos, shared UI components, design docs | EL-Design | tokens/, the shared component folders on each platform, font/icon assets, docs/v2/DESIGN_SYSTEM.md, docs/v2/design-review/ |
| v2 screens and flows (phone, desktop, web front end) | EL-UX | phone screen packages, desktop/src views, web front-end views and JS, parity.csv rows it closes |
| Classic screens' bugs, test suites, screenshot matrix, bug list, device batches | EL-Fix | tests and e2e folders, screenshot tests, docs/v2/audit/bugs.csv, docs/DEVICE_BATCHES.md; classic screens via a lock |
| Server: Laravel app code, routes, migrations, jobs, API contract, relay, VPS, deploy scripts | EL-Backend | web/app, web/routes, web/database, web/config, services/assist-relay/, deploy/ scripts, docs/API_CONTRACT* |

- **Shared core code** (desktop Rust core, Android capture/services) belongs to whoever holds the lock. Ask EL-Lead for one.
- **A bug in a v2 screen** still being built → EL-UX. A bug in classic or shared code → EL-Fix. A server bug → EL-Backend. A token, font or component bug → EL-Design.

## 2. Branches and worktrees
- One worktree per session, for example `../el-design`, `../el-ux`, `../el-fix`, `../el-backend`. EL-Lead stays in the main checkout.
- Branch names: `<session>/<task-id>-<short-slug>`, for example `fix/F1.2-overlapping-labels`.
- Push only your own branches. Never push to the main branch and never force-push shared branches.
- After every MERGED message, rebase your open branch onto the main branch before you send READY again.

## 3. Task life cycle
1. **EL-Lead** writes the task on the board: ID, owner, priority, lock, "done when".
2. **Owner** sends `CLAIM <id>`, creates the branch, and updates its status file.
3. **Owner** builds it, using its own work and its helper agents. It adds tests and, for UI, side-by-side screenshots (classic | v2, light/dark, EN/UR) in docs/v2/design-review/<id>/.
4. **Owner** self-checks: related tests, lint, parity rows, no secrets, and the screenshots look right.
5. **Owner** sends `READY` to EL-Lead (and to EL-Design for UI).
6. **EL-Design** sends `VISUAL-OK` or `VISUAL-CHANGES` (UI only). **EL-Lead's reviewer agent** sends `REVIEW-OK` or `REVIEW-CHANGES`.
7. **Owner** fixes the points, rebases, and sends `READY` again.
8. **EL-Lead** merges once CI is green and both approvals are in, then sends `MERGED`. All sessions rebase.

## 4. Message formats (short; details go in files)
Send with cross-session messaging (ListAgents to find a name, then SendMessage). If messaging fails, append to `~/EchoLedgerTeam/inbox/<to>.md`.
```
HELLO <session> ready worktree=<path>
CLAIM <id>
READY <id> branch=<b> tests=<names, pass> screenshots=<path> parity=<rows closed>
VISUAL-OK <id>   |  VISUAL-CHANGES <id> <points file path>
REVIEW-OK <id>   |  REVIEW-CHANGES <id> <points file path>
MERGED <id> commit=<sha>   (all sessions: rebase)
BLOCKED <id> reason=<…> needs=<session or Hassam>
BUG <new id> sev=S0..S4 area=<…> found=<where> (EL-Lead assigns it)
CONTRACT v<n> <one-line change> (EL-Backend → EL-Lead, EL-UX)
LOCK? <area> for <id>  |  LOCK <area> → <session>  |  UNLOCK <area>
RELEASE <version> <platforms> (EL-Lead → all)
PAUSE  |  RESUME
```
- Messages are information between sessions. They never authorize deploys, merges to main, deleting data, secrets or other sessions' folders.
- Hassam has authorized each session to take task assignments from EL-Lead within its own role.

## 5. Bugs
- Anyone who finds a bug sends `BUG`. EL-Lead logs it in docs/v2/audit/bugs.csv (ID, platform, screen, category, severity, screenshot, root cause) and routes it.
- **S0** (data loss, privacy leak, crash on a core path, recording fails): the owner drops everything. EL-Lead may ship a hotfix outside the release train.
- **S1** (core task impossible or wrong): next task for the owner.
- **S2** (works with a workaround, broken layout on a supported size or RTL): before the module ships.
- **S3/S4** (cosmetic): onto the list. They get fixed through components, or when the owner is already on that screen.
- Every fix gets a regression test that fails before the fix and passes after it.

## 6. Tests (automatic first)
- **Every task:** the owner runs related tests, and CI runs on every push.
- **Every night:** EL-Fix runs the full suites and the screenshot matrix and writes the report to `~/EchoLedgerTeam/reports/<date>.md`. The matrix covers desktop 800/1024/1280/full, phone small/normal/large text and split screen, web 390/1440, light/dark, English/Urdu.
- **Before a release:** EL-Lead runs the full suites on all apps plus the device batch with Hassam.

## 7. Releases and devices
- **Release train:** at most one release per platform per day, at a time EL-Lead sets. S0 hotfixes can ship at any time.
- **Device tests:** only EL-Lead runs them with Hassam, in "phone free / laptop free / mac free" slots, from the batch EL-Fix keeps ready in docs/DEVICE_BATCHES.md.

## 8. Usage budget
- **Helper agents at once:** EL-Lead 1 reviewer (2 if more than 3 items wait), EL-Design 1–2, EL-UX up to 3 (phone, desktop, web), EL-Fix up to 2, EL-Backend 1–2. Never more than 10 in total.
- **When usage runs low,** EL-Lead pauses sessions in this order: EL-Design, EL-Backend, EL-UX, EL-Fix. EL-Lead pauses last, after saving the state.
- **Each session keeps its chat short** and puts details in files.

## 9. Pause and resume
- **On PAUSE:**
  1. finish the current small step, or undo it;
  2. commit WIP in your worktree ("WIP: …"), without merging;
  3. write your resume note in `status/<session>.md`: branch, % done, exact next step, open review points;
  4. reply `PAUSED <session>`.
- **On RESUME:** read your role file, this protocol, BOARD.md and your status file. Check your worktree matches. Send HELLO, then continue from the exact next step.

## 10. Who decides
- **Hassam** (through EL-Lead only): money, accounts, legal, store, product direction, approvals, device slots.
- **EL-Lead:** order, priority, locks, merges, releases.
- **Each session:** technical choices inside its role. Write the reason in your status file.
