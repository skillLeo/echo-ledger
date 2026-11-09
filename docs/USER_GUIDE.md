# EchoLedger user guide

EchoLedger records your calls, meetings and voice notes, shows live captions while you talk, and gives you a
clear written transcript afterwards. Your recordings stay on your own server.

You use it in three places:

- **The Windows or Mac app** on your computer records calls and shows captions.
- **The phone app** on Android records WhatsApp calls and voice notes.
- **The web console** in your browser is where you read, search, share and manage everything.

All three show the same library.

---

## 1. Install the Windows app

1. Run the installer file you were given (it ends in `-setup.exe`).
2. If Windows says **"Windows protected your PC"**, click **More info**, then **Run anyway**. Do this only for a
   file you got from your own team.
3. EchoLedger starts in the system tray (the small icons next to the clock). Click its icon to open it.
4. The first time, a short setup guide walks you through the microphone, pairing and a 10-second test.

**Updates (computer, since v1.5; checked more often since v1.6).** The app looks for a new version on your server soon
after it starts and every 6 hours, downloads it and checks its signature, then shows **Update ready — Restart**.
**Restart** installs it now; otherwise it installs the next time EchoLedger starts (never during a recording). A computer
that still runs **1.1.0** has no updater: install the new version once by hand from your server's **Downloads** page
(run it over the old one; your settings and sign-in stay). After that it updates itself.

**When the server asks for an update, has news or is in maintenance (computer, since v1.6).** A banner at the top of the
window says so:
- **Please update EchoLedger…** with **Update**: the app looks for the new version itself first (then **Update ready —
  Restart**); if it has none, your server's Downloads page opens. When the server no longer accepts this version, the
  banner says so: your recordings stay on this computer and upload after the update.
- **An announcement** from the server's owner: close it with **×** and it stays closed (a new one shows again).
- **Maintenance:** a quiet line, with the time it should end when the server says. Recordings and uploads wait on this
  computer and go by themselves when it is back; nothing is marked as failed.

## 1b. Install the Mac app

The Mac app is built for Apple Silicon Macs (M1 and newer) and Intel Macs, and needs **macOS 14.4 or newer**, which also
records both sides of a call (Apple menu → **About This Mac** shows your version).

1. Open the `.dmg` file and drag **EchoLedger** into **Applications**.
2. The app is not signed by Apple yet, so macOS stops it the first time. Open it once and close the message. Then open
   **System Settings → Privacy & Security**, scroll down to Security and click **Open Anyway** next to EchoLedger, and
   confirm with your password or Touch ID. After that it opens normally. (On macOS 15 and later, right-click → Open no
   longer gets past this message.)
3. EchoLedger lives in the menu bar (top right). Click its icon to open it, or right-click it for the menu. ⌘W closes the
   window and leaves EchoLedger in the menu bar, ⌘, opens Settings, and ⌘Q quits. The icon follows light and dark menu
   bars and turns red while recording.
4. A short setup goes through what macOS must allow, one step each, with a button to the right page of System Settings:
   - **Microphone:** your side of a call, voice notes and dictation.
   - **System audio:** the other side of a call (the sound this Mac plays).
   - **Screen recording:** only for screen moments, what a meeting showed on screen. Recordings work without it. From
     macOS 15, macOS asks again about once a month whether EchoLedger may still see your screen. That is normal: choose
     **Allow**. If you miss it, screen moments pause and the recording goes on.

**If a permission is off,** nothing crashes and nothing records silently: the Record page, the recording pill and the
captions say what is missing and what the recording holds without it (only your voice, or only the other side). A
recording that would hold nothing does not start and says why, even with the window closed: a shortcut or the menu-bar
icon opens the window with the reason, and a meeting that cannot be recorded gets a notification.

**Updates and permissions.** Because the app is not signed with an Apple Developer ID yet, macOS may forget its
permissions after an update. EchoLedger looks at them every time it starts; if one that was allowed has gone, it says so
once, in one sentence, with the same steps to set it up again.

**Starting at login** is off until you turn it on in **Account → This computer**.

**The Mac check.** **Account → This computer → Help & diagnostics → Run Mac check** looks at macOS, the permissions, the
microphone, the other side of a call (with one soft tone), your meeting apps, the captions and pill, and screen moments,
one at a time, and says how to fix what it finds. It does not run during a recording. **Copy report** gives you plain text
with no names, and **Send to my admin** sends it to your workspace's admins, only when you press it.

A $99 a year Apple Developer ID fixes both of the above (the Open Anyway step and the forgotten permissions). The
workflow that signs and notarizes the app already exists and starts working as soon as the certificate is in place.

## 2. Install the phone app

1. On the phone, open your server's **Downloads** page (`https://<your server>/downloads`) and tap **Download**
   under Android phone. (Or copy the `.apk` file to the phone and open it.)
2. Android asks whether this source may install apps. Allow it once. If Play Protect warns about an unknown
   developer, choose **More details → Install anyway**.
3. Open EchoLedger. A short setup guide asks for the microphone, notifications and signing in.

**Updates (since v1.5).** The app looks for a new version every 12 hours. When there is one, a banner on the Record
tab says so: tap **Update**, allow EchoLedger to install apps the first time Android asks, and confirm Android's
install screen. Your recordings and settings stay. You can also look in **You → General → About → Check for
updates**.

**Google Play version.** EchoLedger may also come from Google Play. That version updates through Play and does not
record calls, because Google Play does not allow the capture helper that hears the other side. To record calls, use
the version from your server's Downloads page.

**WhatsApp calls** need a one-time setup, and Android 14 or newer to hear both sides (see "What works where" below). In **Settings → Devices → This phone**, open the **Setup guide** and follow the steps (Shizuku or a computer with ADB). You do this once. After a phone restart, the guide
shows how to turn it on again.

**Voice notes** need no setup. In WhatsApp, press and hold a voice note, tap **Share**, then choose EchoLedger.

## What works where

| | Voice notes and import | Calls and meetings |
|---|---|---|
| **Mac**, Apple Silicon or Intel, macOS 14.4 or newer (the app does not open on older versions) | Yes | Both sides. |
| **Windows 11** | Yes | Both sides. EchoLedger hears only the call's app. |
| **Windows 10** | Yes | Both sides, but it records all sound playing on the computer, not just the call. |
| **Android 14 or newer**, with the capture helper | Yes | App calls: both sides. SIM calls: where the phone maker allows it. |
| **Android 12 and 13**, with the capture helper | Yes | App calls: **only your voice**. SIM calls: where the phone maker allows it. |
| **Android 12 or newer, no helper** | Yes | Not recorded: during a call Android gives other apps no sound. |
| **Android 10 and 11** | Yes | Not recorded. |
| **Google Play version** | Yes | Not recorded: use the version from your server's Downloads page. |

To see what your phone can do, open **You → General → Help & diagnostics → Device check**. It gives the reason for each
line and a fix where there is one, plus the steps your phone's maker needs so EchoLedger keeps running in the background.

**Tested so far:** Samsung Galaxy S25 Ultra on Android 17. Other phones should work the same way, and this list grows as
they are tested. Send your admin the report from Device check if something differs.

## 3. Sign in on your devices

**One way in, everywhere (since v1.6).** Type your email and press **Continue**.

- **New here?** We email you a 6-digit code (it works for 10 minutes). Type it, then your name, accept the Terms and
  the Privacy Policy, and your free account is ready, with its own private workspace. The console then shows **Get
  the apps**: the phone and computer apps, signed in with the same email.
- **You have an account?** Type your password, or press **Email me a code** to sign in without it. When your account
  has two-step sign-in, the code from your authenticator app comes next.
- **Continue with Google** works when the server offers it. A new code can be asked for after a minute; after 5 wrong
  tries ask for a new one.

**Sign in or make an account (phone, since v1.6).** The first screen, **Sign in or create an account**, asks only for
your email: type it and tap **Continue**. A new email gets the 6-digit code, then your name and the Terms, and the
account starts on **Free**; an account you have asks for the password (or **Forgot it? Email me a code instead**), then
the authenticator code if two-step sign-in is on. The phone knows its server: **Advanced → Use a different server** is
only for another EchoLedger server. **Other ways to sign in** holds **Scan QR code**, the **One-time sign-in code** from
the console, and **Sign in in the browser** (Google or a passkey: the browser opens, and the phone is signed in when you
come back). On an older server the phone goes straight to the password, as before. Your account's page on the phone
(**You → Account**) exports your data, signs out every device, or deletes the account (you have 7 days to change your
mind by signing in again).

**Family, friends and teammates (since v1.5).** Everyone has their own account, and nobody sees anyone else's
recordings unless they are shared with them.

- **Family and friends** make their own free account when the server allows it (**Create account** in the apps, or
  the sign-up page of the console). Their account has its own private workspace: they do not see your recordings and
  you do not see theirs.
- **Teammates** are invited: the workspace's owner or an admin invites them by email on the console's **Workspace**
  page (or adds them there). Being in the same workspace still keeps each person's recordings their own.
- **Sharing** is how anyone sees a recording of yours: **Send to a teammate**, or a share link you make and can take
  back.

**Sign in or make an account (computer, since v1.6).** The first screen asks only for your email. Type it and press
**Continue**:

- **A new email:** EchoLedger emails you a 6-digit code (it works for 10 minutes; **Send a new code** is offered after a
  minute). Type it, then your name, tick **I accept the Terms and Privacy** (both open in your browser) and press
  **Create account**. Your account starts on the **Free** plan, and the **Record** page opens with the **Ready check**.
- **An account you already have:** type your password, or press **Email me a code instead**. If your account has
  two-step sign-in, the code from your authenticator app is asked for next.
- **Continue with Google** opens your browser when the server offers Google; the app continues by itself.
- **Sign in with QR / code** (the small link) uses a one-time code from the web console, as below.
- The app knows its server. **Advanced → Use a different server** is only for another EchoLedger server, and holds the
  optional name for this computer. Your sign-in is kept encrypted on this computer.

**Sign in (computer, v1.5).** On an older server the first screen asks for the server address, then offers what your server allows:
email and password (**Forgot password?** opens the server's reset page), **Continue with Google** when the server has
Google sign-in (the browser opens and the app continues by itself), and **Create account** when the server lets anyone
sign up (otherwise ask the owner for an invitation). The code from your authenticator app is asked for when your account
has two-step sign-in. After signing in, the **Record** page opens with the **Ready check**, so you see at once whether
calls will be recorded.

**Signing in with a code** also works, for older apps too. It connects a device to your server with a one-time code; you
need the web console.

1. In the console, open **Account → Devices** and click **Sign in a device**. A code and a QR code appear. The code works for
   10 minutes and only once.
2. **Phone:** tap **Other ways to sign in → Scan QR code** and point the camera at the screen, or type the code under
   **One-time sign-in code**.
3. **Windows:** type the server address and the code.
4. The library appears. Repeat for each device.

**Your devices (since v1.6).** **Profile → Devices** (and **Devices** in the menu) lists each phone and computer
once, by a name you can read ("Galaxy S25 Ultra", "Windows 11 PC", "MacBook Pro"), with its status: **Active** (and
when it was last seen), **Signed out by you**, **Revoked by admin**, or **Expired** (not used for 90 days). Signing in
again on the same phone or computer uses the same row. **Rename** gives it your own name; **Sign out** stops it at
once (its recordings stay) and keeps it in the list until you **Remove from list**; the **Show** filter shows the
signed-out ones. While the **Sign in a device** code is open, it says **Waiting for the app…**, then "✓ Galaxy S25
Ultra signed in" and closes by itself. Your plan says how many devices can be signed in at once.
**Sign in again** on a device that is signed out, revoked or expired shows a code and a QR code for that device only
(10 minutes, once): scan it in the app on that phone or computer and it comes back on the same row, with its name and
recordings. Where your plan leaves something out (a project's AI notes and Ask, Claude and ChatGPT under **Connected
apps**), the plan's badge shows with **See plans**.

**Your devices (phone, since v1.6).** **You → Devices** lists the same devices with their readable names. **Rename**
works on any of them, this phone too; **Sign out** signs another one out at once.

**Your devices (computer, since v1.6).** **Account → Devices** lists every phone and computer signed in to
your account, with when each was last active. **Rename** gives any of them a name of your own; **Revoke** signs another
one out. A computer is named like "Windows 11 PC" or "MacBook Pro" when you sign in, and signing in again on the same
computer keeps its one place in the list instead of adding another.

**Changing your email:** on your profile, type the new address. It takes effect when you open the link sent to it, signed
in; the old address is told, and your devices stay signed in. **Forgot password?** on the sign-in page signs your account
out everywhere when you set the new password: every browser, device and connected app. Sign your devices in again.

## 3b. Plans and your own transcription key (since v1.6)

Your plan decides what you can use; locked things show a small badge (**PRO ✦** or **BUSINESS ✦**) with the plan
that has them. **Free** records, organises, searches, shares and exports, and checks your phone or computer is
ready. It has 2 devices, 1 GB and keeps recordings 30 days.

**Transcripts on Free** are made with your own free Groq key (or by the computer app itself):

1. Open **console.groq.com** and sign in (with Google is fine).
2. Open **API Keys**, press **Create API Key**, and copy the key.
3. In EchoLedger open **Account → Transcription key** (on the phone **You → Transcription key**), paste it and press
   **Save**.

We check the key with Groq once, keep it locked, never show it again (only its last four characters), and use it for
your own recordings only. Without a key a new recording says "Add your free Groq key in Account → Transcription
key". **Pro** and **Business** transcribe for you, with no key of your own.

**Upgrading.** Tap a badge (or **Account → Plan & usage → Compare plans**; on the phone **You → Plan & usage → See
plans**): you see what the feature does and the plans
side by side. **Request upgrade** (a message is optional) shows how to pay; we switch the plan on once the payment is
confirmed, and you get an email and a notification.

### Support access (since v1.6)

EchoLedger's support never sees your recordings. If you want help with one, open **Account → Privacy & data → Support
access → Let support look** and choose for how long (1 hour, 1 day or 7 days). Until then support can open your recordings and their
transcripts, and every look shows in your activity log. **Stop now** ends it at once.

## 3c. Find your way in the computer app (since v1.6)

- **The sidebar** lists **Home** (record, what needs you, your latest recordings), **Library**, **Projects**,
  **Activity**, **Shared** and **Upload**; **Help & shortcuts**, **Account** and **Collapse sidebar** are at the bottom.
  Type in **Find a page** at the top to jump to any page, folder, project or Account tab. The arrows next to Library,
  Projects and Shared show what is inside (the smart folders, your pinned projects, With me · My links · Request links).
  Drag the sidebar's right edge to make it wider or narrower (double-click it for the usual width); press **[** to fold
  it to icons and back. **Account → Preferences → Sidebar style** makes it **Light**, **Dark** or **Soft grey**.
- **The top bar:** your workspace (and the others you belong to); the search box (**Ctrl+K**, **⌘K** on a Mac);
  **Calls: Ready** (or **Not ready — Fix**, or the recording that is running, with its time); **+** to record, upload,
  start a project or make a request link; the bell, whose number counts only what needs you (problems, calls waiting for
  Keep or Discard, an answer to an upgrade request; **9+** above nine); help; and the menu under your initials:
  **Account**, **Plan & usage**, the **Admin panel** (for the server's operator, in the browser), the theme and
  **Sign out**.
- **The Library** has a column on the left when the window is wide enough: **All**, **Waiting**, **Pinned**, **Failed
  uploads**, the smart folders with their counts, then your projects. Drag recordings onto the Inbox or a project to move
  them. Above the list: search, **Filters**, project and label, **View** (Group by, Sort by, Density), **List** or
  **Table** (with **Columns** to choose what the table shows) and **Select**. In the table a recording opens in a panel on
  the right; **Esc** closes it.
- **Keys** (never while you type): **J** and **K** move through the list, **Enter** opens, **X** selects, **Esc**
  closes, **/** searches, **N** starts or stops a voice note, **Space** plays or pauses, **←** and **→** jump 5 seconds
  (**Shift** with them 10), **?** lists them all.
- **Account** has tabs: **Profile**, **Password & sign-in methods**, **Devices**, **Notifications**, **Preferences**
  (theme, sidebar style, density, 12- or 24-hour times, the transcription language, shortcuts), **Plan & usage**,
  **Transcription key** (Free plan), **Privacy & data** (export your data, delete your account), then this computer's:
  **Calls & meetings**, **Voice notes & dictation** and **This computer**. **Saved · just now** at the top right says a
  change was kept.
- **Messages and banners (since v1.6.1).** A message at the bottom of the window ("Moved to Acme", "Link copied") goes
  where it is about when you click it, closes with **×** or a swipe left or right (a finger, the trackpad or a mouse
  drag) and hides after 6 seconds (10 for a problem); the thin bar along its bottom shows the time left and stops while
  you point at it. **Esc** closes it. A banner about something still true (calls not recorded, an update, maintenance,
  a limit, no connection) stays until you close it, and comes back only if it changes or when the app starts again.

**Updates and server notices (phone, since v1.6).** When the server asks for a newer EchoLedger, the phone's Library
says so with **Update**. If the server no longer takes recordings from your version, they wait on the phone and are sent
after you update; nothing is lost (the computer does the same, see "When the server asks for an update" above). During maintenance the Library says so and recordings wait the same way. A notice
from the server's owner shows at the top of the Library until you close it.

## 3d. Find your way in the web console (since v1.6)

The console in the browser has the computer app's frame.

- **The sidebar** lists **Home**, **Library** (the arrow shows its smart folders with their counts), **Projects** (your
  pinned ones), **Activity** (its number counts only what needs you), **Shared** (**With me**, **My links**, **Request
  links**) and **Upload**; **Help & shortcuts**, **Account** and **Collapse sidebar** are at the bottom. Type in **Find
  a page** to jump to any page, smart folder, project or Account tab. Drag its right edge to make it wider or narrower
  (200 to 320 pixels; double-click it for the usual width), press **[** to fold it to icons and back. **Account →
  Preferences → Sidebar style** makes it **Light**, **Dark** or **Soft grey**. The console remembers all of this for
  you, in every browser you sign in on.
- **The top bar:** your workspace and your role there (the other workspaces you belong to are one click away); the
  search box, "Search recordings, transcripts, pages… or type a command" (**Ctrl+K**, **⌘K** on a Mac): type a word to
  find it in your transcripts, a page's name, or a command: **Record now** (the console does not record calls: it
  opens the apps' page, or on a phone a voice note), **Upload**, **New project**, **Invite**, **New request link**; **+**
  for **Upload files**, **New project** and **Request link**; the bell; help; and the menu under your initials:
  **Account**, **Plan & usage**, **Workspace** (owners and admins), the **Admin panel** (staff only), the theme and
  **Sign out**.
- **Pages** show where they are above the title (for example **Shared › With me**) and one line about what they hold.
  In the Library a recording opens in a panel on the right (**Esc** closes it) with **Open**, **Send to WhatsApp** and
  **Copy text** (the whole transcript, in the view it opens in) and the start of the transcript. A project's tabs show
  how many recordings, files and rules it has.
- **Keys** (never while you type): **J** and **K** move through the Library, **Enter** opens, **X** selects, **Esc**
  closes the panel or clears the selection, **/** searches the list, **[** folds the sidebar, **?** lists them all.
- **Account** has tabs: **Profile**, **Password & sign-in methods** (change or set your password, two-step sign-in,
  Google, passkeys and browsers under Security), **Devices** (with Connected apps), **Notifications**, **Preferences**
  (sidebar style, density, 12- or 24-hour times, List or Table, the language your uploads start in, the keys; each
  choice says **Saved · just now**), **Plan & usage**, **Transcription key** (Free plan) and **Privacy & data**
  (export your data, delete your account, Support access).
- **On a phone** the console has five tabs at the bottom: **Home**, **Library**, **Record**, **Activity** and **More**
  (the whole sidebar). **Record → Record a voice note** opens the phone's own recorder; the note is uploaded when you
  stop.

## 4. Record automatically

EchoLedger can start recording when a call starts.

- **Windows:** open **Account → Calls & meetings → Recording**. Turn on **Record calls automatically**. Under **Auto-record
  apps**, switch each app on or off (WhatsApp, WhatsApp Business, WhatsApp Web, Zoom, Microsoft Teams, Google Meet).
- In a browser, only known call sites are recorded. See **Call sites** to add a site or to block one. ChatGPT,
  Claude and Gemini are never recorded.
- **Ask me when an unknown app uses the microphone** shows a small question instead of recording. It closes
  after 8 seconds.
- **Phone:** open the **Record** tab and turn on **Auto-record WhatsApp calls**.
- **On Android 16 and 17** (for example a Galaxy S25 with the latest update), the phone records **only the other
  person** in WhatsApp calls. During a call, these Android versions give the microphone only to the calling app and
  to the phone's own system apps. EchoLedger never takes it away from WhatsApp, so the other person always hears
  you. To record both sides of a WhatsApp call, take the call on the computer: the Windows app records both sides.
- **Phone calls (SIM):** open **Settings → Auto-record apps → Record phone calls** and choose **Off** (the default),
  **Ask each time** (a notification asks "Record this phone call?" when a call starts; tap **Record**) or **Always**.
  It works while auto-record is on and the call capture helper is running. Allow **Call log** and **Contacts** so each
  recording gets the number and the contact's name ("Call with Omar Khan"), and is filed under that client.
  **Tell the other person before you record a phone call. In many places the law needs their consent.** Some phones
  give both voices on one track; the transcript then cannot tell who spoke. Some phone makers (Samsung among them)
  keep the phone line for their own Phone app. EchoLedger then says "The phone line gave no sound" and the SIM call
  cannot be recorded on that phone.

The **Record** screen always explains the last decision, for example "Ignored: dictation".

**Ready to record calls (computer, since v1.5).** The **Ready check** at the top of the **Record** page says whether the
next call will be recorded, both sides, by itself: signed in, a microphone connected, the microphone allowed, the other
side's sound allowed (on a Mac: macOS 14.2 or later and Screen & System Audio Recording), and meeting detection running
with **Record calls automatically** on. It ends in **✓ Ready to record calls**, or lists each problem with a button that
fixes it (**Turn on**, **Open System Settings**, **Open sound settings**…). It runs by itself when the app starts, every few
seconds and after every change in Settings; **Check again** runs it at once (on the computer it is on **Home**). The top
bar and the tray menu say it in two
words: **Calls: Ready** or **Not ready — Fix** (click it to open the check). The moment it breaks by itself (a permission
taken away, the microphone unplugged, detection stopped), a notification says so, even in quiet hours.

**Record now.** One button records the right thing: a meeting or call on screen is recorded with both sides, anything
else as a voice note. It is **REC** on the Record page, **Record now** in the tray menu, and **Ctrl+Alt+R** on Windows or
**⌘⌥R** on a Mac; press it again to stop. On a Mac, Safari uses the same keys to reload a page from the server, so while
EchoLedger runs they record instead; change or clear the shortcut in **Account → Preferences → Global shortcuts**. **Ctrl+Alt+Shift+R**
(start or stop a call recording) stays as before. On the phone, tap **REC**, or use **Record now** from the
notification, the **Record** Quick Settings tile, the app icon's long-press menu, or the home-screen widget. During a
call it records both sides (with the capture helper set up), otherwise a voice note.

**The phone's widget (since v1.6).** Add **EchoLedger** from the home screen's widget list. A dot next to the name says
whether calls are recorded: green is ready, red is **Not ready: tap to fix** (a tap opens the Ready check), grey is off.
Make it as small as 2 x 1 for the Record button alone, 4 x 2 for your last two recordings with their progress, or 4 x 4
for the last six; tap one to open it. It follows the phone's light or dark look and, when the app uses them, the
wallpaper's colours. **Settings → App lock → Hide recording titles on the widget** names recordings by their app only
("WhatsApp call"); with App lock on the widget shows no recordings at all.

**Backup start.** If a meeting is on and its recording has not started 5 seconds later, EchoLedger starts it and says
**Recording started (backup)**. A recording you stopped yourself is never started again.

**Only your voice was recorded.** After each call EchoLedger checks that both sides were heard. If the other side was
silent the whole call, it says **Only your voice was recorded**, why (for example the call played through a device that is
not the default output, or the permission for this computer's sound is off) and how to fix it.

## 5. Live captions

Captions show what you and the other person say, while you talk.

- **Windows:** press **Ctrl+Shift+Space** to show or hide them. They never take the keyboard focus, and clicks
  go through them.
- **Phone:** captions appear as a small floating bubble.
- On the computer, **Account → Calls & meetings → Captions** changes the size, the number of lines, the position, the colours and how fast they
  fade.
- **Hide from screen sharing** is on by default. The other people on your call cannot see the captions.
- Press **Ctrl+Alt+Shift+C** to copy the last 2 minutes of captions.

## 6. After a call

Since v1.5 EchoLedger records a call from the moment it is **answered**, not while it rings, and it does not interrupt
you afterwards. Choose how in **Settings → Calls & meetings → After a call** (phone and computer):

- **Keep automatically** (the default): the call is kept and a small **"Saved · Call with Omar · 4:12 · Undo"**
  shows for 6 seconds. **Undo** deletes it; nothing has been uploaded yet.
- **Ask me**: a small chip with the title, **✓** (keep) and **✕** (discard). Tap the title to rename it or pick a
  client. If you do nothing, the call is kept after 10 seconds.

Calls are **skipped** quietly, with no question: a call that was never answered (on the phone), a call shorter than
**Skip calls shorter than** (10 seconds by default), and a call where nobody spoke. Activity shows one line,
**"Skipped 3 short calls"**; **Show** lists them and **Restore** keeps one. Skipped calls are deleted after 24 hours.

Calls left waiting from before v1.5, or "Ask me" calls not decided yet, show as one slim line, **"4 calls waiting ·
Review"**, in the Library, in **Calls & meetings** and in Activity. **Review** opens the list: each call has **Keep**,
**Discard**, a name (the pencil), a client and a language, and **Keep all** / **Discard all** act on every call at once.
**Undecided calls** are kept automatically after **24 hours** (the default), after **3 days**, or **never**.

The pill (the small bar at the top of the screen) and the notification on the phone have **Pause** and
**Stop** buttons. Paused time is not recorded. While an outgoing call rings, the pill says **Waiting for an answer**.

A Bluetooth headset's microphone gives lower accuracy; EchoLedger says **"Accuracy is lower with Bluetooth mics"** when
one is in use. Use the phone's or the laptop's own microphone for important calls.

## 7. Bookmarks

A bookmark marks an important moment so you can find it later.

- **Windows:** press **Ctrl+Shift+B** or click **Mark** on the pill.
- **Phone:** tap **Mark** in the notification or on the Record screen.
- Pick a tag such as **Requirement**, **Budget**, **Deadline**, **Decision** or **Follow-up**. You can undo a
  bookmark for a few seconds.
- In a recording, tap a bookmark to jump to that moment. You can also filter the library by tag.

## 8. Dictation

Dictation types what you say into any app. It is never saved as a recording.

- **Windows:** put the cursor where the text should go and press **Ctrl+Shift+D**. Speak. Press it again to
  insert the text. In **Account → Voice notes & dictation** you can choose **Hold to talk** instead.
- **Phone:** in **Settings → Dictation**, tap **Turn on the voice keyboard**. In any app, switch to the
  EchoLedger keyboard and tap the microphone.
- Say "new line" or "new paragraph" to start a new line.
- In **Account → Voice notes & dictation** you can add **replacements** (fix how a name is spelled) and **voice snippets**
  (say "insert signature" to add a saved text).

## 9. Search

- **Windows and phone:** type in the search box of the **Library**. Two letters are enough. Results show the
  matching line. Tap one to play the recording from that moment.
- **Web console:** open **Search**. You can filter by client, app, device, date, tag, bookmarks and status.
- Press **Ctrl+K** (**Cmd+K** on a Mac) in the console or the computer app to jump to a page, a recording or a
  command. On the computer it also makes things (**Record now**, **Upload files**, **New project**, **Request link**,
  **Invite people**) and opens any page by name (**Devices**, **Plan & usage**, a project). Press **/** to search the
  Library's list.

**Group by and Sort by (computer, since v1.5).** Above the Library's list, **View** (since v1.6, the same menu as the
phone's View sheet) holds **Group by**, **Sort by** and **Density**. **Group by** puts recordings under headers by
**Date** (the default), **App / Type**, **Project**, **Language**, **Status**, **Device** or **None**, and **Sort by**
orders them **Newest**, **Oldest**, **Longest**, **Shortest**, **Name A–Z** or **Recently opened** (on this computer).
Each view remembers its own choice (the whole Library, the Inbox and every smart folder). A header shows the group's name
and how many recordings it holds, stays at the top while you scroll through it, and folds away with a click. Its box
selects the whole group for **Move**, **Label**, **Export** or **Delete**; it shows a dash when only some are selected.

**On the phone (since v1.6)** the same choices are in the Library's **View** sheet (the sort button next to search):
**Group by**, **Sort by** and **Density** (**Compact** fits more recordings on the screen). Each smart view remembers its
own choice on this phone. Tap a header's name or arrow to fold it; while selecting, its box selects the whole group, and a
long press on a header starts selecting with that group.

**In the web console (since v1.6)** the Library has a column on the left on a wide screen: **All**, **Waiting**,
**Pinned**, **Failed uploads** (and **Today**, **This week** and **Bookmarked** while they hold something), the smart
folders with their counts (Inbox, WhatsApp, WhatsApp Business, Meet, Zoom, Teams, SIM, Voice notes, Imported, Shared
with me), then your projects with **+ New project**. On a narrower screen the toolbar's **All recordings** and **All
folders** menus do the same. The toolbar under the search box has **Group by** and **Sort by** with the same choices as
the phone and the computer, and **List** or **Table** (the table's **Columns** chooses what it shows). A header's box only
selects its group (a dash when only some are selected); its name and arrow fold it. Select recordings and one bar shows
what you can do with them: **8 selected · Move · Label · Export · Delete · More · Clear** (**More** pins, unpins, keeps
forever or stops keeping). An empty view says why it is empty and offers **Show all recordings**.

**Failed uploads (console, since v1.6).** While something did not upload or could not be transcribed, a line above the
Library says **Failed uploads (2) · See why**. In that view each recording says what went wrong and what happens next, in
plain words: an upload from a phone or computer is sent again by that device the next time EchoLedger is open there and
online; **Retry** carries on an upload from this browser (choose the same file again) or transcribes a recording again
(after a busy engine, the monthly limit, or adding your own Groq key); **Delete** puts it in Recently deleted for 48 hours.

## 10. Import, rename, export and share

### Import files

Bring in recordings you already have: WhatsApp voice notes (.opus, .ogg), MP3, M4A, AAC, WAV, and the sound of MP4 or
WebM videos.

- **Phone:** **New → Voice notes & files → Import files**. Pick one file or many, choose a client and a language for
  all of them if you like, then **Import all**. Sharing to EchoLedger from WhatsApp still works too.
- Each file becomes its own recording, named after the file. A file that is already in your Library is skipped and
  marked **Already in your Library**.
- Imports show under **Uploading** at the top of the Library until the server has them. Hold one that has not started
  to cancel it; tap a failed one to try again.
- **Computer:** imported files are listed under **Importing** at the top of the Library, each with its own percent and
  **Cancel**.

**Where a recording is (computer, since v1.5).** Each recording goes through three steps: **Upload** (the percent sent),
**Transcribing** (which part, and the time left, for example "Transcribing… part 2 of 3 · about 20 s left") and
**Improving** (the Roman Urdu and other views). The Library row, the recording page and **Activity → In progress** say it
the same way. The transcript shows as soon as it is ready, with a slim **Improving Roman Urdu… 60%** bar; the view swaps
in where you are reading when it is made (unless you chose Original). **Notify me when ready** on the recording page
sends a notification when the transcript is ready, even if Transcription notifications are off.

**In the web console (since v1.6)** the Library row and the recording page say it with the same words: **Uploading
40%**, **Transcribing · part 2 of 3 · about 20 s left**, **Improving Roman Urdu… 60%**. The recording page shows the
three steps (**Upload · Transcribe · Improve**, each named for where it is: Uploading, Uploaded…), that line and a bar,
and updates by itself. **Notify me when ready** sends you one email for that recording when its transcript is ready (or
if it could not be made), even if transcript emails are off in your profile; the bell shows it as always.

### Rename

Give any recording a clear name: select it in the Library and choose **⋮ → Rename**, or tap its title on the
recording page. The new name shows on all your devices. A call waiting for Keep or Discard can be named on its card
(the pencil).

**Automatic titles (since v1.5).** A recording nobody named gets a short title from what was said, for example
"Laptop — 16 vs 32 GB"; on the computer it is marked **Automatic title**. Type your own and it is never replaced. Empty
the title field to have the automatic one again. Since v1.6 a title nobody chose counts as none: "Voice note",
"WhatsApp call" or a shared voice note's file name such as "PTT-20261108-WA0012" is replaced by an automatic title too,
and older recordings got theirs from the first words of their transcript. The player always shows the recording's real length, in every view
(Original, Roman Urdu, English and Urdu), also for a voice note whose length the server does not know yet.

### Export and share

**Export** saves a transcript as a file: Word, PDF (use **Print or save as PDF**), plain text, Markdown, SRT or
VTT subtitles, or JSON. Urdu and Hindi text keep the right shape and direction.

**Share** makes a link to a recording or a whole project.

1. Open the recording (or the project) and choose **Share**.
2. Choose who may open it:
   - **Anyone with the link**;
   - **Anyone with the link and a password**;
   - **Only people I choose**: type their emails. They sign in to the console with one of those emails, and their
     email must be confirmed.
3. Choose whether people may **download** the audio and the transcript, and when the link stops working (or
   never).
4. Copy the link. It is shown only once.

A project's link shows its recordings, newest first; each opens like a recording's link. You can revoke a link at
any time and it stops working at once. **Shares** (in the console) and the share sheet in the apps list every link
with its views and when it was last opened.

**Send to WhatsApp (computer, since v1.6).** On a recording, the WhatsApp button next to **Share**, or **⋯ → Send to
WhatsApp…** on its row, offers three ways, as on the phone:
- **Text only:** the transcript in the view you are reading (Roman Urdu, English, Urdu or the original). WhatsApp, or
  WhatsApp Web, opens with it ready; choose the chat and press send. The text is copied too: a very long transcript does
  not fit in the message, so only its start is there, and you paste the rest.
- **Text and voice:** the same, and the recording's audio is saved in your **Downloads** folder. **Attach the saved voice
  file in WhatsApp**: in the chat click **+** (or the paper clip), then **Document**, and choose the file.
- **Link (works for 7 days):** a link anyone can open for a week (with the audio when the recording has it); WhatsApp
  opens with the title and the link, and the link is copied.

**Send to WhatsApp (web console, since v1.6).** On a recording, the **WhatsApp** button next to **Share**, or **More →
Send to WhatsApp…** on its row in the Library, offers the same three ways: **Text only** (the view you are reading on the
recording page; from the Library, the view it opens in), **Text and voice** (the text in the message, and the audio saved
in your browser's downloads: **Attach the saved voice file in WhatsApp** with **+** or the paper clip, then **Document**)
and **Link (works for 7 days)**. WhatsApp, or WhatsApp Web, opens in a new tab with the message ready; the whole text is
copied too, because a long transcript only fits in part. **Copy text** is there as well, and on the recording page
**Copy for AI**.

**Send to a teammate** puts a recording or a project in the **Shared with me** folder of someone with an account on
your server. Type their email and, if you like, a note. People without an account here will not receive it, and nothing
tells you so: check with them if it matters. They can play and read it but not change it, and **Save to my library**
gives them their own copy, which stays even if you delete yours.

A sent project holds the recordings that were in it when you sent it, while they stay there. A recording you file in it
later is not sent until you send the project again; its brief, notes and files stay current.

**Sent to** (in the same menu) lists whom you sent a recording or project to. **Stop sharing** takes it off their list
and they can no longer open it. A copy they already saved stays theirs.

**Request files** makes a link anyone can use to send you voice notes, recordings or documents, without an account.
Choose a project (or the Inbox), when the link stops working, and how many files and megabytes it takes. Audio and
video become recordings; PDF, Word, text and pictures go to the project's Files. Other file types are refused.

**Links open in the app.** On a phone with EchoLedger, a share, project or request link of your server opens in the
app. A page in the browser offers **Open in EchoLedger**.

## 11. Projects and vocabulary

**Projects** (called Clients before v1.5) are folders for the people and work you record: a project keeps its
contacts, notes and instructions, files and recordings together. A project can hold folders of its own, up to four
levels (for example Acme › Invoices). Give each one a colour and a picture or letters, and pin the important ones.

- **Smart folders** sort the Library for you, without copying anything: **Inbox** (recordings in no project),
  **WhatsApp**, **WhatsApp Business**, **Google Meet**, **Zoom**, **Teams**, **Phone**, **Voice notes**,
  **Imported** and **Shared with me**. The Library itself still shows everything, newest first.
- **Filing rules** put new recordings in a project by themselves. A call with one of the project's contacts goes
  there first; then a rule on the title ("the title contains Acme"); then a rule on the app ("every Zoom call").
  Everything else waits in the Inbox. A rule never moves a recording that is already in a project. Add rules on the
  project's page.
- **Moving:** drag recordings onto the Inbox or a project in the Library's column, onto Pinned, or onto a pinned
  project in the sidebar (console and computer), or choose **Move** (all apps,
  also for several selected recordings).
- **Deleting a project** keeps its recordings: they go to the Inbox, and its folders move up a level.
- **A recording's project:** choose one on the recording's page, or set a default project in **Settings**.
- **Labels** are your own words on a recording, such as "contract". Select several recordings to move them,
  label them, pin them or export them together.
- **Vocabulary** helps EchoLedger spell names and special words. Add words to a project (**Projects → your project
  → Keyterms**) or to everyone (**Settings → General → Vocabulary**). If you fix a name in a transcript, EchoLedger offers
  to add it.
- **Replacements** change one word into another in every new transcript, for example "echo ledger" into
  "EchoLedger".

### Caller names

EchoLedger can tell who a call was with. The recording is then called **Call with Omar Khan**, not "WhatsApp call".

- **On the phone**, EchoLedger reads the name in WhatsApp's call notification. Turn it on in **Settings → Microphone →
  Permissions → Notification access**, then switch on **EchoLedger caller names**. EchoLedger reads only WhatsApp and
  WhatsApp Business call notifications. It ignores your messages and every other app. The name is kept on the phone
  for a few minutes and is sent only with that call's recording.
- **On Windows**, EchoLedger reads the name in the call window's title, for example "Omar Khan | Microsoft Teams" or
  "Meet - Weekly sync", and calls the recording **Omar Khan** or **Weekly sync**. Titles such as "Zoom Meeting" or a
  Meet code give no name. A meeting name is not added to a client's contacts.
- **Contacts.** Add the people you talk to under **Projects → your project → Contacts**: a name, a phone number and,
  if you like, their language. A call with a matching name or number is filed under that client. It is transcribed in
  the contact's language unless you chose a language for that recording.
- When you move a recording with a caller name to a client, EchoLedger adds the caller to that client's contacts. The
  next call with them is filed under the client by itself.

### Project pages: brief, call notes, memory and Ask

Open a project (**Projects → the project**) to see its tabs: **Notes** (the brief, memory and call notes),
**Recordings**, **Files**, **Screens** and **Ask**. Breadcrumbs at the top lead back up through its folders, and its
folders are listed above the tabs.

- **Call notes.** After every call, voice note or import filed under the project, EchoLedger writes short notes: what
  was asked for, changes, new things that are not in the brief, decisions, what to confirm with the client, deadlines
  and prices, and your own action items. Only sections with something in them are shown. Tap a line to hear that
  moment. Lines marked **verify** were unclear in the recording: check them before you rely on them. Nothing is added
  that was not said.
- **Brief.** One page about the project: goal, scope, constraints, deadlines, budget, people and terms. It is made from
  the project's files or, with none, from the calls so far. **Edit** it as you like; after an edit it is never rewritten
  by itself (**Make again** rewrites it on purpose).
- **Memory.** Decisions, open questions and changes from every call, dated, each with the call it came from. Hold a
  line to pin or remove it, or **Add** your own.
- **Files.** Add PDF, Word, text or Markdown files, or **Paste instructions**. They feed the brief and Ask, and stay
  encrypted on your server.
- **Ask.** Ask anything about the project ("When did we agree to launch?"). The answer is short and lists its sources:
  a call and the moment, or a file and the page. Answers can be in English or Roman Urdu.
- **Copy for AI** copies the brief, memory and latest call notes, ready to paste into your own ChatGPT or Claude
  project.
- **Screens** shows what was on the shared screen in the project's meetings (see section 15b, Screen moments).
- **AI notes** can be switched off per project. The console shows what the AI costs each month; the budget is $5
  unless you change it. Anthropic does not train its models on what EchoLedger sends.

## 12. Languages

Tell EchoLedger which language is spoken and the transcript gets much better. The choices are **Auto**, **English**,
**Urdu**, **Arabic** and **More languages** (French, German, Spanish, Italian, Portuguese, Dutch, Turkish, Russian).

- **Urdu** always allows English words inside, the way people speak in Pakistan ("کل کی meeting reschedule کر دیں").
  Arabic does too. There is no separate "Urdu + English" any more.
- **Auto** only chooses between English, Urdu and Arabic, so a call is never tagged French by mistake. An admin can
  let Auto pick a More language too (**Settings → General → Auto may also pick**).
- Hindi is not offered. Urdu is never written in Hindi letters: such text is transcribed again as Urdu.
- Urdu goes to **Whisper large-v3** (the engine TurboScribe uses), each side of the call on its own. Text it
  invents in silences is removed.
- A long call is transcribed in parts: while it runs, the recording says **Transcribing… part 3 of 8**.
- Words and lines the engine was not sure of have a **dotted underline** in the desktop app, the phone app and the
  console. Tap or click one to hear it. The text stays exactly as the engine heard it.
- **Double-check** (console: **Settings → Engine per language**, off by default): a second engine listens again to
  up to 12 of those lines. Where it heard something different, its words appear in grey under the line, for example
  "Groq Whisper large-v3 heard: …". You decide; the transcript is not changed. Each checked line costs well under
  one cent.

- **For a client:** give the client a default language. Every recording with that client uses it.
- **For everything else:** the default in **Settings → General → Transcription language** (on the phone:
  **Settings → General → Transcription**).
- **During a call:** tap the language chip (for example **UR**) on the desktop recording pill, or on the
  phone next to the caption bubble or on the Record tab. Live captions switch to the new language; the
  recording does not stop. The transcript switches language at that moment too.
- **After the call:** the "Waiting for you" list (Ask me, or calls left from before) has the same chip; the
  transcript uses whatever it shows when the call is kept. Calls kept automatically use the language of the call.
- **Any time later:** open the recording (desktop Library, the phone's recording page or the console) and choose
  **Re-transcribe as…** with the language. The current transcript stays until the new one is ready; then the new
  one is shown and **Current / Previous** switches between the two versions.
- If you change the language for a recording with a client, EchoLedger asks **"Use Urdu for Acme from now on?"**.
  **Yes** makes it that client's default.

The library, the player and exports show the language and the engine that wrote the transcript. If an Urdu
transcript comes back in Hindi letters (or the other way round), the recording page says **Wrong script detected**:
one tap transcribes it again with the next engine. Search finds Urdu words however the engine spelled them, and
English words inside Urdu speech are found too. If a transcript is still not good, open the recording in the
console and choose **Re-transcribe** or **Compare engines**, then keep the better one.

### Show as: Roman Urdu, English and Urdu

A transcript can also be read in another form: an Urdu call in **Roman Urdu** (the same words in Latin letters) or
**English**; an English call in **Urdu** or **Roman Urdu**; an Arabic call in **English**. In the console open the
recording and press **Show as…**; after a minute or two the player shows **Original · Roman Urdu · English · Urdu**
(the ones that apply), **Export** offers Word, print and text files in each view, and **Share** can open
the link in a view. In the desktop Library and on the phone's recording page the same switch writes the views on the
first tap and shows them when ready (exports from there follow the view on screen). Urdu shows in Nastaliq letters and
Arabic in Naskh. The views are
written by Claude (your administrator adds the Anthropic key) and are labelled as views: the transcript itself never
changes. If you edit the transcript, make the views again. To open transcripts in a view by default (for example always
Roman Urdu), choose it in **Settings → Engine per language → Open transcripts as**; the original stays one tap away.

### Find the best engine for your calls (Accuracy Lab)

The console's **Accuracy Lab** (owner only, under System) measures which engine writes your calls best. Use 10
typical recordings: **3 English, 3 Urdu, 3 mixed Urdu + English and 1 Arabic** (both speakers, a few noisy ones,
names you use). For each one:

1. Open the recording, choose **⋯ → Use as Accuracy Lab reference**, correct the text exactly as it was said (one
   line per turn, no speaker names) and save. A transcript of the same audio from TurboScribe, corrected, works too.
2. Choose **⋯ → Compare engines** and pick up to three engines for that language.
3. When all 10 are done, open **Accuracy Lab**. Each engine shows its word error rate (WER) and
   character error rate (CER): the share of words or letters it got wrong, left out or added. Lower is better. You
   also see how long it took and what an hour of audio cost.
4. Press **Use the winners**: the best engine becomes the first one for each language in **Settings → Engine per
   language** (the others stay as the fallback).

Since v1.5 a reference can also be in **Roman Urdu** (or uploaded as .txt, .srt, .vtt or .docx), and the Lab scores
the Original transcript and the Roman Urdu view separately, per category (Urdu, English, mixed, Arabic). **Google
Gemini** is one of the engines you can compare (add its key under **API Credentials → Google Gemini**, from a Google
project with billing on).

### Urdu waits for its best engine (since v1.5)

Urdu and mixed (Auto) recordings are never handed to a weaker engine because the best one is busy. When it is rate
limited or down, the recording waits and is tried again (after 2, 4, 8, 16, then every 30 minutes), up to **6 hours**
(**Settings → Engine per language → Urdu waits for its best engine up to**). The recording page says **"Waiting for
Groq — retrying at 2:05 pm"** meanwhile. Only after the wait does the next engine write it, and the recording then
says **"Backup engine — Re-transcribe?"**; **Re-transcribe** tries the best engine again. English and the other
languages still move to the next engine at once. The console's **API Credentials → Groq** row shows your plan's
limits and this hour's use; EchoLedger paces its requests to stay under them.

### Privacy at the speech providers

EchoLedger deletes its copy at each provider as soon as the transcript is stored. Three switches in the providers'
own accounts make sure none of them trains on your recordings: opt out of model training at AssemblyAI (or choose
the EU data region in **Settings → AssemblyAI**), turn off **Improve the models for everyone** at ElevenLabs, and turn
on **Zero Data Retention** at Groq. Your administrator has the details per provider.

## 13. App lock

- **Windows:** **Account → This computer → App lock**. Set a PIN. Windows Hello can unlock it too. The
  window locks after the time you choose. Recording carries on while it is locked.
- **Phone:** **Settings → Privacy & security → App lock**. It uses your fingerprint, face or phone PIN, so your
  phone needs a screen lock first. **Hide content in Recents and screenshots** keeps your recordings private.
- **Web console:** open **Security** to add a passkey, turn on two-step sign-in and see who signed in.

## 13b. Notifications (since v1.5)

**Computer: Account → Notifications.** Every notification belongs to one kind, the same list as the phone's:
**Recording**, **Transcription**, **Enhancement** (Roman Urdu and other views, briefs, call notes), **Uploads**,
**Call capture & readiness**, **Sharing**, **Account & security**, **Usage & limits**, **Live Translate**, **App updates**
and **Other**. Turn each kind on or off, choose **Sound** or **Silent**, and press **Test** to see one. **Show
notifications** turns them all off. **Quiet hours** (from and to) hold notifications back, except **Call capture &
readiness**, which is Critical: a call that will not be recorded is always said.

**On the phone (since v1.6)**, **Settings → Notifications → Kinds of notification** lists each kind with its own switch,
**Test** (one sample notification) and **Sound & vibration** (Android's own page for that kind). If you switched a kind off
in Android's settings, it says so here with **Turn on in Android**. **Recording** cannot be switched off: Android shows it
while a recording runs.

**The bell in the console (since v1.6)** counts only what needs you: a transcription or upload that failed, the monthly
limit reached, an answer to your upgrade request, and calls waiting for **Keep** or **Discard**. Finished transcripts,
new devices and shares are listed but not counted. Above nine it says **9+**.

## 14. Keep recordings for a set time (retention)

- In **Settings → Storage and retention**, choose how long recordings are kept: **7**, **14**, **30** days or a
  number of your own. A client can have its own time. A recording marked **Keep** is never removed.
- Deleted recordings wait **48 hours** in **Recently deleted**. You can restore them there. After that they are
  gone for good, and nobody can play or share them in between.

## 15. Usage and budget

- The console **Usage** page shows the minutes and the cost for each month, per provider, device and client.
- Your administrator sets a **monthly limit** in the console **Settings → Storage**. At 80% you get a message. At 100% new
  captions and new transcripts stop, but your recording carries on and is kept on your device.

## 15a. Plans, paid features and your own transcription key (computer, since v1.6)

- **Account → Plan & usage** (also in the menu under your initials, top right) shows your plan, what you used of each limit (devices, storage, uploads,
  minutes) and your latest upgrade request.
- A feature your plan does not include shows one badge, like **PRO ✦** or **BUSINESS ✦**, with a small lock: on
  Record (Live Translate), on a transcript (Roman Urdu, English and Urdu views), in Settings (Screen moments), on a
  project's Notes, and in Plan & usage. Click it to see what the feature does and the plans side by side. **Request
  upgrade** (with a message if you like) sends the request to the owner; the app then shows how to pay and says
  "We'll activate it once confirmed". A pending request shows as pending until the owner decides.
- **Free plan: your own Groq key.** Free accounts transcribe with their own free Groq key. Open **Account →
  Transcription key** and follow the three steps: open console.groq.com and sign in, choose **API Keys → Create API
  key** and copy it, paste it and press **Save**. The server checks it once and keeps it encrypted, only for your
  recordings. A recording made before you added a key says so, with **Add my Groq key**; after adding it, use
  **Re-transcribe** on that recording.
- The computer app has no transcription engine of its own: on the Free plan, transcripts come from your Groq key.
  Live captions during a call use EchoLedger's speech service and follow your plan.

## 15b. Screen moments: what was on the screen (since v1.5)

During a meeting, the computer app keeps what was on the shared screen at each moment, so you (and an AI reading
the transcript) know what "this page" or "click here" meant.

- **Turn it on** in the computer app: **Account → Calls & meetings → Screen moments**. On a Mac, allow EchoLedger under **System
  Settings → Privacy & Security → Screen & System Audio Recording**. From macOS 15, macOS asks again about once a month
  whether EchoLedger may still see your screen: choose **Allow**. If you miss it, screen moments pause and the recording
  goes on. On Windows 10 a thin yellow border shows around what is captured; Windows 11 shows none.
- **What is kept:** only during a meeting EchoLedger detected, or a recording you started, and by default only the
  meeting's window (Zoom, Teams, Google Meet in a browser, WhatsApp). A screen is kept when the picture changes and
  settles, or at once when someone says "this page", "click here", "yeh dekhein" and the like. At most about 120 an
  hour.
- **Privacy:** the pill's monitor chip shows whether screens are on and pauses them with one tap. Screens pause by
  themselves while the meeting window is minimised or hidden, and whenever an app on your excluded list is on the
  screen (password managers are on it from the start). EchoLedger's own window is never in a screen. A project can
  turn screens off for its meetings. Screens are encrypted on your computer until they are sent, and on your
  server after that; they are deleted with their recording.
- **In the transcript:** each screen sits under the line it was shown at ("Screen 7 · 14:32"). Click it to see it
  large, read the text on it, and play from that moment. A project's **Screens** tab collects all of them.
- **Text and descriptions:** your server reads each screen's text and writes one line about it (with Claude Haiku),
  so search finds words that were only on a screen. Switch it off in the console: **Settings → Screen moments →
  Describe screens**.
- **For your AI:** **Copy for AI** adds a line like "[Screen 7 at 14:32: the invoice page]" at each screen.
  **Export → Meeting pack** makes a PDF (the transcript with each screen at its moment; Claude and ChatGPT read both)
  or a ZIP with the transcript, the pictures and, if you like, the audio.

## 15c. Ask Claude or ChatGPT about your recordings (since v1.5)

Add EchoLedger as a connector in Claude or ChatGPT, and you can ask them things like "What did Omar ask for on Tuesday's
call?". They read what you can open in the apps, and nothing else: they cannot record, change, share or delete
anything. You disconnect them under **Profile → Connected apps**.

How to add it, what the AI can and cannot see, and example questions: [Claude and ChatGPT connector](CONNECTOR.md).

## 16. Live Translate: games and videos

Live Translate shows what is said in another app, translated, while you use it. It is for you, not for clients:
nothing is recorded or kept unless you switch saving on.

- **Game translate** (phone): teammates in PUBG Mobile, Call of Duty: Mobile or another game speak Arabic (or any
  language); English text appears in a small box over the game. Open the centre button → **Game translate**, choose
  your games and press **Start**, or switch on **Start with the game** so it starts when voice chat plays. The box
  never takes a touch: every tap goes to the game. Tap the small dot beside it to move or resize it (it locks again
  after 5 seconds); hold the dot for Pause, Hide, text size, opacity, the original line, the language and Stop.
- **Watch translate** (phone): films and videos get subtitles in Urdu (or English, Arabic…). Apps that protect their
  videos, such as Netflix, do not allow it and say so.
- **Test translate** on each page plays a short sentence with the phone's own voice and shows the result, so you can
  set the box up without opening a game.
- **On the computer** (Windows): the **Record** page → **Live Translate**: choose an app that is playing sound, Game or
  Watch, the language, then **Start**. The overlay never takes the mouse; **Ctrl+Shift+T** lets you move it,
  **Ctrl+Shift+H** hides it. It works over borderless or windowed games, not exclusive full screen. A browser is heard
  with all its tabs.
- **Quick Settings tiles** "Game translate" and "Watch translate", and the app icon's long-press menu, switch it on and
  off (also from Samsung Routines).
- **Cost and privacy:** about $0.18 an hour, only while someone speaks (the connection closes when it is quiet). The
  sound goes from your phone or computer straight to Soniox, which does not store it or train on it. Live Translate
  has its own monthly budget (default $10, Game translate → Privacy and budget): a warning at 80%, a pause at 100%,
  and for a workspace's owners and admins **Continue this month anyway**, up to twice the budget (a member asks an
  owner). It never stops your call transcripts.
- **Saving:** off by default. **Save session text** (Game) and **Save subtitles (.srt)** (Watch) write the translated
  text to Downloads/EchoLedger on the phone.
- It needs the capture helper (Shizuku) to hear game voice chat. Without it the phone asks to share the sound each
  time, and voice chat may be missing.

---

## Something is wrong?

**I hear nothing from the other side ("Not hearing the other side")**
- Make sure the call's sound plays through the speakers or headset that Windows uses by default.
- On Windows, open **Settings → Recording → Microphone** and run the 10-second test while the other side talks.
- On the phone, call capture must be connected. Open **Settings → Devices → This phone → Setup guide**. After a
  restart of the phone you must turn it on again.
- A muted call or a sound that is turned all the way down also gives silence.

**My call was not recorded**
- Check that **Auto-record calls** and the app's switch are on (computer: **Account → Calls & meetings → Recording**).
- Open the **Record** screen. It says why the call was ignored, for example "Ignored: unknown site".
- In a browser, only Google Meet, WhatsApp Web and Zoom in the browser are known. Add other sites under **Call
  sites**, or record by hand.
- A short sound, such as a voice message, is not a call.

**A phone call says "No audio"**
- Open **Setup → Call capture → Test call capture** and speak for a few seconds. It checks each step and says
  what to do in plain words. Run it during a WhatsApp call to check the other side too.
- After a restart of the phone, start Shizuku again (or run the setup command). While auto-record is on and the
  helper is not running, the phone says **"Call capture isn't ready — tap to fix"**.
- Only one app can record a call: turn off other call recorders, for example CallVault.
- **Copy diagnostics** (same screen) copies a short report you can send to support. It never contains audio,
  names, numbers or what was said.
- A recording with no sound is marked **No audio** in grey. It has nothing to play; delete it in one tap.

**An upload is stuck**
- Open the Library and look at the upload list. Press **Retry**.
- Check your internet connection and that your server is reachable.
- Your recording stays on your device until the server has checked it, so nothing is lost.
- If it says **Storage limit reached**, ask your administrator to raise the limit in the console.

**"Monthly transcription limit reached"**
- Ask your administrator to raise the limit in the console **Settings** or on **Workspace**. Recordings are still
  made and kept.
- When the limit is raised, open the recording in the console and choose **Transcribe again**.

**"This computer was signed out on the server"** (or "This phone was signed out on the server")
- The device was signed out: revoked in the console or under **Account → Devices**, or by **Sign out everywhere**.
  Sign in again (section 3). Recordings waiting to upload stay on the device.

**I forgot my app lock PIN**
- On Windows, use **Windows Hello** if it is set up. Otherwise close EchoLedger, delete the file `app_lock.json` in
  `%APPDATA%\com.echoledger.desktop`, and start it again. The lock is then off and you can set a new PIN.
  Your recordings are not touched. On the phone, use your phone's own screen lock.

Your administrator can find more detail in the server's `web/DEPLOY.md`.
