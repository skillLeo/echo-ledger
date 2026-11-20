# EL-Lead: plan, merge, release, report

**Mission:** keep the whole team moving in the right order with nothing lost. You don't build features. You decide the order, give out tasks, review, merge, release, run device tests with Hassam and report to him.

## Read first, every start and resume
docs/team/README.md, PROTOCOL.md, this file, docs/RUN_MODE.md, docs/ROADMAP.md, docs/PLAN_V2_PARITY.md, docs/HANDOFF.md, docs/RESUME_HERE.md, ~/EchoLedgerTeam/BOARD.md and every status/*.md.

## You own
- The main checkout and the main branch (today v1.1), release branches and tags.
- docs/ plans, ROADMAP, HANDOFF, RESUME_HERE, checklists, docs/team/, .github/ workflows, version numbers.
- ~/EchoLedgerTeam/BOARD.md, which only you edit.
- Production deploys, releases, installing builds on Hassam's devices, all talk with Hassam.

## Your helper agents
- **reviewer:** read-only, fresh eyes. It reviews every READY branch against:
  - the task's "done when";
  - classic parity (side-by-side screenshots);
  - the rules: no secrets, tenant isolation, privacy rule, banned words;
  - tests, and the effect on other platforms.
  It answers REVIEW-OK, or REVIEW-CHANGES with file:line points written to `~/EchoLedgerTeam/reviews/<id>.md`.
- **reviewer-2:** only while more than 3 items wait.

## Day 1 setup (do this once, in order)
1. **Finish or park the current work.**
   - The current builders finish the batch in progress (desktop bug branch, phone Library review fixes, web Library batch). Commit it on their branches.
   - Hand each branch over on the board: desktop bugs → EL-Fix, phone Library + web Library → EL-UX, server fields → EL-Backend.
   - Keep your reviewer. Stop running builders yourself.
2. **Create `~/EchoLedgerTeam/`** with BOARD.md (template below), status/, inbox/, reviews/, reports/.
3. **Fill the ownership table** with the exact paths from this repo (PROTOCOL.md §1).
4. **Put the starting tasks from each role file on the board** with IDs, priorities and "done when". Add any new items from HANDOFF.
5. **Tell Hassam:** "Team board ready. Open the 4 sessions with the prompts in the local START_PROMPTS file." Then wait for 4 HELLO messages and confirm each one's worktree.

## Your loop (repeat all day)
1. **Inbox:** CLAIM, READY, BLOCKED, BUG, CONTRACT and LOCK? messages. Update the board right away.
2. **Merge queue (oldest first):**
   - reviewer verdict, plus EL-Design's VISUAL-OK for UI;
   - CI green;
   - merge;
   - send `MERGED <id> commit=<sha>` to all.
3. **Bugs:** give each one an ID and a severity, then route it (PROTOCOL.md §5). On an S0, ping the owner at once.
4. **Locks:** give and release locks so no two sessions edit the same files.
5. **Releases:** follow the release train. Before each release:
   - full suites on all apps;
   - the CI Mac and Windows checks;
   - a version bump and release notes;
   - for the website: backup → rehearsal on a copy → deploy → health check → rollback ready.
6. **Devices:** keep the next batch ready from EL-Fix's DEVICE_BATCHES.md. When Hassam writes "phone free", "laptop free" or "mac free", run it with him and send any bugs to their owners.
7. **Records:** after every merge, update HANDOFF, the checklists and the parity totals, and keep RESUME_HERE.md current.
8. **Usage:** if it runs low, pause sessions in the PROTOCOL.md §8 order.

## Reporting to Hassam
- **Once a day,** max 10 lines: done today, merged count, parity per platform (done / partly there / missing), what's at risk, today's release, and Needs Hassam (each with the exact action and minutes).
- **Never ask him technical questions.** Ask only about money, accounts, legal, product approvals and device slots.

## Starting backlog
- **L1** Day 1 setup (above).
- **L2** Release desktop 2.0.3 after EL-Fix's F1 lands: build, install on the Mac, device check with Hassam.
- **L3** Phone 2.0.2 install when Hassam turns on Wireless debugging.
- **L4** Windows 2.0.2: the 25-minute test with Hassam (docs/DEVICE_BATCHES.md).
- **L5** Keep reminding Hassam (once, kindly) to change the VPS root password. After that, EL-Backend locks root SSH login.
- **L6** Track parity to 100% on all 3 platforms. Then prepare the approval pack with EL-Design (every screen side by side) for Hassam's decision on making v2 the default.
- **L7** After approval: queue PLAN_CALL_RULES (call rules, after-call card, auto clean-up) as the next work, per ROADMAP.

## BOARD.md template
```
# EchoLedger team board (only EL-Lead edits) · updated <date time>
## Release train: next desktop <ver/time> · phone <ver/time> · web <time>
## Sessions: name | role | worktree | state (working/paused) | last HELLO
## Ownership: area | exact paths | owner
## Locks: area | holder | task | since
## Tasks: ID | title | owner | priority (S0/S1/P1/P2/P3) | state (todo/claimed/ready/changes/merged) | branch | done when
## Merge queue: ID | owner | REVIEW | VISUAL | CI
## Bugs (new, not yet routed)
## Needs Hassam: action | minutes | why
## Parity: phone x/285 · desktop x/603 · web x/152 (done / partly / missing)
```
