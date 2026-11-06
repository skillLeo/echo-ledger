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

**Starting at login** is off until you turn it on in **Settings → General**.

**The Mac check.** **Settings → General → Help & diagnostics → Run Mac check** looks at macOS, the permissions, the
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

## 3. Sign in or pair your devices

**Sign in (phone, since v1.5).** On the phone's first screen, **Sign in to EchoLedger**: type the server address,
your email and password, then the code from your authenticator app when it asks. **Sign in in the browser** does the
same with Google or a passkey: the browser opens, you sign in there, and the phone is signed in when you come back.
Your account's page on the phone (**You → Account**) exports your data, signs out every device, or deletes the
account (you have 7 days to change your mind by signing in again).

**Pairing** still works, also for older apps. It connects a device to your server with a one-time code; you need
the web console.

1. In the console, open **Profile → Devices** and click **Sign in a device**. A code and a QR code appear. The code works for
   10 minutes and only once.
2. **Phone:** tap **Scan QR code** and point the camera at the screen. Or type the server address and the code.
3. **Windows:** type the server address and the code.
4. The library appears. Repeat for each device.

To remove a device, open **Devices** in the console and revoke it. It is signed out at once.

## 4. Record automatically

EchoLedger can start recording when a call starts.

- **Windows:** open **Settings → Recording**. Turn on **Record calls automatically**. Under **Auto-record
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

To record by hand, press **Ctrl+Alt+Shift+R** on Windows, or tap **REC** on the phone.

## 5. Live captions

Captions show what you and the other person say, while you talk.

- **Windows:** press **Ctrl+Shift+Space** to show or hide them. They never take the keyboard focus, and clicks
  go through them.
- **Phone:** captions appear as a small floating bubble.
- **Settings → Captions** changes the size, the number of lines, the position, the colours and how fast they
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
  insert the text. In **Settings → Dictation** you can choose **Hold to talk** instead.
- **Phone:** in **Settings → Dictation**, tap **Turn on the voice keyboard**. In any app, switch to the
  EchoLedger keyboard and tap the microphone.
- Say "new line" or "new paragraph" to start a new line.
- In **Settings → Dictation** you can add **replacements** (fix how a name is spelled) and **voice snippets**
  (say "insert signature" to add a saved text).

## 9. Search

- **Windows and phone:** type in the search box of the **Library**. Two letters are enough. Results show the
  matching line. Tap one to play the recording from that moment.
- **Web console:** open **Search**. You can filter by client, app, device, date, tag, bookmarks and status.
- Press **Ctrl+K** (**Cmd+K** on a Mac) in the console or the Windows app to jump to a page, a recording or a
  command.

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

### Rename

Give any recording a clear name: select it in the Library and choose **⋮ → Rename**, or tap its title on the
recording page. The new name shows on all your devices. A call waiting for Keep or Discard can be named on its card
(the pencil).

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

**Send to a teammate** puts a recording or a project in the **Shared with me** folder of someone with an account on
your server. Type their email and, if you like, a note. They can play and read it but not change it, and **Save to
my library** gives them their own copy, which stays even if you delete yours.

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
- **Moving:** drag recordings onto a project in the sidebar (computer and console), or choose **Move** (all apps,
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

- **Windows:** **Settings → Privacy & security → App lock**. Set a PIN. Windows Hello can unlock it too. The
  window locks after the time you choose. Recording carries on while it is locked.
- **Phone:** **Settings → Privacy & security → App lock**. It uses your fingerprint, face or phone PIN, so your
  phone needs a screen lock first. **Hide content in Recents and screenshots** keeps your recordings private.
- **Web console:** open **Security** to add a passkey, turn on two-step sign-in and see who signed in.

## 14. Keep recordings for a set time (retention)

- In **Settings → Storage and retention**, choose how long recordings are kept: **7**, **14**, **30** days or a
  number of your own. A client can have its own time. A recording marked **Keep** is never removed.
- Deleted recordings wait **48 hours** in **Recently deleted**. You can restore them there. After that they are
  gone for good, and nobody can play or share them in between.

## 15. Usage and budget

- The console **Usage** page shows the minutes and the cost for each month, per provider, device and client.
- Your administrator sets a **monthly limit** in the console **Settings → Storage**. At 80% you get a message. At 100% new
  captions and new transcripts stop, but your recording carries on and is kept on your device.

## 15b. Screen moments: what was on the screen (since v1.5)

During a meeting, the computer app keeps what was on the shared screen at each moment, so you (and an AI reading
the transcript) know what "this page" or "click here" meant.

- **Turn it on** in the computer app: **Settings → Screen moments**. On a Mac, allow EchoLedger under **System
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
  and **Continue this month anyway** if you want more. It never stops your call transcripts.
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
- Check that **Auto-record calls** and the app's switch are on (**Settings → Recording**).
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
- The device was signed out: revoked in the console or under **Profile → Devices**, or by **Sign out everywhere**.
  Sign in again (section 3). Recordings waiting to upload stay on the device.

**I forgot my app lock PIN**
- On Windows, use **Windows Hello** if it is set up. Otherwise close EchoLedger, delete the file `app_lock.json` in
  `%APPDATA%\com.echoledger.desktop`, and start it again. The lock is then off and you can set a new PIN.
  Your recordings are not touched. On the phone, use your phone's own screen lock.

Your administrator can find more detail in the server's `web/DEPLOY.md`.
