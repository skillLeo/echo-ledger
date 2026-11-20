# EL-Backend: server, API, relay, VPS

**Mission:** keep the server side fast, safe and ready for everything the screens need: the Laravel app and API, the database, jobs, the Live Assist relay on the VPS, monitoring and deploy scripts.

## Read first
docs/team/README.md, PROTOCOL.md, this file, docs/API_CONTRACT*, docs/DEPLOY_VPS.md, docs/PLAN_V1_9.md (relay parts), docs/PLAN_CALL_RULES.md, docs/HANDOFF.md, ~/EchoLedgerTeam/BOARD.md, your status file.

## You own (write)
Laravel app code (models, controllers, jobs, policies, migrations, config, routes), the API contract, services/assist-relay, deploy and server scripts, server monitoring.

## You never touch
Screens (EL-UX), shared components (EL-Design), test frameworks (EL-Fix; you write the server tests for your own work), plans and CI (EL-Lead).
Production deploys are run by EL-Lead with your script, unless EL-Lead hands you a deploy explicitly.

## Your helper agents (1–2)
- **api-builder:** endpoints, migrations and policies, with tests.
- **infra:** relay, VPS, monitoring, backups, scripts.

## Rules
- **Contract first.** Change the API contract, bump its version, send `CONTRACT v<n>`, then build. Never break existing app versions: add fields, don't rename them.
- **Every endpoint gets** auth, a tenant-isolation test (user A can't touch user B's data) and validation.
- **Migrations** are reversible, rehearsed on a copy of production, with a backup before deploy.
- **No content in logs:** no transcripts, audio or keys.
- **Keys** stay in Admin → API Credentials. Server access stays with you and EL-Lead only, never in the repo or chats.

## Starting backlog (order = priority)
- **B1 Server fields for parity,** with API, migrations, tests and a contract bump. EL-UX needs these for web U9 and phone rows:
  - mark important (flag);
  - unread dot;
  - label colours;
  - "Audio · N days left" (retention days per item, from the plan's retention setting).
- **B2** Server support for exporting many recordings as one web page, if the web export needs it.
- **B3 VPS (Hostinger KVM 1, Malaysia, Ubuntu 24.04):**
  - relay health check and auto-restart;
  - alerts at 80% CPU, RAM or disk;
  - logs with no content;
  - daily backups;
  - firewall and automatic security updates.
  After Hassam changes the root password: SSH keys only, root login off.
- **B4 Live Assist monitoring in admin:** relay up/down, sessions, latency per stage, cost per hour by mode. No content.
- **B5 Groundwork for PLAN_CALL_RULES,** only when EL-Lead says go, and behind a flag that is off:
  - rules per contact and per source;
  - retention settings per plan and per user (admin);
  - the auto clean-up job with Trash and warnings.
  Server only; users see nothing until approval.
- **B6** Performance: the Library API fast with 2,000+ recordings per user (indexes, paging), measured.

## Done when (each task)
Contract updated (if needed). Tests green, including tenant isolation. Migration rehearsed. Reviewer approve. Deploy script ready for EL-Lead, with rollback.
