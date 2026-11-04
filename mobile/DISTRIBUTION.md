# EchoLedger for Android: the website build and the Google Play build

Since v1.5 the app is built in two flavors from the same code. They share one application id, so a phone has one of
them, not both. A phone cannot switch from one to the other without uninstalling, because Google Play signs its build
with its own key.

| | Website build (`full`) | Google Play build (`play`) |
|---|---|---|
| Where people get it | The server's `/downloads` page (APK) | Google Play (AAB) |
| Call auto-record (WhatsApp, WhatsApp Business, other call apps, SIM) | Yes, through the capture helper (Shizuku or ADB) | No |
| Who a SIM call was with, and its talk time | Yes (call log) | No |
| Caller names from call notifications | Yes (notification access) | No |
| Voice notes, Record tab, imports, sharing audio to EchoLedger | Yes | Yes |
| Voice keyboard (dictation) | Yes | Yes |
| Live Translate | The helper's per-app audio, or Android's screen-audio capture | Android's screen-audio capture only (Android asks each time) |
| Updates | Itself, from the server (see below) | Google Play |
| Delete the account | In the app (You → Account) and on the server's `/account/delete` page | Same |

## Why the Play build has fewer call features

- **Call log** (`READ_CALL_LOG`, `PROCESS_OUTGOING_CALLS`): Play's SMS and Call Log policy allows these only for the
  default phone or assistant app. The Play build declares neither and never asks for them.
- **The capture helper** runs as the shell user through Shizuku or ADB to hear the other side of a call. Play's
  Device and Network Abuse policy does not allow getting around Android's permission model this way. The Play build
  removes the helper's providers, the Shizuku permission and the call-only permissions (contacts, audio files,
  notification access), never binds Shizuku, and keeps auto-record off, even with settings imported from a website
  build.
- **Special-use foreground services** kept the call pipeline ready for the helper. The Play build declares only
  `microphone` (recording you start) and `mediaProjection` (Live Translate), each started only by the person.
- **Accessibility** is not used by either build. Play bans call recording through Accessibility.
- **Updating itself**: Play forbids apps that update themselves any other way. The Play build has no updater code and
  no `REQUEST_INSTALL_PACKAGES` permission.

`PlayBuildTest` checks the Play build's merged manifest and these switches on every test run.

## Build

```powershell
$env:JAVA_HOME = "$env:LOCALAPPDATA\Android\Sdk\jdk"
.\gradlew.bat assembleFullDebug        # app/build/outputs/apk/full/debug/app-full-debug.apk
.\gradlew.bat assembleFullRelease      # app/build/outputs/apk/full/release/app-full-release.apk (signed, see RELEASE.md)
.\gradlew.bat bundlePlayRelease        # app/build/outputs/bundle/playRelease/app-play-release.aab
.\gradlew.bat testFullDebugUnitTest testPlayDebugUnitTest ktlintCheck lintFullDebug lintPlayDebug
```

Screenshots for the design docs come from the website build: `recordRoborazziFullDebug`, `verifyRoborazziFullDebug`.

## How the website build updates itself

1. It reads the server's `/downloads/android.json` at most every 12 hours while online (a WorkManager job) and
   whenever someone taps **You → General → About → Check for updates**. The request carries no token.
2. A build with a higher `code` (and a `min_sdk` this phone meets) is offered on the Record tab and in About. The
   banner can be closed for that version; About keeps offering it.
3. **Update** downloads the APK from the same server only (a redirect elsewhere stops it). The app checks the size
   and the SHA-256 published in `android.json`, then hands the file to Android's installer. The first time, Android
   asks to allow EchoLedger to install apps. Android shows its own confirmation and refuses a file not signed with the
   same key as the installed app.
4. The server publishes builds with `php artisan echoledger:publish-release` (docs/API.md, "Public pages and app
   downloads").

## Google Play Console (drafts for the listing)

**App content → Foreground service types**

- `microphone` (RecordingService): "Records a voice note or a meeting that the person starts with the Record button.
  A notification with Stop is shown for the whole recording."
- `mediaProjection` (LiveTranslateService): "Live Translate shows subtitles of another app's audio (a game's voice
  chat, a video) in a floating window. The audio comes from Android's screen-audio capture after the person agrees on
  Android's prompt. A notification with Stop is shown while it runs."

Each needs a short screen recording of the feature in use.

**App content → Data safety (draft for review)**

| Data type | Collected | Why | Notes |
|---|---|---|---|
| Audio (voice or sound recordings) | Yes | App functionality | Recordings the person makes or imports, uploaded to their EchoLedger server for transcription. |
| Other user-generated content | Yes | App functionality | Transcripts, notes, project files. |
| Name, email address | Yes | Account management | The account the app signs in to. |
| App interactions, crash logs, device IDs | No | | No analytics or crash SDK in the app. |

- Contacts, call logs and other apps' notifications: not read by the Play build (only the website build uses them,
  for call recording).
- Data is encrypted in transit (HTTPS, with the server's certificate pinned when paired by QR).
- People can ask for deletion in the app (You → Account → Delete my account) and on the web page
  `https://<server>/account/delete`.
- "Shared with third parties": No. The transcription and AI providers (listed in the Privacy Policy) process the
  audio for the server, as service providers, which Play does not count as sharing.

**Store listing → Privacy policy**: `https://<server>/privacy`.
**App content → Account deletion URL**: `https://<server>/account/delete`.

## App Links for the Play build

Google Play signs the Play build with the app signing key from Play Console → Setup → App signing. Add that key's
SHA-256 fingerprint to the server's App Links fingerprints (next to the website build's), so that share, project and
request links open in the Play build too.
