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
2. **Google sign-in (optional).** One OAuth client of type *Web application*: the steps are in
   [Google sign-in: the OAuth client](#google-sign-in-the-oauth-client) below.
3. **Who can sign up.** Settings → Privacy & security → Who can sign up:
   - **On** (*Anyone*): family and friends make their own free account on the sign-up page, or with **Create account**
     in the apps. Each account gets its own private workspace: they never see your recordings and you never see theirs
     unless one of you shares.
   - **Off** (*Nobody*, the default): there is no sign-up page. You add people on the Workspace page; they join your
     workspace as teammates.
   - **Invite only**: the Workspace page's **Invite** emails a sign-up link to an address without an account.

   Keep it Off or Invite only until the server is ready for the public (VPS, legal pages reviewed).
4. **Members in the console.** Settings → Members can use the console (on by default). When it is on, members and
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
   - Authorised redirect URIs: the address the console shows under **API Credentials → Google sign-in**, exactly, for
     example `https://echoledger.example.com/auth/google/callback`. It is the server's address followed by
     `/auth/google/callback`; `http://` works only for `localhost`.
6. **Create**, then copy the **Client ID** and **Client secret** into **API Credentials → Google sign-in** in the console
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

**Settings → Privacy & security → Sign-up.** Everyone signs up the same way: their email, a 6-digit code by email,
their name and the Terms (no password needed; they can set one later with **Forgot password?**).

- **Who can sign up:** On (anyone), Off (only people you add), or Invite only (an address with an invitation waiting;
  the code proves it is theirs).
- **Allowed email domains:** empty for any address, or only these domains. Throwaway addresses (the list in
  `web/resources/data/disposable-email-domains.txt`, one domain a line) are always refused.
- **New accounts a day from one network:** default 5.
- **Turnstile** (Cloudflare's free check against robots) protects the web sign-up once its site key and secret are
  saved in **API Credentials → Turnstile** (dash.cloudflare.com → Turnstile → Add widget for the console's domain).
  Without them it is skipped. The apps do not show it; the limits per address and network protect them.
- Codes: 10 minutes, 5 tries, a new one after 60 seconds, at most 5 an hour per address and 20 an hour per network.

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

- **Turn it on or off** per plan: System → Plans → the Connector feature.
- **Once on the server:** `php artisan passport:keys`, then `chmod 600 storage/oauth-private.key storage/oauth-public.key`,
  run as the user PHP runs as. Until then `/mcp` and its sign-in page answer with a server error. [DEPLOY.md](../web/DEPLOY.md)
  has checks you can run with curl.
- **What a person controls:** Profile → Connected apps lists each app with its last use and Disconnect. **Sign out
  everywhere** disconnects them too.
- **What you can see:** every tool call is in **Security** (the tool, the arguments without content, the size of the
  answer). "App disconnected: used token came back" means a sign-in token was used twice, so the connection was ended; the
  person connects again. If it keeps happening with one app, check that app first.

## 9. Device reports

**System → Device reports** (super admin) lists what people chose to send from **Send to my admin** on the phone's Device
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
