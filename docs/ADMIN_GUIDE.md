# EchoLedger admin guide (v1.6)

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

### The app and the admin panel (since v1.6)

- **The app** is at `/app`: everyone's own recordings, projects, devices, workspace and profile. Owners and admins
  run their workspace there.
- **The admin panel** is at `/admin`, for the platform's staff only. It is orange with an **ADMIN** label next to the
  name, so it is never mistaken for your own app. Staff sign in to the app as usual and open it from the avatar menu
  (**Admin panel**); **Back to the app** returns. It has Plans, Upgrade requests, Server usage, API Credentials,
  Settings (email, sign-up, engines and the rest of the server), Device reports and the Accuracy Lab.
- Someone who is not staff gets "Forbidden" on every admin page; the bare `/admin` (the console's home before v1.6)
  takes them to `/app`. The app's old addresses under `/admin` (bookmarks, links in emails sent before the update)
  go to the same page under `/app`.

## 2. After the accounts update (once)

1. **Email.** Admin panel → Settings → Email: the SMTP server, port, encryption, user, password and sender. **Send a test email**
   tries the values on the page before you save them. Then add the SPF, DKIM and DMARC records in
   [EMAIL_DNS.md](EMAIL_DNS.md) at your domain's DNS host. Invitations, confirmations, password resets and sign-in
   alerts all need this.
2. **Google sign-in (optional).** One OAuth client of type *Web application*: the steps are in
   [Google sign-in: the OAuth client](#google-sign-in-the-oauth-client) below.
3. **Who can sign up.** Admin panel → Settings → Privacy & security → Who can sign up:
   - **On** (*Anyone*): family and friends make their own free account on the sign-up page, or with **Create account**
     in the apps. Each account gets its own private workspace: they never see your recordings and you never see theirs
     unless one of you shares.
   - **Off** (*Nobody*, the default): there is no sign-up page. You add people on the Workspace page; they join your
     workspace as teammates.
   - **Invite only**: the Workspace page's **Invite** emails a sign-up link to an address without an account.

   Keep it Off or Invite only until the server is ready for the public (VPS, legal pages reviewed).
4. **Members in the console.** Admin panel → Settings → Members can use the console (on by default). When it is on, members and
   viewers get a small read-only console: their own library, Shared with me, their profile and devices. When it is
   off, the console points them to the apps.
5. **Legal pages.** `/terms` and `/privacy` are drafts marked "Draft — for review". Have them read before anyone
   outside your team signs up.

### Google sign-in: the OAuth client

The phone and computer apps sign in with Google **through the console in the browser**: they open
`https://<your server>/device/sign-in`, the console sends the browser to Google, and Google sends it back to the console.
So the server needs **only one OAuth client, of type Web application**. No Android client (no SHA-1 fingerprint) and no
desktop client are needed; create them only if the apps ever sign in with Google without the browser.

1. Open [console.cloud.google.com](https://console.cloud.google.com) and pick or create a project (for example
   *EchoLedger*).
2. **Google Auth Platform → Branding** (older consoles: *APIs & Services → OAuth consent screen*): app name
   *EchoLedger*, your support email, your domain under *Authorised domains*, and a developer contact email.
3. **Audience:** *External*. Press **Publish app** so it is *In production*; while it says *Testing*, only the test users
   listed there can sign in.
4. **Data access:** add the scopes `openid`, `.../auth/userinfo.email` and `.../auth/userinfo.profile`. They are not
   sensitive, so Google asks for no review of them.
5. **Clients → Create client** (older consoles: *Credentials → Create credentials → OAuth client ID*):
   - Application type: **Web application**; name: *EchoLedger console*.
   - Authorised JavaScript origins: `https://<your server>` (optional).
   - Authorised redirect URIs: the address the console shows under **Admin panel → API Credentials → Google sign-in**, exactly, for
     example `https://echoledger.example.com/auth/google/callback`. It is the server's address followed by
     `/auth/google/callback`; `http://` works only for `localhost`.
6. **Create**, then copy the **Client ID** and **Client secret** into **Admin panel → API Credentials → Google sign-in** in the console
   and save. *Sign in with Google* appears on the console's sign-in page at once, and the apps offer Google too
   (`GET /api/auth/options` says `"google": true`).
7. Check it: sign out, choose **Sign in with Google**, and sign in with an account whose email has an account here.

If Google answers *redirect_uri_mismatch*, the address in step 5 differs from the one the console shows (a missing
`www`, `http` instead of `https`, or a slash at the end).

## 3. People and workspaces

Open **Workspace** in the console.

- **Invite:** an email address and a role. The email holds a link that works once, for 7 days. If the plan's seats
  are full, the invitation is refused until you raise the seats or remove someone.
- **Pending invitations** lists the invitations not used yet. **Resend** emails a new link for 7 more days (the old
  link stops working) and counts against the same 20 an hour as Invite; **Cancel** stops the link and frees its seat.
- **Change role** or **Remove** on a person's row. A removed person keeps their own personal workspace, and their
  devices leave this one. Their sign-in codes, share links and request links here stop working, and what was sent to
  them from this workspace ends (copies they saved stay theirs). Coming back does not bring any of it back.
- **Hand over** makes another member the owner; you become an admin. **Leave** works after that.
- **Rename** and **Delete workspace** (owner only). **Switch workspace** when you belong to several.

Each person sees their devices under **Profile → Devices** (phones, computers and console browsers, with Revoke) and
can **Sign out everywhere**. A new device or a console sign-in from a new browser sends that person an email.

### Sign-up (since v1.6)

**Admin panel → Settings → Privacy & security → Sign-up.** Everyone signs up the same way: their email, a 6-digit code by email,
their name and the Terms (no password needed; they can set one later with **Forgot password?**).

- **Who can sign up:** On (anyone), Off (only people you add), or Invite only (an address with an invitation waiting;
  the code proves it is theirs).
- **Allowed email domains:** empty for any address, or only these domains. Throwaway addresses (the list in
  `web/resources/data/disposable-email-domains.txt`, one domain a line) are always refused.
- **New accounts a day from one network:** default 5.
- **Turnstile** (Cloudflare's free check against robots) protects the web sign-up once its site key and secret are
  saved in **Admin panel → API Credentials → Turnstile** (dash.cloudflare.com → Turnstile → Add widget for the console's domain).
  Without them it is skipped. The apps do not show it; the limits per address and network protect them.
- Codes: 10 minutes, 5 tries, a new one after 60 seconds, at most 5 an hour per address and 20 an hour per network.
- **Tell me when one person records more than … hours a day** (default 6): an email and a notification to you, at
  most once a day per person. Only the lengths are counted.
- **Suspend or restore a person** (Admin panel → Plans): a suspended person cannot sign in anywhere (the console and the
  apps say "This account is suspended"), and their phones and computers are refused on every call. They stay signed
  in, so lifting the suspension brings them straight back.
- **Device limit:** each plan's devices per person; one more is refused with "Your plan signs in 2 devices. Sign one
  out (Account → Devices), or upgrade."

### Staff and their roles (since v1.6)

**Admin panel → Staff** lists who works on EchoLedger. **Add staff** gives an existing account a role (they open the
admin panel from their avatar menu); **Change role** and **Remove from staff** change it at once. The roles that come
ready:

| Role | May |
|---|---|
| Super admin | everything (the server's operator; needs no role) |
| Admin | customers (see, change plans and limits, notes, support actions, delete), plans, upgrade requests, usage, the activity log, device reports; not API keys, Settings or Staff |
| Support | see customers and their usage; the support actions (resend emails, reset two-step sign-in, sign out devices) |
| Billing | plans and upgrade requests; see customers and usage |
| Read-only | see customers, plans, usage and the activity log |

**New role** makes one of your own; the **Permissions** matrix below the list has a box per permission and role and
applies at once. Staff who may manage staff can give only what they have themselves and cannot change their own role.
**Every member of staff needs two-step sign-in** (Profile → Two-step sign-in in the app) before the admin panel opens;
without it /admin takes them to their profile. Workspace roles (owner, admin, member, viewer) are the customers' own:
the app's Workspace page shows what each may do.

### Customers (since v1.6)

**Admin panel → Customers** lists every account: name and email, the plan badge, status (Active, Suspended, Not
confirmed, Being deleted), devices signed in, minutes and US dollars this month (per provider under the total), how
much of the plan's minutes is used, storage, last active and the sign-up date. Search by name or email, filter by plan
or status, and select rows to **Change plan** (until a date or not), **Suspend**, **Restore** or **Download CSV**.

A customer's page has this month's numbers and a chart of the last 30 days, their devices (**Revoke** one), their
workspaces with roles, the plan with **Change plan** (with an end date: then the default plan) and **Features and
limits** (their own, on top of the plan), staff **Notes** (never shown to them), their activity log, and **Support**:
send the confirmation email again, reset two-step sign-in (when they lost their authenticator; make sure it is really
them), sign out every device, suspend or restore, and delete the account (as if they asked: 7 days, then erased).

**Privacy.** The admin panel shows metadata and usage only, never recordings or transcripts. A customer who wants help
with a recording turns on **Support access** in their profile for 1 hour, 1 day or 7 days; until then their page lists
their recordings and **Open** shows a transcript. Every look is in the activity log (theirs too), and it ends by itself
or when they press **Stop now**.

## 4. Plans (super admin)

**Admin panel → Plans.** Prices are only shown until billing comes (backlog): the apps show **Price a month** with its
**Currency** ("Rs 2,000 a month"), or **Price as shown** when you write your own text; **Price in US$** is for the
reports.

- **New plan** / **Edit:** name, badge (PRO) and its colour, price, description, order on the comparison, **On offer**
  (listed in the apps' plan comparison), **Default for sign-ups** (one plan; a plan that ends falls back to it), trial
  minutes, and the limits: minutes a month, storage (GB), devices per person, retention (days), seats, AI credits (US$
  a month), longest recording (minutes), recordings a day. An empty number means no limit. **Engines** says which
  speech engines the plan may use (the person's own Groq key and the computer's own transcription cost you nothing).
- **Features** (since v1.6): every paid feature is a switch: transcription on EchoLedger, Roman Urdu/English/Urdu
  views, double-check, screen moments, the Claude and ChatGPT connector, team workspace, premium engines (ElevenLabs,
  Gemini), Live Translate, AI notes. Recording, organising, search, share links, export and the Ready check are free
  and in every plan. A new plan starts with every paid feature off.
- **Assign** puts a workspace on a plan, **until** a date if you like (then it goes back to the default plan; checked
  every hour). **Delete** works only for a plan no workspace uses, and not for the default one.
- **Duplicate** (since v1.6) makes a copy with every limit, feature and engine, not on offer and not the default, to
  change before you offer it. **Archive** takes a plan off offer and out of Assign and upgrade requests; workspaces on
  it keep it until you move them. **Show → Archived** lists them, with **Bring back**. Free and the default plan cannot
  be archived.
- **Features and plans** (since v1.6), under the list: a box per paid feature and plan. Ticking one changes the plan
  at once for every workspace on it, and goes in the activity log.
- **Overrides for a person:** for one person only, on top of their workspace's plan: a feature on or off, a limit
  higher, lower or `none` (no limit). Setting everything back to "The plan's" clears them. Retention counts for the
  workspaces the person owns.
- What a plan leaves out shows a lock in the apps, and its routes answer "… is not in your plan" with the plan that
  has it. At a limit people see a message in plain words (apps of v1.6 and later get one code for every limit).
- Retention of a plan applies only to recordings made after it was set; older recordings are never deleted by it.
- **Payment instructions** (since v1.6): the text people read after **Request upgrade** (bank or JazzCash details and
  what to write as the reference), followed by "We'll activate it once confirmed".

### Upgrade requests (since v1.6)

**Admin panel → Upgrade requests** lists the waiting requests first: who asked, the plan they are **On now**, the plan
they ask for and their message (the badge counts the waiting ones; the bell tells you too; **Status** shows the
approved and declined ones). **Customer** opens their page. Once the payment is confirmed press **Approve**: choose the
plan (archived plans are not offered), if you like until when (then it goes back to the default plan), and an optional
word to them. **Decline** needs a reply. Either way the person gets an email and a notification in the apps, with your
words. One request waits at a time per person.

## 5. Usage and costs

- **Admin panel → Server usage** (staff who see usage): minutes, tokens and the estimated cost at the top, each
  compared with the period just before (**Compare with the period before**); a bar per day; per plan; the **Top
  customers** by cost (each opens their page); per provider (Groq, AssemblyAI, ElevenLabs, Soniox, Anthropic with its
  uses, Gemini), per workspace and per person; the last six months; **Period** (this month, last month, the last 7 or
  30 days, this year, or your own dates) and a workspace filter; **Download CSV**. The dollars come from **Prices** on
  the same page (edit one when a provider changes theirs).
- **Budget alert** (since v1.6): a monthly budget in US$. Staff who see usage get a notice in the bell and an email when
  this month's estimated cost passes 80% of it, and again at 100%, once each a month. It only warns; the monthly limit
  in Settings is what stops transcription.
- **Usage** (owners and admins): their workspace per person. A member sees only their own.
- Activity shows "at 80 %" and "reached" once a month for minutes, storage and AI credits.

**Limits on a server with customers.** Use each plan's minutes, not only the server-wide monthly budget in Settings. The
server-wide budget stops transcription for everyone once it is reached, and streaming minutes are reported by the devices.
A report can no longer claim more than the time since the server granted that device a stream, but a device with a real
grant can still report up to that time on two channels, so one account can use up a small server-wide budget.

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
- `php artisan recordings:check-storage`, by hand, only when recordings are moved to a bucket
  ([DEPLOY_VPS.md](DEPLOY_VPS.md)): it tries a write, a read and a delete on the bucket and warns if it keeps old versions.

## 8. Claude and ChatGPT

People can connect Claude or ChatGPT to their own recordings; the steps are in [CONNECTOR.md](CONNECTOR.md). It only
reads, and sees only what that person can open in the apps.

- **Turn it on or off** per plan: Admin panel → Plans → the Connector feature.
- **Once on the server:** `php artisan passport:keys`, then `chmod 600 storage/oauth-private.key storage/oauth-public.key`,
  run as the user PHP runs as. Until then `/mcp` and its sign-in page answer with a server error. [DEPLOY.md](../web/DEPLOY.md)
  has checks you can run with curl.
- **What a person controls:** Profile → Connected apps lists each app with its last use and Disconnect. **Sign out
  everywhere** disconnects them too.
- **What you can see:** every tool call is in **Security** (the tool, the arguments without content, the size of the
  answer). "App disconnected: used token came back" means a sign-in token was used twice, so the connection was ended; the
  person connects again. If it keeps happening with one app, check that app first.

## 9. Device reports

**Admin panel → Device reports** (super admin) lists what people chose to send from **Send to my admin** on the phone's Device
check or the Mac's Run Mac check: the maker, model, system version, app version and each check's result. Nothing is sent
unless the person taps it. Use it to see what works on your team's phones. The list of checks a teammate should run is in
[MANUAL_QA.md](MANUAL_QA.md), "Teammate phone check".

## 10. Security at a glance

- Sign-in attempts are limited (5 a minute per address, 5 failures per 15 minutes per email, on the console's sign-in
  page as in the apps); two-step codes and recovery codes are under Profile.
- The server answers only the address in `APP_URL` and builds every link from it: a wrong `APP_URL` makes every request
  fail with 400 (see `web/DEPLOY.md`).
- **Forgot password?** signs the account out everywhere: browsers, devices and connected apps. Changing the password
  on the profile ends other browsers and leaves devices signed in. A new email address counts once the link sent to it is
  opened; a Google account links only to a confirmed address.
- An account in its 7-day deletion grace serves no share or request link until it cancels.
- Only the super admin changes the server's engine order (**Make default** in Engine results, Settings).
- Usage reports count only for streaming the server granted that device, so nobody can use up the monthly limit by
  reporting made-up minutes. Only an owner or admin can continue over Live Translate's budget, up to twice the budget.
- **Security** in the console lists sign-ins, failed bursts, shares, deletions and admin actions (the audit log).
- Nobody's password, token or key is shown again after it is saved.
