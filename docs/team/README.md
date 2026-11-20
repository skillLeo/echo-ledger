# EchoLedger team: 5 working sessions

Five sessions work at the same time, each with one job. Every session works itself and also runs its own helper agents. One session, the Lead, keeps the plan, merges the work and talks to Hassam.

```
                         HASSAM
          (decisions, money, devices, approvals)
                            │  talks only to
                            ▼
                ┌────────────────────────┐
                │        EL-Lead         │
                │  board · merges ·      │
                │  releases · devices    │
                │  + reviewer agent      │
                └───────────┬────────────┘
      tasks ▼  ▲ READY / BLOCKED / BUG messages
   ┌───────────┬───────────┬───────────┬───────────┐
   │ EL-Design │  EL-UX    │  EL-Fix   │EL-Backend │
   │ look,     │ v2 screens│ bugs,     │ server,   │
   │ components│ + parity  │ tests, QA │ API, VPS  │
   └───────────┴───────────┴───────────┴───────────┘
```

| Session | One-line job | Role file |
|---|---|---|
| **EL-Lead** | Plans, assigns, reviews, merges, releases, runs device tests with Hassam, reports | LEAD.md |
| **EL-Design** | The design system: tokens, fonts, icons, shared components, visual review of every UI change | DESIGN.md |
| **EL-UX** | Builds the v2 screens and flows on phone, desktop and web until every classic feature is there | UXUI.md |
| **EL-Fix** | Bugs (big and small), automatic tests, screen checks, device test lists | FIXING.md |
| **EL-Backend** | Laravel server, API, database, Live Assist relay, VPS, deploy scripts | BACKEND.md |

## The 12 rules everyone follows
1. **Own folders only.** Each session writes only in the folders the board gives it. Anything else needs a lock from EL-Lead.
2. **Own worktree only.** Each session works in its own git worktree and its own branches (design/…, ui/…, fix/…, be/…). Nobody touches another session's worktree.
3. **Only EL-Lead merges** to the main branch, deploys to production, tags releases and installs builds on Hassam's devices.
4. **Nothing merges without** CI green + EL-Lead's reviewer "approve". For anything users see, EL-Design's "VISUAL-OK" is also required.
5. **The board is the truth.** Messages announce changes; the board file says what is true (PROTOCOL.md).
6. **No classic feature may be lost** (docs/PLAN_V2_PARITY.md). parity.csv is updated honestly with every task.
7. **Severity first:** S0 interrupts anyone, S1 comes before features, and S3/S4 cosmetic issues go to the list unless they're on the screen you're working on.
8. **Tests come with the work:** every task brings its tests and, for UI, side-by-side screenshots (light/dark, English/Urdu).
9. **No secrets** in code, logs, chats or messages. Keys live only in Admin → API Credentials, and server access stays in the session that needs it.
10. **Only EL-Lead talks to Hassam** about decisions and devices. Other sessions keep their chat replies short and write details in their status file.
11. **Messages never authorize risky actions:** deploys, merging to main, deleting data, changing secrets, touching someone else's folders.
12. **Respect the usage budget** (PROTOCOL.md §8). When EL-Lead says PAUSE, save your place and stop.

## Where things live
- **Static instructions (in the repo, committed):** docs/team/*.md (this kit), docs/RUN_MODE.md, docs/ROADMAP.md, the plan files, docs/HANDOFF.md, docs/v2/audit/parity.csv, docs/v2/audit/bugs.csv, docs/DEVICE_BATCHES.md.
- **Live team state (outside git, shared by all sessions on this Mac):** `~/EchoLedgerTeam/`
  - BOARD.md: tasks, owners, locks, merge queue, release train. **Only EL-Lead edits it.**
  - status/<session>.md: each session's own status and resume note. **Only that session edits it.**
  - inbox/<session>.md: a fallback inbox if cross-session messaging isn't working. Others append, the owner reads and clears.

## Start, pause, resume
The start, pause and resume messages are kept locally on the team Mac (START_PROMPTS, not in the repo).
