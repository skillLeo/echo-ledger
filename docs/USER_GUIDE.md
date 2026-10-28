# EchoLedger user guide

EchoLedger records your calls, meetings and voice notes, shows live captions while you talk, and gives you a
clear written transcript afterwards. Your recordings stay on your own server.

You use it in three places:

- **The Windows app** on your computer records calls and shows captions.
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

## 2. Install the phone app

1. Copy the `.apk` file to your phone and open it.
2. Android asks whether this source may install apps. Allow it once.
3. Open EchoLedger. A short setup guide asks for the microphone, notifications and pairing.

**WhatsApp calls** need Android 14 or newer and a one-time setup. In **Settings → Devices → This phone**, open the **Setup guide** and follow the steps (Shizuku or a computer with ADB). You do this once. After a phone restart, the guide
shows how to turn it on again.

**Voice notes** need no setup. In WhatsApp, press and hold a voice note, tap **Share**, then choose EchoLedger.

## 3. Pair your devices

Pairing connects a device to your server. You need the web console.

1. In the console, open **Devices** and click **Pair a device**. A code and a QR code appear. The code works for
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
- **Phone calls (SIM):** open **Settings → Auto-record apps → Record phone calls** and choose **Off** (the default),
  **Ask each time** (a notification asks "Record this phone call?" when a call starts; tap **Record**) or **Always**.
  It works while auto-record is on and the call capture helper is running. Allow **Call log** and **Contacts** so each
  recording gets the number and the contact's name ("Call with Omar Khan"), and is filed under that client.
  **Tell the other person before you record a phone call. In many places the law needs their consent.** Some phones
  give both voices on one track; the transcript then cannot tell who spoke.

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

## 6. Keep or Discard

When a recording ends, EchoLedger asks what to do with it.

- **Keep** uploads the recording and makes the transcript.
- **Discard** deletes it from your device. Nothing is uploaded and nothing is paid for.

If you do nothing, the choice is made after 60 seconds. You can set it to **Keep** (the default) or **Discard**.
A recording that is still undecided is listed under **Waiting for your decision**, also after a restart.

In **Settings → Recording**, **Ask before saving** can be **Always**, **Only automatic calls** or **Never**.
The pill (the small bar at the top of the screen) and the notification on the phone have **Pause** and
**Stop** buttons. Paused time is not recorded.

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

## 10. Export and share

**Export** saves a transcript as a file: Word, PDF (use **Print or save as PDF**), plain text, Markdown, SRT or
VTT subtitles, or JSON. Urdu and Hindi text keep the right shape and direction.

**Share** makes a link that anyone can open without an account.

1. Open the recording and choose **Share**.
2. Pick how long the link works: **1 hour**, **1 day** or **7 days**.
3. Add a password if you want one. Choose whether the link can play the audio.
4. Copy the link. It is shown only once.

You can revoke a link at any time and it stops working at once. The recording page lists every time a link was
opened.

## 11. Clients and vocabulary

- **Clients** are folders for the people you work with. Give each one a colour and a picture or letters, and
  pin the important ones. Choose a client for a recording, or set a default client in **Settings**.
- **Labels** are your own words on a recording, such as "contract". Select several recordings to move them,
  label them, pin them or export them together.
- **Vocabulary** helps EchoLedger spell names and special words. Add words to a client (**Clients → your client
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
- **Contacts.** Add the people you talk to under **Clients → your client → Contacts**: a name, a phone number and,
  if you like, their language. A call with a matching name or number is filed under that client. It is transcribed in
  the contact's language unless you chose a language for that recording.
- When you move a recording with a caller name to a client, EchoLedger adds the caller to that client's contacts. The
  next call with them is filed under the client by itself.

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
- **After the call:** the Keep card, the phone popup and the "Waiting for your decision" list have the same chip.
  The transcript uses whatever it shows when you press **Keep**.
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

**"This device was unpaired on the server"**
- The device was revoked in the console. Pair it again (section 3). Recordings waiting to upload stay on the
  device.

**I forgot my app lock PIN**
- On Windows, use **Windows Hello** if it is set up. Otherwise close EchoLedger, delete the file `app_lock.json` in
  `%APPDATA%\com.echoledger.desktop`, and start it again. The lock is then off and you can set a new PIN.
  Your recordings are not touched. On the phone, use your phone's own screen lock.

Your administrator can find more detail in the server's `web/DEPLOY.md`.
