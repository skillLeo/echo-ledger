# EchoLedger — Android call-capture core (clean-room research)

> Area owner: Android capture core. Researched 2026-10-24. Target device: Samsung S25 Ultra (ships
> Android 15/16). Baseline AOSP tag read during research: `android-16.0.0_r1` (plus `android-10.0.0_r1` …
> `android-15.0.0_r1` for the permission-evolution table).
>
> **Clean-room statement.** Nothing here was taken from CallVault, BCR, or any GPL call-recording app.
> Every fact is cited inline to a primary source: AOSP via
> `android.googlesource.com` / the `aosp-mirror` GitHub raw mirror, `developer.android.com`, the MIT
> `RikkaApps/Shizuku-API` repo, and the LICENSE/README of each permissively-licensed library named.
> Anything that could not be confirmed from a primary source is marked **UNVERIFIED** with the reason.
>
> **License discipline.** Every library mentioned has its license recorded (see the
> [Library license summary](#library-license-summary)). The one real licensing trap for a sellable
> build is the SPAKE2 dependency used for ADB pairing — see topic 6.

---

## TL;DR — feasibility verdict

1. **Far-party WhatsApp VoIP audio (the hard part) is capturable without root, but only on Android
   14+.** WhatsApp renders the remote party with `AudioAttributes.USAGE_VOICE_COMMUNICATION`. The
   public `AudioPlaybackCaptureConfiguration` API *cannot* capture that usage (it is restricted to
   `USAGE_UNKNOWN`/`USAGE_MEDIA`/`USAGE_GAME`). The only route is a **hidden dynamic audio-policy
   mix** (`AudioPolicy` + `AudioMix` + `AudioMixingRule`) registered from a process that holds
   `MODIFY_AUDIO_ROUTING` + `CAPTURE_VOICE_COMMUNICATION_OUTPUT` (+ `CAPTURE_MEDIA_OUTPUT` to bypass
   the app's capture opt-out). The `com.android.shell` UID (2000) accumulated exactly those
   permissions over Android 13 → 14 (table below), so an ADB/Shizuku-launched `app_process` helper
   can do it on Android 14, 15, 16.
2. **The near-end mic can be captured *concurrently* while WhatsApp holds it** — this is *proven*
   from `AudioPolicyService::updateUidStates_l`: a recorder whose client has
   `canBypassConcurrentPolicy` (set from `CAPTURE_AUDIO_OUTPUT`, which Shell holds from Android 11, on
   AOSP release configs; see topic 3 for the flag-gated replacement permission)
   bypasses both the privacy-sensitive gate and the in-call gate. It is gated only by the
   `RECORD_AUDIO` app-op and the hardware mic kill-switch.
3. **Two independent on-ramps**: (a) MIT **Shizuku** user-service, or (b) built-in **Wireless
   debugging** pairing driven on-device by the Apache-dual-licensed **libadb-android**. Both end with
   a helper running as UID 2000 that hands its binder back to the app through a UID-checked
   `ContentProvider.call()`.
4. **Output**: interleave L=mic / R=far-party into a stereo stream and encode with the system
   `MediaCodec` Opus encoder (Android 10+) into an Ogg container (`MUXER_OUTPUT_OGG`).
5. **Floor**: the whole far-party technique needs **Android 14 (API 34)** as the practical minimum,
   not the spec's "min SDK 29." SDK 29–33 can do mic-only / partial capture (see table).

---

## 1. Shell UID (2000) audio permissions, Android 10 → 16

`adb shell` and anything it launches with `app_process` run as UID 2000, whose granted permissions are
the union of what packages sharing `android:sharedUserId="android.uid.shell"` declare — effectively
`com.android.shell`'s manifest. I read `packages/Shell/AndroidManifest.xml` at each release tag.

| Permission (`android.permission.*`) | A10 | A11 | A12 | A13 | A14 | A15 | A16 |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| `RECORD_AUDIO`                       | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `CAPTURE_AUDIO_OUTPUT`               | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `MODIFY_AUDIO_SETTINGS`              | ✗ | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `MODIFY_AUDIO_ROUTING`               | ✗ | ✗ | ✗ | ✓ | ✓ | ✓ | ✓ |
| `CAPTURE_MEDIA_OUTPUT`               | ✗ | ✗ | ✗ | ✗ | ✓ | ✓ | ✓ |
| `CAPTURE_VOICE_COMMUNICATION_OUTPUT` | ✗ | ✗ | ✗ | ✗ | ✓ | ✓ | ✓ |
| `MODIFY_AUDIO_SETTINGS_PRIVILEGED`   | ✗ | ✗ | ✗ | ✗ | ✓ | ✓ | ✓ |

Package `com.android.shell`, `sharedUserId="android.uid.shell"` throughout. On A16 the manifest also
carries `MODIFY_PHONE_STATE` and `CAPTURE_TUNER_AUDIO_INPUT` (both already present on A10).
All seven permissions in the table are still requested at `android-17.0.0_r1` (added by
verification: https://android.googlesource.com/platform/frameworks/base/+/refs/tags/android-17.0.0_r1/packages/Shell/AndroidManifest.xml).

Sources (one tag each):
`.../android-10.0.0_r1/packages/Shell/AndroidManifest.xml` …
`.../android-16.0.0_r1/packages/Shell/AndroidManifest.xml`
(e.g. https://android.googlesource.com/platform/frameworks/base/+/refs/tags/android-16.0.0_r1/packages/Shell/AndroidManifest.xml ,
and the r1 tags for 10/11/12/13/14/15).

**What each threshold unlocks for a shell-UID helper:**

- **A10**: Shell can't record at all (no `RECORD_AUDIO`/`CAPTURE_AUDIO_OUTPUT`). Technique impossible.
- **A11–A12**: `RECORD_AUDIO` + `CAPTURE_AUDIO_OUTPUT` → mic capture + concurrent-capture bypass
  (topic 3), but **no** `MODIFY_AUDIO_ROUTING` → cannot register an `AudioPolicy` dynamic mix, so no
  far-party capture via policy. (A12 adds `MODIFY_AUDIO_SETTINGS` only.)
- **A13**: adds `MODIFY_AUDIO_ROUTING` → can now `registerAudioPolicy`, but still lacks
  `CAPTURE_VOICE_COMMUNICATION_OUTPUT`, so a mix matching `USAGE_VOICE_COMMUNICATION` is refused by the
  native layer (topic 2). Media/game playback capture works; VoIP downlink does not.
- **A14+**: adds `CAPTURE_MEDIA_OUTPUT` + `CAPTURE_VOICE_COMMUNICATION_OUTPUT`
  (+ `MODIFY_AUDIO_SETTINGS_PRIVILEGED`) → **full far-party VoIP capture via policy mix**.

> Note: these are all signature/privileged permissions. UID 2000 holds them because the platform
> *grants* them to `android.uid.shell`; a normal third-party app cannot obtain them. That is the whole
> reason the capture must run inside the shell-UID helper, not in the EchoLedger app process.

---

## 2. Capturing far-party VoIP audio (WhatsApp uses `USAGE_VOICE_COMMUNICATION`)

### 2a. Why the *public* playback-capture API is a dead end

`android.media.AudioPlaybackCaptureConfiguration` (the MediaProjection-based public API) hard-restricts
the usages it will capture. Class javadoc (AOSP
`.../android-16.0.0_r1/media/java/android/media/AudioPlaybackCaptureConfiguration.java`): the usage
"MUST be `USAGE_UNKNOWN` or `USAGE_GAME` or `USAGE_MEDIA`. All other usages CAN NOT be captured."
`USAGE_VOICE_COMMUNICATION` is therefore **not capturable** by the public API — confirmed by its
absence from that list. So no amount of `MediaProjection` plumbing captures a WhatsApp call. We must
drop to the hidden `android.media.audiopolicy` dynamic-mix API.

### 2b. The hidden dynamic-mix path (what actually works)

All three classes are `@SystemApi @hide`
(`.../android-16.0.0_r1/media/java/android/media/audiopolicy/{AudioMixingRule,AudioMix,AudioPolicy}.java`):

**`AudioMixingRule.Builder`** — match the far party by playback usage. Confirmed constants/values and
rules:

- `RULE_MATCH_ATTRIBUTE_USAGE = 0x1`, `RULE_MATCH_ATTRIBUTE_CAPTURE_PRESET = 0x2`,
  `RULE_MATCH_UID = 0x4`, `RULE_MATCH_USERID = 0x8`; exclusion bit is `RULE_EXCLUSION_MASK = 0x8000`.
- `Builder addRule(AudioAttributes attrToMatch, int rule)` — rule must be `RULE_MATCH_ATTRIBUTE_USAGE`
  or `RULE_MATCH_ATTRIBUTE_CAPTURE_PRESET` (else `IllegalArgumentException`).
- `Builder addMixRule(int rule, Object property)` — attribute rules take an `AudioAttributes`; UID /
  user-id / audio-session rules take an `Integer`.
- **Nothing in the class blocks matching `USAGE_VOICE_COMMUNICATION`.** A hidden
  `voiceCommunicationCaptureAllowed(boolean)` Builder method exists; its javadoc states a system app
  may capture voice-communication output "only with the `CAPTURE_VOICE_COMMUNICATION_OUTPUT`
  permission" (set/enforced internally). So you both match the usage *and* need that permission.
- To defeat an app that marked its tracks non-capturable, the hidden
  `allowPrivilegedMediaPlaybackCapture()` javadoc says "the permission `CAPTURE_AUDIO_OUTPUT` or
  `CAPTURE_MEDIA_OUTPUT` is needed."

**`AudioMix` route flags** — whether the user still hears the call (this is the crucial UX point):

- `ROUTE_FLAG_RENDER = 0x1`, `ROUTE_FLAG_LOOP_BACK = 0x2`,
  `ROUTE_FLAG_LOOP_BACK_RENDER = (LOOP_BACK|RENDER) = 0x3`.
- `LOOP_BACK` alone: audio "is rerouted back to the framework" (accessible via `AudioRecord`) and is
  **not** routed to the output device (`isRoutedToDevice()` is false without `RENDER`) → **the user
  would stop hearing the call**. Do not use this for a live call.
- `ROUTE_FLAG_LOOP_BACK_RENDER` (javadoc): "the targeted audio is played unaffected but a copy is
  accessible for capture through `AudioRecord`" (effects/volume not applied) → **user still hears the
  call and we get a copy**. This is the flag to use. It is `@hide`.
- `build()` rejects `RENDER`/`LOOP_BACK_RENDER` for *recorder* mixes ("not supported for non-playback
  mix rule"), so the far-party mix must be a **players** mix (`MIX_TYPE_PLAYERS = 0`). Default route if
  unset is `ROUTE_FLAG_LOOP_BACK`.

**`AudioPolicy`** (`.../audiopolicy/AudioPolicy.java`):

- Build with `AudioPolicy.Builder().addMix(mix).setLooper(looper).build()`.
- `AudioRecord createAudioRecordSink(AudioMix mix)` returns a loopback `AudioRecord` in the mix format,
  or `null` "if this policy was not successfully registered." (`createAudioTrackSource(AudioMix)` is the
  injection counterpart — not needed here.)
- `updateMixingRules(...)`, focus-stack getters, etc. are `@RequiresPermission(MODIFY_AUDIO_ROUTING)`.
  Native `policyReadyToUse()` accepts `MODIFY_AUDIO_ROUTING`, *or* an audio-projection, *or*
  `CALL_AUDIO_INTERCEPTION` (call-redirection path).

**Registration** — on `AudioManager` (AOSP `.../media/java/android/media/AudioManager.java`, read at
offset): `@SystemApi @hide @RequiresPermission(Manifest.permission.MODIFY_AUDIO_ROUTING) public int
registerAudioPolicy(@NonNull AudioPolicy policy)` and the matching `unregisterAudioPolicy`.

**Native permission enforcement** (`.../frameworks/av/+/android-16.0.0_r1/media/utils/ServiceUtilities.cpp`)
— the ground truth for which permission is checked:

- `captureVoiceCommunicationOutputAllowed` checks `"android.permission.CAPTURE_VOICE_COMMUNICATION_OUTPUT"`
  **specifically** (it does *not* accept `CAPTURE_AUDIO_OUTPUT`).
- `captureMediaOutputAllowed` → `CAPTURE_MEDIA_OUTPUT`; `captureAudioOutputAllowed` →
  `CAPTURE_AUDIO_OUTPUT`; `modifyAudioRoutingAllowed` → `MODIFY_AUDIO_ROUTING`.
- **AID_AUDIOSERVER (the audioserver UID) and AID_ROOT bypass these checks** via `isAudioServerOrRootUid()` (corrected by verification: https://android.googlesource.com/platform/frameworks/av/+/refs/tags/android-16.0.0_r1/media/utils/include/mediautils/ServiceUtilities.h); UID 2000 (shell) does **not** bypass — it must hold
  the literal permission. (So a *root* Shizuku helper bypasses the permission gate entirely; an
  ADB/shell helper relies on the A14 manifest grants above.)

**Net recipe for the far party:** players `AudioMix` with `AudioMixingRule` matching
`USAGE_VOICE_COMMUNICATION` (+ hidden `voiceCommunicationCaptureAllowed(true)` and
`allowPrivilegedMediaPlaybackCapture()`), `setRouteFlags(ROUTE_FLAG_LOOP_BACK_RENDER)` →
`AudioPolicy.Builder().addMix(...).build()` → `AudioManager.registerAudioPolicy(policy)` →
`policy.createAudioRecordSink(mix)` → read PCM. Works on shell UID at **A14+**.

### 2c. Getting a `Context`/`AudioManager` inside an `app_process` helper (no `Application`)

A helper launched via `app_process` has no `Application` and no `Context`. Confirmed primary option:
`android.app.ActivityThread.systemMain()` exists (`@UnsupportedAppUsage public static ActivityThread
systemMain()`, AOSP `core/java/android/app/ActivityThread.java`); it runs
`ThreadedRenderer.initForSystemProcess()`, creates an `ActivityThread`, and `attach(true, 0)` (marks
the thread "system_process", builds an initial application context). Its companion
`getSystemContext()` (referenced throughout that file, returns a `ContextImpl`-like object; exact
declaration **not quoted during research**) yields a usable `Context`, from which
`context.getSystemService(Context.AUDIO_SERVICE)` returns `AudioManager`. The idiomatic sequence is:

```java
Looper.prepareMainLooper();
ActivityThread at = ActivityThread.systemMain();
Context ctx = at.getSystemContext();
AudioManager am = (AudioManager) ctx.getSystemService(Context.AUDIO_SERVICE);
```

Alternative (lower-level, no Context): talk to `IAudioService` via
`IAudioService.Stub.asInterface(ServiceManager.getService("audio"))`. Standard AOSP pattern but the
`ServiceManager`/`IAudioService` signatures were **not opened during research** — treat as design intent,
verify in code.

**Hidden-API enforcement for `app_process`.** `registerAudioPolicy`, the `audiopolicy` classes, and
`ActivityThread.systemMain` are `@SystemApi`/`@UnsupportedAppUsage`, i.e. non-SDK. Per
`developer.android.com/guide/app-compatibility/restrictions-non-sdk-interfaces`: non-SDK restrictions
apply to all apps **except** platform-key-signed and system-image apps; there is **no documented
per-process self-exemption API** (only the device-wide dev toggle `adb shell settings put global
hidden_api_policy 1`, which "doesn't require a rooted device"). Our helper's classes load from the APK
via `PathClassLoader` (the restricted domain), so enforcement *could* apply. Robust mitigations:
  - Use **`org.lsposed.hiddenapibypass:hiddenapibypass`** (Apache-2.0, confirmed) — exposes
    `HiddenApiBypass.addHiddenApiExemptions("L")` / `setHiddenApiExemptions(...)`. Clean-room safe.
  - Or, since the helper runs as shell, set the device-wide policy before launch.
  - The common claim that *processes started by `app_process` (not forked as an app by zygote) are not
    subject to enforcement* is **UNVERIFIED** here (the non-SDK page documents only the exemptions
    above). Do not rely on it; call `HiddenApiBypass` early in `main()`.

---

## 3. Capturing the near-end mic while WhatsApp holds it (concurrent capture)

This is the question most call recorders get wrong. **Answer: yes, a `CAPTURE_AUDIO_OUTPUT`-holding
recorder keeps receiving mic audio concurrently with WhatsApp's `VOICE_COMMUNICATION` capture — and
this is proven from AOSP source, not inferred.**

Behavioral contract (`developer.android.com/media/platform/sharing-audio-input`): since Android 10 a
priority scheme silences all but the active capturer; `VOICE_COMMUNICATION` and `CAMCORDER` are
"privacy-sensitive" and outrank ordinary capture; during a call (`getMode()` ==
`MODE_IN_CALL`/`MODE_IN_COMMUNICATION`) "the call always receives audio," and only accessibility
services or "a privileged app with `CAPTURE_AUDIO_OUTPUT`" may also capture. (The page's
`VOICE_UPLINK`/`VOICE_DOWNLINK`/`TYPE_TELEPHONY` route is **telephony-only** and does *not* apply to
VoIP — do not plan around it for WhatsApp.)

Implementation ground truth — `AudioPolicyService::updateUidStates_l`
(`aosp-mirror/platform_frameworks_av` @ `android-16.0.0_r1`,
`services/audiopolicy/service/AudioPolicyService.cpp`, read via gitiles):

- The client flag that matters is **`canBypassConcurrentPolicy`** (not "canCaptureOutput"). The
  comment block states a client may capture calls if it "has `CAPTURE_AUDIO_OUTPUT` privileged
  permission (temporarily until …)".
- Where that flag is computed (`AudioPolicyInterfaceImpl.cpp`): at `android-16.0.0_r1`/`_r2` it is
  `CAPTURE_AUDIO_OUTPUT`, **replaced** by `BYPASS_CONCURRENT_RECORD_AUDIO_RESTRICTION` when the aconfig
  flag `android.media.audio.concurrent_audio_record_bypass_permission` is on (no r1/r2 release config
  turns it on). From `_r3`/`_r4` and at `android-17.0.0_r1` it is `CAPTURE_AUDIO_OUTPUT` **OR** that
  permission (flag ENABLED in `bp4a`, inherited by `cp1a`/`cp2a`). Shell's manifest (16r1 and 17r1)
  does not request `BYPASS_CONCURRENT_RECORD_AUDIO_RESTRICTION`, so `TODO(b/374751406)` (drop the
  `CAPTURE_AUDIO_OUTPUT` path once system apps migrate) is the concrete "temporarily until" risk, and
  a build pairing r1/r2-era code with the flag on would silence the helper (corrected by verification:
  `services/audiopolicy/service/AudioPolicyInterfaceImpl.cpp` @ android-16.0.0_r1…r4 and
  android-17.0.0_r1; `platform/build/release` aconfig value files).
- Privacy-sensitive gate: `allowSensitiveCapture = !isSensitiveActive || isTopOrLatestSensitive ||
  current->canBypassConcurrentPolicy;` → the bypass flag lets us capture even when a sensitive
  `VOICE_COMMUNICATION` client is active.
- In-call gate: `bool canCaptureCall = recordClient->canBypassConcurrentPolicy;` → same flag lets us
  capture during `MODE_IN_COMMUNICATION`.
- A top-active client is only cleared `&& !topActive->canBypassConcurrentPolicy` — i.e. a bypassing
  client is not cleared.
- Final silence decision: `setAppState_l(current, allowCapture ? … : APP_STATE_IDLE)` →
  `af->setRecordSilenced(client->portId, silenced)` where `silenced = (state == APP_STATE_IDLE)`.

Two hard overrides remain, both **PROVEN** in the same function:

1. **App-op**: `if (!current->hasOp()) { … }` // "Never allow capture if app op is denied" — the
   `RECORD_AUDIO` app-op must be granted to the capturing UID. Shell holds `RECORD_AUDIO` from A11.
2. **Sensor privacy / mic kill-switch**: `if (mSensorPrivacyPolicy->isSensorPrivacyEnabled()) {
   silenceAllRecordings_l(); }` — the OS mic toggle silences everyone regardless of bypass.

**Conclusion (proven vs speculative):**
- *Proven*: a shell-UID `AudioRecord` (CAPTURE_AUDIO_OUTPUT → `canBypassConcurrentPolicy`) is **not**
  silenced by WhatsApp's `VOICE_COMMUNICATION` capture nor by `MODE_IN_COMMUNICATION`, as long as the
  RECORD_AUDIO app-op is on and the mic kill-switch is off.
- *Speculative / verify on device*: the exact audio content you get for the near end. Easiest and most
  robust is a direct mic `AudioRecord` from the helper (source `MIC` or `VOICE_COMMUNICATION`). Using
  `VOICE_COMMUNICATION` also engages platform AEC/NS, which may partially cancel the far party leaking
  through the earpiece — test both `MIC` and `VOICE_COMMUNICATION` and `VOICE_RECOGNITION` on the S25.
- The `"temporarily until …"` comment is a **standing risk**: Google may move this bypass off plain
  `CAPTURE_AUDIO_OUTPUT` in a future release. Track it (gotchas).

There is **no** playback-mix route to the near end: the mic is an *input*, never rendered to an
output, so a players mix cannot see it. The far party is captured by the policy mix (topic 2); the near
party is captured by a concurrent mic `AudioRecord`. These are two separate streams you interleave.

---

## 4. Detecting call start/end for `com.whatsapp` / `com.whatsapp.w4b`

**Audio mode.** `AudioManager.getMode()` and the mode constants come from `AudioSystem`
(`.../android-16.0.0_r1/media/java/android/media/AudioSystem.java`, exact values):
`MODE_NORMAL = 0`, `MODE_RINGTONE = 1`, `MODE_IN_CALL = 2`, **`MODE_IN_COMMUNICATION = 3`**,
`MODE_CALL_SCREENING = 4`, `MODE_CALL_REDIRECT = 5`, `MODE_COMMUNICATION_REDIRECT = 6`
(`MODE_INVALID = -2`, `MODE_CURRENT = -1`). A WhatsApp call drives the device to
**`MODE_IN_COMMUNICATION`** (VoIP), *not* `MODE_IN_CALL` (that is the cellular telephony mode).

**Mode listener.** `AudioManager.OnModeChangedListener { void onModeChanged(int mode); }` with
`public void addOnModeChangedListener(Executor, OnModeChangedListener)` /
`removeOnModeChangedListener(OnModeChangedListener)` (confirmed in `AudioManager.java`; both plain
public, no permission). API level **31 (Android 12)** per developer docs — **not re-verified from the
API txt during research** (medium confidence); before 31, poll `getMode()`.

**Recording/playback callbacks and which client fields are visible.** The "Sharing audio input" page
(opened) documents `AudioManager.AudioRecordingCallback.onRecordingConfigChanged()`,
`getActiveRecordingConfigurations()`, and `AudioRecordingConfiguration.isClientSilenced()`. The
identity fields are privilege-gated:

- `AudioRecordingConfiguration` (`.../media/java/android/media/AudioRecordingConfiguration.java`):
  `getClientUid()` is `@SystemApi @hide @RequiresPermission(MODIFY_AUDIO_ROUTING)` and **throws
  `SecurityException`** without the permission; `getClientPackageName()` returns an **empty string**
  without `MODIFY_AUDIO_ROUTING`. `getClientAudioSessionId()`, `getAudioSource()`,
  `isClientSilenced()`, `getClientEffects()` are plain public.
- `AudioPlaybackConfiguration` (`.../media/java/android/media/AudioPlaybackConfiguration.java`):
  `getClientUid()`, `getClientPid()`, `getPlayerType()` are `@SystemApi @hide`. For a non-privileged
  listener the framework delivers an **anonymized copy** server-side: `clientUid = -1`
  (`PLAYER_UPID_INVALID`), `playerType = PLAYER_TYPE_UNKNOWN`, and attributes stripped to "only usage,
  content type and public flags."

**So:** our shell-UID helper (holding `MODIFY_AUDIO_ROUTING` on A13+) sees the **real client UID and
package** of the active recorder/player — it can confirm the active capturer is `com.whatsapp` /
`com.whatsapp.w4b` and read its `USAGE_VOICE_COMMUNICATION` player. A normal app gets only anonymized
data and would have to infer the call from `MODE_IN_COMMUNICATION` + an active `VOICE_COMMUNICATION`
recording config. Recommended trigger: `MODE_IN_COMMUNICATION` entered **and** an active
`VOICE_COMMUNICATION` playback/record config owned by a WhatsApp UID; call end = mode leaves
`MODE_IN_COMMUNICATION`.

---

## 5. Shizuku-API (on-ramp #1)

Repo `RikkaApps/Shizuku-API`. **License: MIT** — confirmed from `LICENSE` ("MIT License", "Copyright
(c) 2021 RikkaW"). Artifacts: `dev.rikka.shizuku:api` and `dev.rikka.shizuku:provider`; latest version
in the changelog is **13.1.5** (README).

**Provider manifest** (README): component `rikka.shizuku.ShizukuProvider`,
`authorities="${applicationId}.shizuku"`, `android:multiprocess="false"`, `enabled/exported="true"`,
guarded by `android:permission="android.permission.INTERACT_ACROSS_USERS_FULL"` (keeps normal apps
out). Multi-process apps call `ShizukuProvider.enableMultiProcessSupport()`. Since 12.1.0 Sui
auto-initializes (opt out with `ShizukuProvider.disableAutomaticSuiInitialization()` before
`onCreate()`).

**Binder lifecycle / permission flow** (`api/.../rikka/shizuku/Shizuku.java`):
`addBinderReceivedListener(OnBinderReceivedListener[, Handler])` and `…Sticky(…)`,
`addBinderDeadListener`, `addRequestPermissionResultListener(OnRequestPermissionResultListener[,
Handler])`. Calling before binder-alive throws `IllegalStateException`. Request flow:
`isPreV11()` → unsupported; else `checkSelfPermission() == PERMISSION_GRANTED`?; else
`shouldShowRequestPermissionRationale()` means "deny & don't ask again"; else `requestPermission(code)`.

**UserService** (the piece we use to run the capture helper):
`public static void bindUserService(UserServiceArgs args, ServiceConnection conn)` and
`unbindUserService(UserServiceArgs, ServiceConnection, boolean remove)`. `UserServiceArgs` fluent API
(confirmed in source): `UserServiceArgs(ComponentName)`, `.daemon(boolean)`, `.tag(String)`,
`.version(int)`, `.debuggable(boolean)`, `.processNameSuffix(String)`. The service class extends your
AIDL `IYourInterface.Stub`; the framework delivers a **destroy** transaction (code `16777115`;
`16777114` in AIDL ordinal) where you clean up and call `System.exit()`. Service constructor may be
no-arg or `(Context)` (since v13; v13 tries the `Context` ctor first), but that `Context` is limited
(`registerReceiver`/`getContentResolver` may not work). Use `.daemon(true)` to keep it alive across app
unbinds (daemon mode since 12.0.0); `peekUserService()` checks liveness.

**UID the user service runs as** (README, matches ServiceUtilities bypass note): **root (UID 0) if
Shizuku was started as root, or shell (UID 2000) if Shizuku was started via ADB.** `Shizuku.getUid()`
returns 0 or 2000. For EchoLedger's non-root target, Shizuku-via-ADB ⇒ the helper is **UID 2000**, so
it relies on the A14 Shell permission set (topic 1); a rooted user gets UID 0 and bypasses the native
permission gates (topic 2b).

**`newProcess` deprecation**: in current source `newProcess` is **`private`** (effectively removed from
the public API); the README/changelog says to use `UserService` instead and that it "can replace
`newProcess` in all cases." So **do not design around `Shizuku.newProcess`** — use a bound
`UserService`.

---

## 6. Built-in "Wireless debugging" pairing (on-ramp #2) and library licenses

**Protocol (from AOSP `packages/modules/adb`).** mDNS service types
(`.../packages/modules/adb/+/refs/heads/main/adb_mdns.h`): `ADB_MDNS_SERVICE_TYPE = "adb"`,
`ADB_MDNS_TLS_PAIRING_TYPE = "adb-tls-pairing"`, `ADB_MDNS_TLS_CONNECT_TYPE = "adb-tls-connect"` (i.e.
`_adb-tls-pairing._tcp` / `_adb-tls-connect._tcp`). Pairing crypto
(`.../packages/modules/adb/+/refs/heads/main/pairing_auth/pairing_auth.cpp`): a `PairingAuthCtx` wraps
BoringSSL **SPAKE2** (`<openssl/curve25519.h>`, `SPAKE2_CTX_new`, `SPAKE2_generate_msg`,
`SPAKE2_process_msg`, `SPAKE2_MAX_MSG_SIZE`, `SPAKE2_MAX_KEY_SIZE`). Roles/identities are fixed:
client = `spake2_role_alice` "adb pair client" / peer "adb pair server"; server = `spake2_role_bob`
"adb pair server" / peer "adb pair client". The **6-digit pairing code** is the SPAKE2 password (a
non-empty byte vector; the digit→bytes encoding happens in the caller, not this file). The SPAKE2
output `key_material` is fed directly into **AES-128-GCM** (`new Aes128Gcm(key_material, …)`) that
encrypts the subsequent TLS-certificate exchange. The connect phase then uses the device's RSA/TLS key
on `adb-tls-connect`.

**Driving it on-device.** The host flow is `adb pair ipaddr:port` (prompts for the code), one-time:
"the device will remain paired … until you explicitly forget it," and mDNS auto-reconnects on the same
network (`developer.android.com/tools/adb`). The key trick for a phone-only product: run the pairing +
TLS connect against **`127.0.0.1`** (localhost) from *inside* the EchoLedger app, using a JVM adb
library, then `openStream("shell:…")` to `app_process`-launch the helper as UID 2000. No PC needed
after the user enables Wireless debugging and types the 6-digit code once.

**Libraries + licenses (all verified during research):**

| Library | Coordinate | License | Pairing? | Shell? | Notes |
|---|---|---|---|---|---|
| **libadb-android** (MuntashirAkon) | `com.github.MuntashirAkon:libadb-android:3.1.1` (JitPack) | **GPL-3.0-or-later OR Apache-2.0** (dual; `COPYING` SPDX confirmed) | **Yes** — `AdbConnectionManager.pair(host, port, pairingCode)` | Yes — `openStream("shell:")` | TLS via **Conscrypt** that the app supplies (platform Conscrypt via hidden-API bypass, or a bundled one). Its 3.1.1 build depends on `com.github.MuntashirAkon.spake2-java:spake2-android:2.2.1` (**LGPL-3.0**) and `org.bouncycastle:bcprov-jdk15to18:1.81` (corrected by verification: https://raw.githubusercontent.com/MuntashirAkon/libadb-android/3.1.1/libadb/build.gradle) |
| **dadb** (mobile-dev-inc) | `dev.mobile:dadb` (Maven Central) | **Apache-2.0** | **No** — connect-only; uses an existing `~/.android/adbkey` | Yes — `dadb.shell(...)` | Cannot do the one-time 6-digit pairing by itself |
| **Spake2-Java** (Flyfish233) | `com.github.Flyfish233:spake2-java` (JitPack) | **GPL-3.0** (repo badge "Licensed under the GPLv3") | n/a (SPAKE2 core) | n/a | "ADB-focused … compatible with AOSP/BoringSSL pairing-auth". A GPL-3.0 fork that libadb-android does **not** depend on; libadb-android uses MuntashirAkon's own `spake2-java` (`spake2-android`), which is **LGPL-3.0** (corrected by verification: https://github.com/MuntashirAkon/spake2-java) |
| **cryptography-cafe/spake2** | (no coordinate published) | **MIT** | n/a | n/a | "pure-Java SPAKE2"; **not** confirmed BoringSSL/adb-wire-compatible — likely wrong group params |
| **AndroidHiddenApiBypass** (LSPosed) | `org.lsposed.hiddenapibypass:hiddenapibypass` | **Apache-2.0** | n/a | n/a | needed for topic 2c hidden-API access |

**The licensing trap for selling later.** libadb-android itself can be taken under **Apache-2.0**
(great), but its **pairing** depends on **MuntashirAkon `spake2-android` 2.2.1, which is LGPL-3.0** (the
README warns of "an LGPL dependency"), not on the GPL-3.0 Flyfish233 fork. Shipping it in a proprietary
APK brings the LGPL-3.0 conditions for that library (notice, its source, and the relinking/replacement
terms) rather than GPL on the whole app; get a legal review before selling (corrected by verification:
https://raw.githubusercontent.com/MuntashirAkon/libadb-android/3.1.1/libadb/build.gradle ,
https://raw.githubusercontent.com/MuntashirAkon/spake2-java/master/LICENSE). Options, by effort:

1. **Prefer Shizuku (topic 5) for distribution** and treat Wireless-debugging/libadb as a fallback, or
   isolate the LGPL pairing library into a separate, replaceable component. (Cleanest legally, least code.)
2. **Re-implement BoringSSL-compatible SPAKE2 with BouncyCastle** (MIT-style license) — must reproduce
   BoringSSL's fixed M/N generator points, the `"adb pair client"`/`"adb pair server"` identities, the
   message framing, and AES-128-GCM wrapping exactly, then do the `adb-tls-connect` TLS with Conscrypt
   (Apache-2.0). **Effort estimate: medium-high** — on the order of 1–2 engineer-weeks plus real-device
   interop testing against `adb pair`, because SPAKE2 interop is unforgiving (the fixed seeds/points
   and transcript must match BoringSSL bit-for-bit). cryptography-cafe/spake2 (MIT) is **not** a
   shortcut — generic SPAKE2 params won't interop with BoringSSL (**UNVERIFIED** that it's adb-compatible;
   README doesn't claim Ed25519/BoringSSL).
3. **Accept the LGPL-3.0 terms for the pairing library** if the business model allows (corrected by verification: see above).

---

## 7. Launching the helper, keeping it alive, re-arming after reboot

**`app_process` launch.** Standard shell invocation:
`CLASSPATH=<path-to-apk> app_process /system/bin --nice-name=<name> <fully.qualified.MainClass> [args]`.
The `CLASSPATH` is the EchoLedger APK (so the helper's classes ship inside the normal app). Get the APK
path at runtime from the app via `context.getApplicationInfo().sourceDir` (or `getPackageCodePath()`),
pass it into the shell command. `main()` should `Looper.prepareMainLooper()`, call
`HiddenApiBypass.addHiddenApiExemptions("L")`, build the `Context`/`AudioManager` (topic 2c), register
the policy + mic `AudioRecord`, then publish its binder (topic 8) and `Looper.loop()`.

**Keeping it alive.** Under Shizuku, bind as a **daemon `UserService`** (`.daemon(true)`) so it
survives the app being backgrounded/unbound; implement the destroy transaction to exit cleanly. Under
the libadb path, the `shell:` stream's process lives as long as the adb connection; keep the connection
open from a foreground service, or relaunch on demand.

**Re-arming after reboot (the fragile part).**
- Wireless debugging survives reboot as a *pairing*, but the live `adb-tls-connect` **port is
  dynamically assigned** and the daemon/socket must be re-established; mDNS rediscovers it
  (`developer.android.com/tools/adb` shows dynamic ports and mDNS discovery; it does **not** explicitly
  state the port changes across reboots — that is widely observed but **UNVERIFIED** from the doc).
  So after reboot the app must re-discover via mDNS (`_adb-tls-connect._tcp`) and reconnect; **no new
  6-digit code is needed** (pairing persists).
- The shell-UID helper process **dies on reboot** and must be relaunched.
- The EchoLedger app **cannot** auto-start a `microphone` foreground service from `BOOT_COMPLETED`
  (Android 14+ restriction, topic 10) — but re-establishing the adb connection and relaunching the
  helper does **not** require a mic FGS, so re-arming can be driven from a user tap (notification
  action / widget — the reliable background-start exemptions the spec already calls for) or a
  non-mic worker. Document the "keep Wireless debugging on; tap to re-arm after reboot" flow in-app.
- Caveat: some banking/DRM apps refuse to run while USB/Wireless debugging
  is enabled.

---

## 8. Binder hand-off (helper → app) with a UID check

The helper publishes an AIDL binder; the app fetches it through its **own exported `ContentProvider`**
and verifies the caller.

- Provider side (`.../android-16.0.0_r1/core/java/android/content/ContentProvider.java`): override
  `public Bundle call(String method, String arg, Bundle extras)`; the 4-arg
  `call(String authority, String method, String arg, Bundle extras)` (authority-aware overload;
  delegates to the 3-arg; **API 29** per developer docs — the source javadoc carries no `@since`, so
  medium confidence) is what cross-authority callers hit.
- Pass the binder in the `Bundle`: `Bundle.putBinder(String key, IBinder value)` /
  `IBinder getBinder(String key)` (`.../core/java/android/os/Bundle.java`; both plain public, no
  `@hide`; introduced **API 18** per developer docs, not in-source during research).
- **UID verification (the security requirement):** inside `call()`, use
  `Binder.getCallingUidOrThrow()` (`.../core/java/android/os/Binder.java`) — it returns the caller's
  Linux UID and **throws `IllegalStateException` when not inside a transaction**, unless the calling
  identity was explicitly set with `clearCallingIdentity()` (then it returns that identity, i.e. your own
  UID, without throwing; so never check after `clearCallingIdentity()`) (corrected by verification:
  https://android.googlesource.com/platform/frameworks/base/+/refs/tags/android-16.0.0_r1/core/java/android/os/Binder.java) (unlike
  `getCallingUid()`, which silently returns the *process's own* UID outside a transaction — a footgun
  if you ever call the handler locally). Accept only **2000 (shell)**, **0 (root)**, or the app's own
  UID; reject everything else. `getCallingUid()` javadoc: "Return the Linux UID assigned to the process
  that sent you the current transaction"; it is `@CriticalNative`.
- Direction matters: the *helper* is the binder **server**. The simplest hand-off is the reverse —
  the app puts a "callback" binder into the shell command / UserService args, or the helper calls
  **into** the app's provider (`context.getContentResolver().call(authority, "register", null,
  bundleWithBinder)`) and the app validates `getCallingUidOrThrow() == 2000/0`. Either way the UID
  check lives on whichever side receives the binder.
- **Signature-permission alternative** (defense in depth): guard the provider with a custom
  `android:protectionLevel="signature"` permission so only same-signed components interact — but note a
  shell/root caller is a *different* UID, so you still need the explicit UID allowlist as the primary
  gate; the signature permission mainly blocks other third-party apps from poking the provider.

---

## 9. Two-channel Opus on device (L = mic, R = far party)

- **Encoder availability**: the Opus **encoder** is available from **Android 10 (API 29)+**
  (`developer.android.com/media/platform/supported-formats`: Opus Encoder "Android 10+", Decoder
  "Android 5.0+"). MIME string `MediaFormat.MIMETYPE_AUDIO_OPUS = "audio/opus"`
  (`.../media/java/android/media/MediaFormat.java`). Configure via `MediaCodec` with
  `MediaFormat.createAudioFormat("audio/opus", sampleRate, 2)`, plus
  `KEY_SAMPLE_RATE = "sample-rate"`, `KEY_CHANNEL_COUNT = "channel-count"`, `KEY_BIT_RATE = "bitrate"`.
  Opus natively runs at 48 kHz internally; pick 48 kHz stereo (or 16 kHz if the STT provider prefers it
  and to save bandwidth) and ~24–32 kbps/channel. (Exact encoder-supported rate/channel/bitrate ranges
  are device-codec-dependent — query `MediaCodecInfo.getCapabilitiesForType("audio/opus")` on the S25;
  **not enumerable from docs**.)
- **Container**: `MediaMuxer.OutputFormat.MUXER_OUTPUT_OGG = 4`
  (`.../media/java/android/media/MediaMuxer.java`). The `addTrack` codec-support table ties Opus to the
  **OGG** output. OGG muxer output is available from **API 29** (the in-file table shows an ambiguous
  "26" for the Opus/OGG cell; the `MUXER_OUTPUT_OGG` constant itself is API 29 — **treat 29 as the
  floor**, which is moot since the feature needs A14 anyway). WebM (`MUXER_OUTPUT_WEBM = 1`) carries
  Vorbis, not Opus — use OGG.
- **Interleaving**: capture two mono `AudioRecord`/`AudioRecord`-sink streams at the same sample rate,
  timestamp-align them, and write interleaved 16-bit PCM frames as `[L0,R0,L1,R1,…]` with **L = near-end
  mic**, **R = far-party policy sink**, feed that stereo PCM to the Opus encoder, mux into OGG. This
  gives the STT provider free speaker separation ("Me" vs "Client") with no diarization — matching the
  EchoLedger two-channel design. Handle clock drift between the two sources (resample/pad to keep the
  channels length-aligned; the spec's QA already requires ±50 ms alignment).

---

## 10. Android 14 / 15 / 16 changes that affect this design

- **Android 13 (API 33)**: Shell gains `MODIFY_AUDIO_ROUTING` (dynamic-mix registration becomes
  possible for shell). `POST_NOTIFICATIONS` becomes a runtime permission (affects the app's
  foreground-service / save-after-call notifications).
- **Android 14 (API 34)** — the pivotal release:
  - Shell gains `CAPTURE_MEDIA_OUTPUT` + `CAPTURE_VOICE_COMMUNICATION_OUTPUT`
    (+ `MODIFY_AUDIO_SETTINGS_PRIVILEGED`) → **far-party VoIP capture via policy mix becomes possible**
    (topic 1). **This is why A14 is the practical minimum.**
  - Foreground-service **types are mandatory**
    (`developer.android.com/about/versions/14/changes/fgs-types-required`): the app's own mic service
    needs `android:foregroundServiceType="microphone"` + `FOREGROUND_SERVICE_MICROPHONE` manifest
    permission + granted `RECORD_AUDIO`, started with `FOREGROUND_SERVICE_TYPE_MICROPHONE`. Missing type
    → `MissingForegroundServiceTypeException`; missing permission → `SecurityException`. Permissions
    must be granted **before** `startForeground()`.
  - A `microphone` FGS **cannot be started from the background** (while-in-use restriction) nor from a
    `BOOT_COMPLETED` receiver (threshold stated as API 34+).
- **Android 15 (API 35)**:
  - More FGS types barred from `BOOT_COMPLETED` (camera, dataSync, mediaPlayback, mediaProjection,
    phoneCall; microphone already barred since API 34) —
    `developer.android.com/about/versions/15/changes/foreground-service-types`.
  - **MediaProjection** gets a persistent status-bar chip and auto-stops on screen-lock
    (`developer.android.com/about/versions/15/behavior-changes-all`). Irrelevant to our policy-mix path
    but another reason **not** to try a MediaProjection-based capture on Android.
- **Android 16 (API 36)**: current baseline for all reads above; the `canBypassConcurrentPolicy`
  bypass (topic 3) and the full Shell permission set are present. The `"temporarily until …"` comment on
  the CAPTURE_AUDIO_OUTPUT→bypass mapping signals this may change in a later release — watch it.
- **Future**: ADB "Wi-Fi 2.0" requires **Android 17+** (`adb mdns` `mdns_service_version: "2.0"`),
  per `developer.android.com/tools/adb`. Not needed now; note for forward-compat.

---

## Library license summary

| Component | License | Confidence |
|---|---|---|
| AOSP platform code (reference only, clean-room) | Apache-2.0 | high |
| `dev.rikka.shizuku:api` / `:provider` | **MIT** (LICENSE opened) | high |
| `com.github.MuntashirAkon:libadb-android` | **GPL-3.0-or-later OR Apache-2.0** (COPYING opened) | high |
| `com.github.MuntashirAkon.spake2-java:spake2-android` 2.2.1 (libadb pairing dep) | **LGPL-3.0** (LICENSE opened) (corrected by verification: https://raw.githubusercontent.com/MuntashirAkon/spake2-java/master/LICENSE) | high |
| `com.github.Flyfish233:spake2-java` (GPL fork, **not** a libadb dep) | **GPL-3.0** (repo) | high |
| `cryptography-cafe/spake2` | **MIT** (repo) | high (but adb-incompatible — see topic 6) |
| `dev.mobile:dadb` | **Apache-2.0** | high |
| `org.lsposed.hiddenapibypass:hiddenapibypass` | **Apache-2.0** | high |
| Conscrypt (TLS for libadb, supplied by the app; not in libadb's `build.gradle`) (corrected by verification: libadb-android 3.1.1 `libadb/build.gradle` + README) | Apache-2.0 | medium (not opened during research) |
| BouncyCastle (if DIY SPAKE2) | MIT-style (Bouncy Castle license) | medium (not opened during research) |

---

## Recommendations for EchoLedger

1. **Set the floor at Android 14 (API 34) for call capture.** The spec says min SDK 29; keep 29 for the
   app shell, but gate the WhatsApp far-party feature to API 34+ (Shell lacks
   `CAPTURE_VOICE_COMMUNICATION_OUTPUT` before A14). The S25 Ultra target is A15/A16, so this is fine;
   surface a clear "requires Android 14+" capability check.
2. **Capture topology**: far party = players `AudioMix` matching `USAGE_VOICE_COMMUNICATION` with
   `ROUTE_FLAG_LOOP_BACK_RENDER` (user keeps hearing the call) + hidden `voiceCommunicationCaptureAllowed`
   + `allowPrivilegedMediaPlaybackCapture`, via `registerAudioPolicy`/`createAudioRecordSink`; near end
   = a concurrent mic `AudioRecord` in the same helper (bypasses silencing via CAPTURE_AUDIO_OUTPUT).
   Interleave L=mic/R=far, Opus→OGG.
3. **Prefer Shizuku (MIT) as the primary on-ramp**; offer Wireless-debugging/libadb as the
   no-Shizuku fallback. This keeps the default path MIT-clean and avoids the LGPL-3.0 SPAKE2 dependency
   for most users (corrected by verification: libadb-android 3.1.1 `libadb/build.gradle`).
4. **If you ship the libadb pairing path, quarantine the LGPL library**: isolate SPAKE2 pairing behind an
   interface and either (a) use Shizuku instead, (b) re-implement BoringSSL-compatible SPAKE2 on
   BouncyCastle (budget ~1–2 weeks + device interop), or (c) accept the LGPL-3.0 terms for that library
   (corrected by verification: libadb-android 3.1.1 depends on LGPL-3.0 `spake2-android`, not a GPL one). Do **not**
   assume cryptography-cafe/spake2 interoperates with `adb pair` — it almost certainly does not.
5. **Run the helper via `app_process` with `HiddenApiBypass` (Apache-2.0)** called first in `main()`;
   don't rely on an undocumented "app_process is exempt" assumption. Build the `Context` via
   `ActivityThread.systemMain().getSystemContext()`.
6. **Binder hand-off must `getCallingUidOrThrow()` and allowlist {2000, 0, ownUid}**, backed by a
   signature-level permission on the provider. Treat `getCallingUid()`'s out-of-transaction fallback as
   a bug source.
7. **Re-arm flow**: pairing is one-time; after reboot, rediscover `_adb-tls-connect._tcp` via mDNS,
   reconnect, relaunch helper — triggered from a notification action or Glance widget (never a
   `BOOT_COMPLETED` mic FGS). Document "keep Wireless debugging on."
8. **Identity-confirm the active call**: in the helper (which sees real client UID/package via
   `MODIFY_AUDIO_ROUTING`), start capture only when `MODE_IN_COMMUNICATION` + an active
   `USAGE_VOICE_COMMUNICATION` stream owned by `com.whatsapp`/`com.whatsapp.w4b`; stop when the mode
   leaves `MODE_IN_COMMUNICATION`. Fire the "Save this recording?" prompt only *after* stop.
9. **Consent/legal (do not skip)**: capturing the far party is two-party-consent-sensitive in many
   jurisdictions. Keep the on-screen recording indicator and an explicit in-app consent setting the
   product requirements already requires; store app-private + Keystore-encrypted; never auto-upload without the
   user's keep decision.

## Gotchas

- **Public `AudioPlaybackCapture` is a trap** — it silently cannot capture `USAGE_VOICE_COMMUNICATION`;
  do not waste time on it for WhatsApp.
- **`ROUTE_FLAG_LOOP_BACK` (default) mutes the call for the user** — you must use
  `ROUTE_FLAG_LOOP_BACK_RENDER`. Using the default would make the human unable to hear the call.
- **The CAPTURE_AUDIO_OUTPUT → `canBypassConcurrentPolicy` mapping is marked "temporarily until …"** in
  AOSP — a future Android could require a different permission for concurrent capture. That
  permission already exists: `BYPASS_CONCURRENT_RECORD_AUDIO_RESTRICTION`, which Shell does not hold
  (see topic 3; corrected by verification: AudioPolicyInterfaceImpl.cpp @ android-17.0.0_r1). Add a
  device self-test that verifies the near-end channel is non-silent.
- **Mic hardware kill-switch / sensor privacy silences everyone**, bypass or not (`silenceAllRecordings_l`).
  Detect `AudioRecordingConfiguration.isClientSilenced()` and warn the user.
- **Shell UID ≠ root**: UID 2000 must hold the literal permissions (A14 set); only AID_AUDIOSERVER/AID_ROOT
  bypass the native checks (corrected by verification: ServiceUtilities.h @ android-16.0.0_r1). A rooted Shizuku helper (UID 0) works on older Android but that's not the
  default target.
- **Hidden-API enforcement may bite the helper** (classes load from the APK). Call `HiddenApiBypass`
  before touching any `@SystemApi`/`@hide` member.
- **LGPL-3.0 SPAKE2 (`spake2-android`) in the pairing path** arrives as a transitive JitPack
  dependency and carries LGPL obligations — audit the dependency tree (corrected by verification:
  https://raw.githubusercontent.com/MuntashirAkon/libadb-android/3.1.1/libadb/build.gradle).
- **`Binder.getCallingUid()` returns your own UID outside a transaction** — use `getCallingUidOrThrow()`.
- **Reboot kills the helper and the adb socket**; the 6-digit code is *not* needed again, but the
  connection and helper must be re-established, and you can't do it from a boot-time mic FGS.
- **Android 15 MediaProjection chip/auto-stop** — another reason the Android lane uses the policy mix,
  not MediaProjection.
- **WhatsApp AEC**: platform echo-cancel on the mic path may already remove some far-party bleed from
  the near channel — good for channel separation, but verify the near channel isn't over-processed.

## Load-bearing claims

| # | Claim | Source | Confidence |
|---|---|---|---|
| 1 | `com.android.shell` (UID 2000) holds `CAPTURE_VOICE_COMMUNICATION_OUTPUT`, `CAPTURE_MEDIA_OUTPUT`, `MODIFY_AUDIO_ROUTING`, `CAPTURE_AUDIO_OUTPUT`, `RECORD_AUDIO` from **Android 14**; A13 has routing but not voice-comm; A11–12 have record/output only; A10 none. | AOSP `packages/Shell/AndroidManifest.xml` @ tags android-10…16 (googlesource) | high |
| 2 | Public `AudioPlaybackCaptureConfiguration` can capture only `USAGE_UNKNOWN`/`USAGE_MEDIA`/`USAGE_GAME`; "all other usages CAN NOT be captured" → no VoIP. | AOSP `media/.../AudioPlaybackCaptureConfiguration.java` @ android-16.0.0_r1 | high |
| 3 | `AudioMix` `ROUTE_FLAG_LOOP_BACK_RENDER = 0x3` plays the audio unaffected *and* exposes a capture copy; `ROUTE_FLAG_LOOP_BACK = 0x2` does not route to the device (user stops hearing it). | AOSP `media/.../audiopolicy/AudioMix.java` @ android-16.0.0_r1 | high |
| 4 | Capturing `USAGE_VOICE_COMMUNICATION` output is gated on `CAPTURE_VOICE_COMMUNICATION_OUTPUT` specifically (not CAPTURE_AUDIO_OUTPUT); registering a policy needs `MODIFY_AUDIO_ROUTING`; AID_AUDIOSERVER/AID_ROOT bypass (corrected by verification: ServiceUtilities.h @ android-16.0.0_r1), UID 2000 does not. | AOSP `frameworks/av/media/utils/ServiceUtilities.cpp` @ android-16.0.0_r1 | high |
| 5 | `AudioManager.registerAudioPolicy(AudioPolicy)` is `@SystemApi @hide @RequiresPermission(MODIFY_AUDIO_ROUTING)`; `AudioPolicy.createAudioRecordSink(AudioMix)` returns the loopback `AudioRecord`. | AOSP `media/.../AudioManager.java`, `audiopolicy/AudioPolicy.java` @ android-16.0.0_r1 | high |
| 6 | A recorder with `canBypassConcurrentPolicy` (from CAPTURE_AUDIO_OUTPUT) is **not** silenced by a privacy-sensitive `VOICE_COMMUNICATION` capture or during `MODE_IN_COMMUNICATION`; gated only by the RECORD_AUDIO app-op and the sensor-privacy kill-switch. | AOSP `frameworks/av/.../AudioPolicyService.cpp` `updateUidStates_l` @ android-16.0.0_r1 | high |
| 7 | `MODE_IN_COMMUNICATION = 3` (VoIP); privileged callers (`MODIFY_AUDIO_ROUTING`) see real recorder client UID/package, others get empty/`-1` anonymized. | AOSP `media/.../AudioSystem.java`, `AudioRecordingConfiguration.java`, `AudioPlaybackConfiguration.java` @ android-16.0.0_r1 | high |
| 8 | Shizuku-API is **MIT**; UserService runs as UID 0 (root) or **2000 (ADB)**; bind via `Shizuku.bindUserService(UserServiceArgs(...).daemon()..., conn)`; `newProcess` is now private/deprecated. | `RikkaApps/Shizuku-API` `LICENSE`, `README.md`, `api/.../Shizuku.java` | high |
| 9 | Wireless-debugging pairing = mDNS `_adb-tls-pairing._tcp`/`_adb-tls-connect._tcp` + BoringSSL **SPAKE2** (6-digit code as password, roles "adb pair client/server") → AES-128-GCM; one-time, persists across reboot. | AOSP `packages/modules/adb/adb_mdns.h`, `pairing_auth/pairing_auth.cpp`; `developer.android.com/tools/adb` | high |
| 10 | **libadb-android is dual GPL-3.0-or-later/Apache-2.0** (so Apache-usable) and does pairing+shell, but its pairing pulls **MuntashirAkon `spake2-android` 2.2.1 (LGPL-3.0)**, not the GPL-3.0 Flyfish233 fork (corrected by verification: libadb-android 3.1.1 `libadb/build.gradle`); dadb (Apache-2.0) is connect-only (no pairing). | `MuntashirAkon/libadb-android` COPYING+README; `Flyfish233/spake2-java` repo; `mobile-dev-inc/dadb` repo | high |
| 11 | Opus **encoder** = Android 10+ (API 29); `MIMETYPE_AUDIO_OPUS="audio/opus"`; `MediaMuxer.MUXER_OUTPUT_OGG = 4` carries Opus. | `developer.android.com/media/platform/supported-formats`; AOSP `MediaFormat.java`, `MediaMuxer.java` @ android-16.0.0_r1 | high |
| 12 | Android 14 makes FGS types mandatory: mic FGS needs `FOREGROUND_SERVICE_MICROPHONE`+`RECORD_AUDIO`+type `microphone`, cannot start from background/`BOOT_COMPLETED`. | `developer.android.com/about/versions/14/changes/fgs-types-required`; `.../versions/15/changes/foreground-service-types` | high |

## Open questions (need the S25 Ultra / real device)

1. **Near-end audio quality / source choice.** Proven that a CAPTURE_AUDIO_OUTPUT mic recorder isn't
   silenced during a WhatsApp call; **not** proven which source (`MIC` vs `VOICE_COMMUNICATION` vs
   `VOICE_RECOGNITION`) yields the cleanest near-end with least far-party bleed. Test all three.
2. **Does the far-party policy mix actually yield WhatsApp's remote audio on the S25?** The permission
   path is proven; the *routing* (that WhatsApp's `USAGE_VOICE_COMMUNICATION` track is visible to a
   players mix with LOOP_BACK_RENDER, through Samsung's audio HAL) needs device confirmation. OEM audio
   HALs sometimes route voice-comm through paths a dynamic mix can't see.
3. **Exact hidden Builder calls** to set `voiceCommunicationCaptureAllowed(true)` /
   `allowPrivilegedMediaPlaybackCapture()` on `AudioMixingRule.Builder` (these are `@hide`, reached via
   reflection/HiddenApiBypass) — confirm the method set on A14/15/16 at runtime.
4. **`getSystemContext()` exact signature/behavior** from a shell-UID `app_process` (declaration not
   quoted during research) — confirm it returns a `Context` usable for `getSystemService(AUDIO_SERVICE)`
   from UID 2000, or fall back to the `IAudioService`/`ServiceManager` path.
5. **`addOnModeChangedListener` API level (believed 31)** and **`ContentProvider.call` 4-arg @since
   (believed 29)** — not re-verified from the API txt during research.
6. **Does the `canBypassConcurrentPolicy` "temporarily until …" change** land in A16 QPR / A17? Monitor
   AOSP. (Verification 2026-10-24: at android-17.0.0_r1 `CAPTURE_AUDIO_OUTPUT` still grants it, OR'd
   with `BYPASS_CONCURRENT_RECORD_AUDIO_RESTRICTION`; see topic 3.)
7. **Samsung One UI background-kill / Wireless-debugging port behavior** across reboot — confirm mDNS
   rediscovery works without re-entering the 6-digit code, and measure battery of the kept-alive
   connection.
8. **Whether a bare `app_process` process is in fact subject to hidden-API enforcement** on A14–16
   (docs don't say) — determines whether HiddenApiBypass is mandatory or belt-and-suspenders.
