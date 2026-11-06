# EchoLedger admin guide (v1.5)

For the person who runs the server (the super admin) and for workspace owners and admins. Everyday use of the apps
is in [USER_GUIDE.md](USER_GUIDE.md); putting the server up is in [web/DEPLOY.md](../web/DEPLOY.md).

## 1. Who can do what

Every account has a personal workspace of its own. A team is a workspace with more people in it. **A recording or
project belongs to the person who made it.** Nobody else sees it, owners and admins included, until that person
shares it with a link or with **Send to a teammate**.

| In a workspace | Owner | Admin | Member | Viewer |
|---|---|---|---|---|
| Record, upload, import | yes | yes | yes | no |
| Sign in their own phones and computers | yes | yes | yes | yes |
| Open what is shared with them | yes | yes | yes | yes (no Save to my library) |
| Invite and remove people, change roles | yes | members and viewers | no | no |
| See minutes and costs per person (never content) | yes | yes | their own | no |
| Revoke another person's device | yes | yes | no | no |
| Plan, name, hand over, delete the workspace | yes | no | no | no |

The **super admin** runs the server: plans, server settings, API credentials, and every workspace's people and usage,
never their recordings. A workspace always has one owner, so an owner who wants to leave hands it over first.

## 2. After the accounts update (once)

1. **Email.** Settings → Email: the SMTP server, port, encryption, user, password and sender. **Send a test email**
   tries the values on the page before you save them. Then add the SPF, DKIM and DMARC records in
   [EMAIL_DNS.md](EMAIL_DNS.md) at your domain's DNS host. Invitations, confirmations, password resets and sign-in
   alerts all need this.
2. **Google sign-in (optional).** API Credentials → Google sign-in shows the exact redirect address. In the Google
   Cloud console create an OAuth client of type *Web application* with that address, then paste its client ID and
   secret here.
3. **Who can sign up.** Settings → Privacy & security → Who can sign up: *Nobody* (the default), *Only invited people*,
   or *Anyone*. Keep it on *Nobody* or *Only invited people* until the server is ready for the public (VPS, legal
   pages reviewed).
4. **Members in the console.** Settings → Members can use the console (on by default). When it is on, members and
   viewers get a small read-only console: their own library, Shared with me, their profile and devices. When it is
   off, the console points them to the apps.
5. **Legal pages.** `/terms` and `/privacy` are drafts marked "Draft — for review". Have them read before anyone
   outside your team signs up.

## 3. People and workspaces

Open **Workspace** in the console.

- **Invite:** an email address and a role. The email holds a link that works once, for 7 days. If the plan's seats
  are full, the invitation is refused until you raise the seats or remove someone.
- **Change role** or **Remove** on a person's row. A removed person keeps their own personal workspace, and their
  devices leave this one.
- **Hand over** makes another member the owner; you become an admin. **Leave** works after that.
- **Rename** and **Delete workspace** (owner only). **Switch workspace** when you belong to several.

Each person sees their devices under **Profile → Devices** (phones, computers and console browsers, with Revoke) and
can **Sign out everywhere**. A new device or a console sign-in from a new browser sends that person an email.

## 4. Plans (super admin)

**System → Plans.** Prices stay at 0 until billing comes (backlog).

- **New plan** / **Edit:** name, price, minutes a month, storage (GB), devices, retention (days), seats, AI credits
  (US$ a month), and the features: Live Translate, Screen moments, AI notes (client notes, memory, Ask) and Connector
  (Claude and ChatGPT). An empty number means no limit.
- **Assign** puts a workspace on a plan. **Delete** works only for a plan no workspace uses.
- What a plan leaves out disappears from the apps, and its routes answer "… is not in your plan". Roman Urdu and the
  other transcript views, suggested fixes and screen descriptions are in every plan.
- At a limit people see a message in plain words: minutes, storage, seats or AI credits used up for the month.

## 5. Usage and costs

- **System → Server usage** (super admin): minutes and tokens per provider (Groq, AssemblyAI, ElevenLabs, Soniox,
  Anthropic with its uses, Gemini), per workspace and per person, the dollars from the price table on the same page
  (edit a price when a provider changes theirs), the share of each plan used, the last six months, date and workspace
  filters, and **CSV**.
- **Usage** (owners and admins): their workspace per person. A member sees only their own.
- Activity shows "at 80 %" and "reached" once a month for minutes, storage and AI credits.

## 6. Deleting an account, exporting data

- A person deletes their account in the app, in the console or on the public page `/account/delete`. They have
  7 days to change their mind (sign in, **Keep my account**). After that the server erases everything only they own.
  An owner of a team with other people hands the team over first.
- **Export my data** makes a ZIP of everything the person owns. The download link is valid for 24 hours.
- Both need the hourly commands below.

## 7. The server's scheduled commands

Next to the ones in [web/DEPLOY.md](../web/DEPLOY.md):

- `php artisan echoledger:erase-accounts` hourly: erases accounts whose 7 days are over.
- `php artisan echoledger:prune-exports` hourly: removes export ZIPs after 24 hours.

## 8. Security at a glance

- Sign-in attempts are limited (5 a minute per address, 5 failures per 15 minutes per email); two-step codes and
  recovery codes are under Profile.
- **Security** in the console lists sign-ins, failed bursts, shares, deletions and admin actions (the audit log).
- Nobody's password, token or key is shown again after it is saved.
