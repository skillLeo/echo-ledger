# Launch: store accounts and submissions

For Hassam · company: SkillLeo SMC Pvt Ltd (Pakistan) · facts checked on **2026-11-16** · decision D6: the store
accounts are organization accounts of the company that owns the app, and Hassam creates them.

How to read this page:

- `<Name>` is the product name once it is decided and `<final-domain>` its domain. Until then the server is
  `https://<server>` (the production address is in the deploy notes, never in the repository) and the company website is `https://skillleo.com`.
- Every answer marked **draft — Hassam confirms** was prepared from the code. Hassam checks it and submits it.
- Fees, deadlines and limits change. Each one carries the date it was checked and a link to the official page.
- None of the accounts below depend on the product name: they are in the company's name and use `skillleo.com`. The
  name is needed before the first app is created in each store.

---

## Checklist (most urgent first)

| # | Action | Hassam's time | Then wait |
|---|---|---|---|
| 1 | **D-U-N-S number.** Create the company Apple Account (a work email on skillleo.com, your legal first and last name, two-factor sign-in on). Sign in at [Apple's D-U-N-S Lookup](https://developer.apple.com/enroll/duns-lookup/), look the company up, and if it is not listed submit it to D&B. Type the legal name exactly as printed on the SECP Certificate of Incorporation (section 1). | 30 min | Up to 5 business days for D&B, then up to 2 business days until Apple has it |
| 2 | **Documents.** Scan in colour: the SECP Certificate of Incorporation, a recent SECP registry extract, the FBR taxpayer registration (NTN) certificate, and your CNIC or passport. | 30 min | — |
| 3 | **Website proof for Google.** In [Google Search Console](https://search.google.com/search-console), add `skillleo.com` as a property and verify it (a TXT record in the Hostinger DNS), signed in with the Google account that will own the Play Console. | 15 min | Minutes to a few hours for DNS |
| 4 | **Product name.** Decide the name (the name check notes are kept outside the repository). If you want the official Pakistan trademark search first, file IPO-Pakistan Form TM-55 now, because its report takes about 15 working days. | TM-55: 1–2 h | TM-55: about 15 working days |
| 5 | **Google Play Console** as an organization, after the D-U-N-S arrives (section 2.2). US$25 once. | 45–60 min | Google's review (no time published) |
| 6 | **Apple Developer Program** as an organization (section 3.2). US$99 a year. | 30 min | Apple's verification email (no time published) |
| 7 | **Microsoft Store** company account (section 4.2). Free. | 20–30 min | Automatic, or 2–5 business days if reviewed by hand |
| 8 | **Review account** on the server (section 5.6). | 30 min | — |
| 9 | **Two Play demo videos** (section 2.7). | 30 min | — |
| 10 | **Play App content forms**, from the drafts in section 2.9, once the Play app exists. | 60–90 min | Reviewed with the first release |
| 11 | **Website APK key** for Android developer verification, in Play Console (section 2.5). The team hands you the fingerprint and the proof APK. | 20 min | An email when registered |
| 12 | **Mac signing.** Make the Developer ID Application certificate and add the five `APPLE_*` secrets in GitHub (section 3.3). | 20 min | — |
| 13 | **After the name is decided:** reserve it in Partner Center, create `contact@<final-domain>`, approve the store texts and screenshots (section 5). | 30 min | — |

---

## 1. D-U-N-S number

**What it is.** "The D‑U‑N‑S Number is a unique nine-digit number that identifies business entities on a
location-specific basis", assigned by Dun & Bradstreet (D&B) ([Apple: D-U-N-S](https://developer.apple.com/support/D-U-N-S/)).
All three stores use it to check the company:

- Google Play: "To create a developer account for an organization, you must provide a D-U-N-S number"
  ([Play Console Help: Choose a developer account type](https://support.google.com/googleplay/android-developer/answer/13634885)).
- Apple: "Your organization (excluding government entities) must have a D-U-N-S Number"
  ([Apple: Enrollment](https://developer.apple.com/programs/enroll/)).
- Microsoft: a D-U-N-S number is the recommended way to verify a company account; without one the account goes to
  manual review ([Microsoft Learn: Open a developer account](https://learn.microsoft.com/en-us/windows/apps/publish/partner-center/open-a-developer-account)).
- One number serves several accounts: "Multiple developer accounts can be created using one DUNS number"
  ([Play Console Help: Required information](https://support.google.com/googleplay/android-developer/answer/13628312)).

**Cost: free.** "There is no cost to request and receive a D‑U‑N‑S Number"
([D&B: Get a D-U-N-S](https://www.dnb.com/en-us/smb/duns/get-a-duns.html)). Google warns that applying through D&B "is
a completely free process" and that third-party services may try to charge fees
([Play Console Help](https://support.google.com/googleplay/android-developer/answer/13634885)).

### How to request it for a Pakistani company

**Route A: Apple's D-U-N-S Lookup (the quickest published timing).**

1. Go to [developer.apple.com/enroll/duns-lookup](https://developer.apple.com/enroll/duns-lookup/) and sign in with the
   company Apple Account.
2. Enter the "Legal entity name", "Headquarters address", "Mailing address" and "Your work contact information"
   ([Apple: D-U-N-S](https://developer.apple.com/support/D-U-N-S/)).
3. If the company is listed, write the number down. If not, submit the details to D&B from the same page.
4. "A D&B representative may contact you directly for more information (such as your business type or number of
   employees). Please have your business registration documents ready." (same page)
5. Timing (2026-11-16): "allow up to 5 business days to receive your number from D&B", then "up to 2 business days for
   Apple to receive your information from D&B". If it takes longer than two weeks, email D&B at
   [support.dnb.com/?CUST=APPLEDEV](https://support.dnb.com/?CUST=APPLEDEV) (same page).

**Route B: D&B's own form.** [dnb.com → Get a D-U-N-S](https://www.dnb.com/en-us/smb/duns/get-a-duns.html) → "I have an
International-based business". "In most cases, you should receive your number in no more than 30 business days"; D&B
also sells an optional expedited service (eight business days) (same page, 2026-11-16). Google's estimate is "up to
30 days" ([Play Console Help](https://support.google.com/googleplay/android-developer/answer/13628312)) and Android's
"up to 28 days" ([Android developer verification FAQ](https://developer.android.com/developer-verification/guides/faq)).

Either route gives the company its D&B number, which is then used for Google, Apple and Microsoft.

### What to have ready

- **The legal name, exactly as printed on the SECP Certificate of Incorporation.** Single-member companies are usually
  printed as "… (SMC-Private) Limited"; use the printed spelling everywhere (D&B, the Google payments profile, Apple,
  Microsoft). Google: the payments profile's legal name and address "must exactly match the details in your Dun &
  Bradstreet profile" ([Play Console Help](https://support.google.com/googleplay/android-developer/answer/13628312)).
- The registered office address and the mailing address, written the same way you will write them at Google.
- A company phone number that someone answers (D&B may call).
- The details D&B asks for: owner or CEO name, legal structure, year founded, primary industry, number of employees
  ([D&B](https://www.dnb.com/en-us/smb/duns/get-a-duns.html)).
- The website `https://skillleo.com` (live on 2026-11-16) and a work email on `skillleo.com`.
- The documents from checklist step 2.

**One thing to watch.** Apple accepts only legal entities ("We do not accept DBAs, fictitious business names, trade
names, or branches") and says that a business listed by D&B as a sole proprietorship or single-person business should
enroll as an individual, or send D&B its registration documents if it is really a legal entity
([Apple: D-U-N-S](https://developer.apple.com/support/D-U-N-S/)). A single-member company is a company: SECP: "A company
is a body corporate registered under the Companies Act 2017, and has separate legal status" and "If only one member
forms a private company, it is called a single member company"
([SECP: FAQs on incorporation](https://www.secp.gov.pk/wp-content/uploads/2025/09/FAQs-Incorporaiton.pdf)). When D&B
asks, make sure the record says private limited company, not sole proprietorship.

---

## 2. Google Play Console (organization, US$25)

### 2.1 What you need before starting

| Item | Detail | Source |
|---|---|---|
| Age | "You must be at least 18 years of age to sign up for a Play Console account." | [Create and set up your account](https://support.google.com/googleplay/android-developer/answer/6112435) |
| Fee (2026-11-16) | US$25, one time. Mastercard, Visa, American Express, Visa Electron (outside the US). "Google Play does not accept prepaid cards." The fee is not refunded if the identity information is judged invalid. | same |
| D-U-N-S | Required; "Google does not grant exemptions or extensions for accounts that fail to provide a D-U-N-S number." | [Required information](https://support.google.com/googleplay/android-developer/answer/13628312) |
| Payments profile | Organization type; it supplies the D-U-N-S number, organization name and address, which must match D&B exactly. | same |
| Website | Organizations must verify their website with Google Search Console (since February 2024 for new organization accounts). It comes before phone verification. | [developer.android.com: Android developer verification](https://developer.android.com/developer-verification/guides) · [Verify your developer identity information](https://support.google.com/googleplay/android-developer/answer/10841920) |
| Contacts | Developer name (public), organization phone (used by Google to verify, "does not display it to users"), website, contact name, contact email and phone (internal), developer email and phone (public), trader status (shown where required, such as the EEA and UK). | [Required information](https://support.google.com/googleplay/android-developer/answer/13628312) |
| Developer email | "The developer email address for an organization should not be a generic or personal email address." Use an address on the company's domain. Google may remove apps if the developer email or phone stops working. | same |
| Documents, Pakistan | Organization: "Certificate of incorporation or registration", "Extract from SECP registry" or "Taxpayer registration certificate". Authorized representative (CEO or director): "Passport", "Identification card", "Driving license" or "Permanent residence card". Everything must match the payments profile exactly; photos in colour, clear, not photocopies. | [Required documents by country: Pakistan](https://support.google.com/googleplay/android-developer/answer/15633622?co=GENIE.CountryCode%3DPK) |

### 2.2 Create the account

1. Sign in at [play.google.com/console/signup](https://play.google.com/console/signup) with the Google account that will
   own the developer account. Use the same account in Search Console (checklist step 3).
2. Choose an organization account: "Choose an organization account if you're creating an account for an organization
   or business" ([Choose a developer account type](https://support.google.com/googleplay/android-developer/answer/13634885)).
   "Currently, we do not support changing an account from organization to individual"
   ([Keeping your account information up to date](https://support.google.com/googleplay/android-developer/answer/13634888)).
3. Create or link the organization's payments profile with the D-U-N-S number and the legal name and address from the
   D&B record ([Required information](https://support.google.com/googleplay/android-developer/answer/13628312)).
4. Fill in the developer name, organization phone, website (`https://skillleo.com`), contact person, developer email
   and phone, and trader status (same page).
5. Accept the Google Play Developer Distribution Agreement and pay the US$25
   ([Create and set up your account](https://support.google.com/googleplay/android-developer/answer/6112435)).
6. Identity verification: upload one organization document and your photo ID (section 2.1).
7. Website verification: send the request for `skillleo.com`. Google checks organization websites through Search
   Console ([Android developer verification guides](https://developer.android.com/developer-verification/guides)). The
   Android Developer Console Help describes the flow: when the same Google account owns the property in Search Console,
   "the request will be automatically approved"; otherwise the Search Console owner gets an email to approve it
   ([Verify your Android developer identity information](https://support.google.com/android-developer-console/answer/16641416)).
8. Verify the developer phone and the contact phone (after identity and website verification) and the two email
   addresses with a 6-digit code ([Verify your developer identity information](https://support.google.com/googleplay/android-developer/answer/10841920)).
   "You must verify your developer account before you can submit apps for consideration on Google Play." (same page)

### 2.3 What Google Play shows publicly

For an organization, Google Play displays the "legal name, D-U-N-S number, legal address, developer email address,
and developer phone number", the developer name and, where required, the trader status. The organization phone and
the contact person are not shown ([Required information](https://support.google.com/googleplay/android-developer/answer/13628312)).
Pick a developer phone number you are happy to publish.

### 2.4 Testing tracks: new personal accounts and organization accounts

- **New personal accounts** (created after November 13, 2023) must run a closed test with "a minimum of 12 testers who
  have been opted in continuously for at least 14 days", then "Apply for production"; that review "usually takes seven
  days or less" ([App testing requirements](https://support.google.com/googleplay/android-developer/answer/14151465)).
  They must also verify access to an Android device with the Play Console app
  ([Create and set up your account](https://support.google.com/googleplay/android-developer/answer/6112435)).
- **Organization accounts:** the testing and device-verification step is listed for "personal accounts only" (same
  page), and the 12-tester article covers only personal accounts. Our account can release to production after the
  normal app review.
- **Tracks we will use:** internal testing ("up to 100 testers per app"; releases reach testers "within minutes"; it
  "might not be subject to standard Play policy or security reviews"; apps only on internal testing are exempt from the
  Data safety section), then production. Closed and open tracks are available too
  ([Set up an open, closed or internal test](https://support.google.com/googleplay/android-developer/answer/9845334)).

### 2.5 Android developer verification (the Play build and the website APK)

**What Google requires (2026-11-16)**

- It applies to "all certified Android devices running Android 7 or higher", through Google Play services
  ([FAQ](https://developer.android.com/developer-verification/guides/faq)).
- From **September 30, 2026** the protections are live for users in **Brazil, Indonesia, Singapore and Thailand**, for
  installs from the participating stores (Google Play, HONOR App Market, OPPO App Market, Galaxy Store, Palm Store,
  V-Appstore, GetApps) ([Guides](https://developer.android.com/developer-verification/guides)). In that first phase,
  apps that users sideload, or install from other stores, are not checked yet
  ([FAQ](https://developer.android.com/developer-verification/guides/faq), updated July 15, 2026).
- In **2027** Google will "expand these protections globally for all apps on certified Android devices", and "if your
  app runs on a certified device, these requirements apply to you, regardless of your app's download source"
  ([Guides](https://developer.android.com/developer-verification/guides)). No exact 2027 date was published on
  2026-11-16. This is when the website APK is affected in Pakistan.
- On Google Play: "Effective September 30, 2026, all Play packages must be registered"; "Apps not registered by Sep 30,
  2026 will be removed from Play" ([Registering Play package names](https://support.google.com/googleplay/android-developer/answer/16984799)).
  A new app's package name is registered automatically when the app is created in Play Console
  ([Register on Google Play Console](https://developer.android.com/developer-verification/guides/google-play-console)).
- Identity: "For most Play developers, no new action is required for identity verification" (same page). Our Play
  verification (section 2.2) covers it.
- Apps outside Play: "developers with a Play Console account can use it as the single place to manage all their
  verification requirements, including for their apps distributed outside of Play"
  ([FAQ](https://developer.android.com/developer-verification/guides/faq)). The separate Android Developer Console
  (US$25 for a Full Distribution account, per the FAQ, March 25, 2026) is only for developers who are not on Play, so
  we do not need it.
- What a user sees for an **unregistered** app after enforcement: a one-time "advanced flow" (turn on developer mode,
  confirm nobody is coaching them, restart, "a one-time, one-day wait", confirm with fingerprint, face or PIN), and even
  then a warning on each install. "Unregistered apps can only be installed or updated when the advanced flow is enabled
  or by using ADB." ADB installs are not affected ([FAQ](https://developer.android.com/developer-verification/guides/faq)).
  For the website APK that would mean every update fails for users without the advanced flow, so the package and key
  must be registered before the 2027 rollout.

**What we do.** One application id is shipped with two signing keys: Google Play signs the Play build with its own
app signing key, and we sign the website APK with our release key (mobile/DISTRIBUTION.md, mobile/RELEASE.md).

1. Create the app in Play Console. Play registers the package name and its Play signing key automatically.
2. Add the website key: Play Console → **Android developer verification** → **Package names** → the package → **Add
   key** → enter the website key's SHA-256 certificate fingerprint. If Play asks for proof of ownership: **Verify** →
   copy the snippet → the team puts it in `assets/adi-registration.properties`, builds a release APK signed with the
   website key, and you upload it ([Adding additional keys](https://support.google.com/googleplay/android-developer/answer/16762301)).
3. A package name that already has installs outside Play (today `com.echoledger.mobile`) is registered from a list of
   eligible keys, with the same proof APK ([Registering Android package names](https://support.google.com/googleplay/android-developer/answer/16761053)).
   If the name decision changes the application id, register the new one as well: a package name "never seen on
   Android" needs only the public key certificate, no APK (same page).
4. Fingerprints: the website key's comes from `apksigner verify --print-certs` (mobile/RELEASE.md). The Play key's is
   under **Protected with Play → Play Store protection → Manage Play app signing**; with Play's quantum-ready hybrid
   signing there are three fingerprints to copy ([Use Play App Signing](https://support.google.com/googleplay/android-developer/answer/9842756)).
5. Keep the website keystore and its backup safe: "If you lose your signing key you won't be able to register your
   packages" ([FAQ](https://developer.android.com/developer-verification/guides/faq)).

### 2.6 Permissions in each build (checked in the manifests)

Sources: `mobile/app/src/main/AndroidManifest.xml`, `mobile/app/src/play/AndroidManifest.xml` (removes the call-only
parts), `mobile/app/src/full/AndroidManifest.xml` (adds the updater), and the merged manifest of the website build
(which adds the library permissions). Only the Play build goes to Google Play.

| Permission or component | Website build (`full`) | Play build (`play`) | What it is for | Play Console declaration |
|---|---|---|---|---|
| `RECORD_AUDIO` | Yes | Yes | The Record button, the voice keyboard, live captions | None (runtime permission) |
| `FOREGROUND_SERVICE` + `FOREGROUND_SERVICE_MICROPHONE` (RecordingService, type `microphone`) | Yes | Yes | A recording the person starts | Foreground service declaration and video (2.7) |
| `FOREGROUND_SERVICE_MEDIA_PROJECTION` (LiveTranslateService, type `mediaProjection`) | Yes | Yes | Live Translate subtitles from Android's screen-audio capture | Foreground service declaration and video (2.7) |
| `FOREGROUND_SERVICE_SPECIAL_USE` (both services, with a special-use subtype) | Yes | **Removed** | Keeps the call pipeline ready for the capture helper | Would need a declaration; not in the Play build |
| `POST_NOTIFICATIONS` | Yes | Yes | The recording notification with Stop | None |
| `SYSTEM_ALERT_WINDOW` | Yes | Yes | The caption bubble and the Live Translate subtitle window | None; the person allows "display over other apps" in Android settings ([reference](https://developer.android.com/reference/android/Manifest.permission#SYSTEM_ALERT_WINDOW)) |
| `DETECT_SCREEN_RECORDING` (Android 15+) | Yes | Yes | Shrinks the app's own floating window to a dot while it appears in a screen recording or share | None; protection level "normal" ([reference](https://developer.android.com/reference/android/Manifest.permission#DETECT_SCREEN_RECORDING), [Android 15 features](https://developer.android.com/about/versions/15/features)) |
| `RECEIVE_BOOT_COMPLETED` | Yes | Yes | Turns auto-record back on after a restart or update | None; protection level "normal" |
| `CAMERA` | Yes | Yes | Scanning the pairing QR code only (processed on the phone) | None |
| `INTERNET`, `ACCESS_NETWORK_STATE`, `WAKE_LOCK`, `USE_BIOMETRIC`, `USE_FINGERPRINT` | Yes | Yes | Networking, background jobs, app lock (the last four come from libraries) | None |
| `READ_CALL_LOG` | Yes | **Removed** | Who a SIM call was with, and its talk time | SMS and Call Log policy (2.8) |
| `READ_CONTACTS` | Yes | **Removed** | The caller's name on a recording | — |
| `READ_MEDIA_AUDIO`, `READ_EXTERNAL_STORAGE` (Android 12L and lower) | Yes | **Removed** | Importing the Phone app's own call recordings | — |
| Shizuku permission `moe.shizuku.manager.permission.API_V23`, the Shizuku and binder hand-off providers | Yes | **Removed** | The capture helper | — |
| Notification listener (CallerNameListener) | Yes | **Removed** | Caller names from call notifications | — |
| `REQUEST_INSTALL_PACKAGES` + update receiver | Yes | No | The website build updates itself from the server | Play does not allow self-updating apps ([Device and Network Abuse](https://support.google.com/googleplay/android-developer/answer/9888379)) |
| Voice keyboard (input method), Quick Settings tiles, home-screen widget, share target for audio | Yes | Yes | — | None |
| `<queries>` for launcher apps | Yes | Yes | Picking an app for Live Translate | None (this is not `QUERY_ALL_PACKAGES`) |

`PlayBuildTest` checks the Play build's merged manifest on every test run (mobile/DISTRIBUTION.md).

### 2.7 Foreground service declarations and demo videos

**Rules (2026-11-16).** Apps targeting Android 14 and later declare their foreground service types on the App content
page. For each type Google asks for a description of the feature, the impact on the user if the system defers or
interrupts it, "a link to a video demonstrating each foreground service feature", and a use case from a list. "The
video should demonstrate the steps the user needs to take in your app in order to trigger the feature."
`specialUse` is allowed "in limited scenarios", and "all foreground service types are subject to review"
([Foreground service requirements](https://support.google.com/googleplay/android-developer/answer/13392821)).
On the Android side: `microphone` needs `RECORD_AUDIO` granted and cannot be started from the background or from
`BOOT_COMPLETED`; `mediaProjection` needs the person's consent through `createScreenCaptureIntent()` before the service
starts, and apps targeting Android 15 cannot start it from `BOOT_COMPLETED`
([Foreground service types](https://developer.android.com/develop/background-work/services/fgs/service-types)). The
Device and Network Abuse policy also asks that foreground services be started by the user and limited in duration
([policy](https://support.google.com/googleplay/android-developer/answer/9888379)).

**The Play build declares two types.** `specialUse` exists only in the website build.

| Type | Description of the feature | If deferred | If interrupted | Use case |
|---|---|---|---|---|
| `microphone` (RecordingService) | Records a voice note or an in-person meeting that the person starts with the Record button. A notification with Stop is shown for the whole recording. *Draft — Hassam confirms.* | The start of what the person says is not recorded. *Draft.* | The rest of the meeting is not recorded. *Draft.* | Audio recording started by the user. *Draft.* |
| `mediaProjection` (LiveTranslateService) | Live Translate shows translated subtitles of another app's audio (a video, a game's voice chat) in a floating window. The audio comes from Android's screen-audio capture after the person agrees on Android's prompt. A notification with Stop is shown while it runs. *Draft — Hassam confirms.* | The first sentences are not translated. *Draft.* | Subtitles stop in the middle of the video or conversation. *Draft.* | Capturing another app's audio for live subtitles. *Draft.* |

**Videos to record** (on a phone with the Play build from the internal test track, 30–60 seconds each):

1. *Microphone:* open the app → Record tab → tap Record → allow the microphone the first time → show the notification
   with Stop → go to the home screen and show the recording continuing → return and tap Stop → Keep → the recording in
   the Library.
2. *Media projection:* open Live Translate → pick a video app → Start → accept Android's screen-capture prompt → play a
   video and show the subtitle window and the notification → stop from the notification.

Google's page sets no hosting rule; use a link the reviewer can open without signing in (an unlisted YouTube video is
the usual choice).

If `specialUse` is ever shipped on Play, its two subtype texts are already in the main manifest; the reviewer must be
able to see why no other type fits.

### 2.8 SMS and Call Log permissions

- **Policy (2026-11-16).** The SMS and Call Log permission groups are for apps registered as the default SMS, Phone or
  one other listed default handler, plus a list of temporary exceptions reviewed by Google. "Call recorder" is listed as
  an invalid use. Apps that qualify declare the permissions through the Permissions Declaration Form in Play Console;
  "Apps that fail to meet policy requirements or lack a Permissions Declaration Form may be removed from Google Play";
  an app that does not qualify "must remove these permissions from your app's manifest"
  ([Use of SMS or Call Log permission groups](https://support.google.com/googleplay/android-developer/answer/10208820)).
- **Our case.** The Play build does not request `READ_CALL_LOG` (or any SMS permission) and never asks for it, so no
  Permissions Declaration Form is needed. If Play Console ever asks for this declaration after an upload, the bundle
  contains one of these permissions: fix the build rather than declare.
- The website build keeps `READ_CALL_LOG`; it is not distributed on Play, so this Play form does not apply to it. It
  still needs Android developer verification (2.5).

### 2.9 App content answers

| Section | Draft answer (**draft — Hassam confirms**) | Rules |
|---|---|---|
| Privacy policy | `https://<server>/privacy` today; `https://<final-domain>/privacy` after the rename | Public HTTP(S) page with a valid certificate, worldwide, not a PDF, specific to the app, and linked inside the app ([Prepare your app for review](https://support.google.com/googleplay/android-developer/answer/9859455)) |
| App access | "All or some functionality is restricted"; the review account and instructions from section 5.6 | Sign-in details "accessible at all times, reusable, and valid regardless of user location"; if the app normally asks for a one-time code, give reusable credentials that work without it ([Sign-in details for review](https://support.google.com/googleplay/android-developer/answer/15748846)) |
| Ads | No ads | ([Prepare your app for review](https://support.google.com/googleplay/android-developer/answer/9859455)) |
| Content rating | Section 5.5 | "Apps without a content rating will be removed from the Play Store." ([Content ratings](https://support.google.com/googleplay/android-developer/answer/9898843)) |
| Target audience | 18 and over only. Optional: turn on "Restrict Minor Access" | ([Target audience and content](https://support.google.com/googleplay/android-developer/answer/9867159)) |
| News app | No | — |
| Government app | No | — |
| Financial features | The app has no financial features (the Play build shows plans without prices or payments) | ([Financial features declaration](https://support.google.com/googleplay/android-developer/answer/13849271)) |
| Health apps | No health features. The declaration is required for every published app, including testing tracks | ([Health apps declaration](https://support.google.com/googleplay/android-developer/answer/14738291)) |
| Advertising ID | The app does not use it: no `AD_ID` permission in the merged manifest, no ads or analytics library | — |
| Data safety | Table below | ([Data safety](https://support.google.com/googleplay/android-developer/answer/10787469)) |
| Foreground service types | `microphone` and `mediaProjection`, section 2.7 | — |
| Permissions declaration | Not shown (no SMS or Call Log permission) | Section 2.8 |

#### Data safety (Play build) — draft, Hassam confirms every row

How Google defines the terms ([Data safety](https://support.google.com/googleplay/android-developer/answer/10787469)):
"collect" means transmitting data off the device, to us or to a third party; sending data to a **service provider**
("an entity that processes user data on behalf of the developer and based on the developer's instructions") or because
of a **user-initiated action** is not "sharing"; "ephemeral" means kept only in memory and only for the request; data
may be marked "optional" only if every user can choose not to provide it; approximate location inferred from an IP
address must be declared as location.

In our case the service providers are the speech-recognition and language engines listed in the Privacy Policy, the
hosting provider and the email provider. Share links and "Send to a teammate" are user-initiated.

| Play data type | Collected | Shared | Ephemeral | Required or optional | Purposes | What it is in the app | Status |
|---|---|---|---|---|---|---|---|
| Personal info → Name | Yes | No | No | Required | Account management, App functionality | The name given at sign-up (`ApiClient.authSignUp`) | Draft — Hassam confirms |
| Personal info → Email address | Yes | No | No | Required | Account management, App functionality | Sign-in and sign-up; addresses typed to share with people | Draft — Hassam confirms |
| Personal info → User IDs | Yes | No | No | Required | Account management, App functionality | The account id behind the device token | Draft — Hassam confirms |
| Location → Approximate location | Only if the server's `ECHOLEDGER_COUNTRY_HEADER` is set (check production) | No | No | Required | Fraud prevention, security, and compliance | The country shown next to devices and in sign-in alerts (`web/app/Support/ClientCountry.php`); the server also keeps sign-in IP addresses (Privacy Policy, "Devices and sign-ins") | Draft — Hassam confirms |
| Messages → Other in-app messages | Yes | No | No | Optional | App functionality | The note sent with "Send to a teammate" (`ApiClient.sendToTeammates`) | Draft — Hassam confirms |
| Audio → Voice or sound recordings | Yes | No | No | Required | App functionality | Recordings, live captions and dictation audio, Live Translate audio (sent from the phone to the engine with a key valid for minutes) | Draft — Hassam confirms |
| Audio → Other audio files | Yes | No | No | Optional | App functionality | Audio files the person shares into the app (`ShareImportActivity`) | Draft — Hassam confirms |
| Files and docs | Yes | No | No | Optional | App functionality | Project files (`ApiClient.uploadClientFile`) | Draft — Hassam confirms |
| App activity → App interactions | Yes | No | No | Required | App functionality, Account management | "Opened" marks, notifications marked read, minutes used for plan limits (`markOpened`, `UsageWorker`) | Draft — Hassam confirms |
| App activity → In-app search history | Yes | No | No: the query is in the URL, so the web server's access log may keep it | Optional | App functionality | Library search (`ApiClient.search`) | Draft — Hassam confirms |
| App activity → Other user-generated content | Yes | No | No | Required | App functionality | Transcripts, titles, notes, bookmarks, project instructions, questions asked about a project | Draft — Hassam confirms |
| App info and performance → Diagnostics | Yes | No | No | Optional | App functionality | The device check, sent only when the person taps "Send to my admin" (`ApiClient.reportDevice`): brand, model, Android and app version, check results | Draft — Hassam confirms |
| App info and performance → Crash logs | No | — | — | — | — | No crash or analytics library; "Copy diagnostics" only copies to the clipboard (`CaptureLog`) | Draft — Hassam confirms |
| Device or other IDs | Yes | No | No | Required | App functionality, Account management, Fraud prevention, security, and compliance | A random install id (`core/InstallId.kt`), the device name (maker and model) and versions sent at sign-in | Draft — Hassam confirms |
| Contacts, Phone number, Photos and videos, Financial info, Calendar, Health, Precise location, Web browsing | No | — | — | — | — | Not read or sent by the Play build | Draft — Hassam confirms |

Security practices and deletion (draft — Hassam confirms):

- **Encrypted in transit: Yes.** HTTPS only, plain HTTP is refused in release builds
  (`mobile/app/src/main/res/xml/network_security_config.xml`), and streaming to the engines uses secure WebSockets.
- **Users can ask for their data to be deleted: Yes.** In the app (You → Account → Delete my account) and on the web at
  `https://<server>/account/delete` (later `https://<final-domain>/account/delete`). The account is
  erased 7 days later, with time to change one's mind (`AccountDeletion::GRACE_DAYS = 7`). Single recordings and
  projects can be deleted without deleting the account (48-hour undo bin).
- Google's rules for the deletion link: an in-app path and "a web link resource where users can request app account
  deletion"; the page must work, make the request path easy to find, "reference the app or developer name (that is, as
  it appears on your store listing in Google Play)", and explain what is kept; the link goes in the Data safety form
  ([Account deletion requirements](https://support.google.com/googleplay/android-developer/answer/13327111)). The live
  page meets this with the name "EchoLedger" (checked 2026-11-16); it must show `<Name>` when the listing does.
- **Account creation:** yes, with an emailed code or Google sign-in in the browser; a password can be added later.

**Website build only (not part of the Play form; keep in the Privacy Policy):** the call log (who a SIM call was with,
the number, the talk time), contact names used as caller labels, caller names taken from call notifications, the Phone
app's own call recordings, and payment receipts with the payer's name and reference.

### 2.10 Before the first Play submission

- **Target API (2026-11-16):** since August 31, 2026 "new apps and app updates must target Android 16 (API level 36)
  or higher" ([Target API level requirements](https://support.google.com/googleplay/android-developer/answer/11926878)).
  The app targets 37 (`mobile/app/build.gradle.kts`).
- **App bundle and Play App Signing:** uploading an app bundle enrolls the app in Play App Signing by default
  ([Use Play App Signing](https://support.google.com/googleplay/android-developer/answer/9842756)). Build with
  `bundlePlayRelease` (mobile/DISTRIBUTION.md).
- **The package name is fixed by the first upload** and registered for developer verification at the same time, so the
  name decision comes first.
- **App Links:** add the Play app signing key's SHA-256 to the server's App Links fingerprints (mobile/DISTRIBUTION.md).
- **Default server:** build the Play bundle with `echoledger.defaultServer` set, so reviewers do not have to type a
  server address (`mobile/app/build.gradle.kts`).
- **Payments:** apps "may not lead users to a payment method other than Google Play's billing system", including
  "buttons, links, messaging"; and "if a listing refers to paid features, your app listing must clearly notify users
  that payment is required" ([Payments policy](https://support.google.com/googleplay/android-developer/answer/9858738)).
  The Play build already shows plans without prices or buy buttons; the listing says "Some features need a paid plan".
- **Listing content:** do not describe call recording or the website build in the Play listing or the Play build (a
  team rule for the Play build).
- **Optional clean-up:** the capture-helper libraries (the Shizuku API and provider, and the hidden-API helper library)
  are compiled into both flavors (`implementation(...)` in `mobile/app/build.gradle.kts`) although the Play build never
  calls them. Declaring them for the `full` flavor only keeps them out of the Play bundle. No Play rule requires this; it
  keeps the bundle limited to what it uses.

---

## 3. Apple Developer Program (organization, US$99 a year)

### 3.1 Requirements (2026-11-16)

| Item | What Apple says | Source |
|---|---|---|
| Apple Account | "An Apple Account with two-factor authentication turned on"; "Make sure both your first and last names are your legal names"; Apple recommends an address from the organization | [Enrollment](https://developer.apple.com/programs/enroll/) |
| Who enrolls | The Account Holder "must have the legal authority to bind your organization to legal agreements" (owner or founder, executive, or an employee given that authority) | same |
| Legal entity | "We do not accept DBAs, fictitious business names, trade names, or branches." | same |
| D-U-N-S | Required for organizations (section 1) | same |
| Work email | "associated with your organization's domain name" | same |
| Website | "Publicly available and functional, and its domain name must be associated with your organization"; social media pages and registrar placeholder pages are not accepted. `https://skillleo.com` was live on 2026-11-16. | same |
| Fee | "99 USD per membership year", in local currency where available; "make your purchase with a credit card that can be charged in USD" | same · [Program enrollment help](https://developer.apple.com/support/enrollment/) |

### 3.2 Steps

1. Wait until Apple has the D-U-N-S number (up to 2 business days after D&B issues it, section 1).
2. Go to [developer.apple.com/programs/enroll](https://developer.apple.com/programs/enroll/) → Start your enrollment (or
   use the Apple Developer app) → sign in with the company Apple Account → choose the organization type → enter the
   legal entity name exactly as in the D&B record, the D-U-N-S number, the headquarters address and phone, the website
   and your work email.
3. Apple checks "legal binding authority, legal entity name and status, the D-U-N-S Number, phone and email, and
   website" ([Enrollment](https://developer.apple.com/programs/enroll/)). It may ask for "business documents that are
   notarized" ([Program enrollment help](https://developer.apple.com/support/enrollment/)).
4. "Organizations can review the license agreement and purchase a membership once Apple Developer Support verifies the
   enrollment information and sends an email with next steps." If no confirmation arrives within 24 hours of paying,
   contact Apple with the Enrollment ID (same page).
5. Note the Team ID (Account → Membership details). You stay the Account Holder; others can be added with their own
   roles later.
6. For the EU App Store: since February 17, 2025 "apps without trader status will be removed from the App Store in the
   European Union (EU) until trader status is provided and verified"
   ([Upcoming requirements](https://developer.apple.com/news/upcoming-requirements/)). Enter it in App Store Connect
   before the first iPhone release if the app will be offered in the EU.

### 3.3 Mac app: signing and notarization

**What Apple requires**

- A **Developer ID** certificate lets Gatekeeper trust an app downloaded outside the Mac App Store, and "you must be
  the Account Holder of your development team … in order to generate this certificate"
  ([Developer ID](https://developer.apple.com/developer-id/)).
- **Notarization:** "Beginning in macOS 10.15, all software built after June 1, 2019, and distributed with Developer ID
  must be notarized." The notary service needs: code signing of every executable with a Developer ID certificate, the
  Hardened Runtime, a secure timestamp, no `com.apple.security.get-task-allow` entitlement, the macOS 10.9 or later
  SDK, and correctly formatted entitlements. Since November 1, 2023 it accepts uploads only from `notarytool` or Xcode 14
  and later ([Notarizing macOS software](https://developer.apple.com/documentation/security/notarizing-macos-software-before-distribution)).

**What we already have.** `.github/workflows/desktop-mac.yml` signs with the Hardened Runtime, notarizes and staples
as soon as five secrets exist (docs/SIGNING.md): `APPLE_CERTIFICATE`, `APPLE_CERTIFICATE_PASSWORD`, `APPLE_ID`,
`APPLE_PASSWORD`, `APPLE_TEAM_ID`.

**Hassam's steps (about 20 minutes, after the membership is active)**

1. Xcode → Settings → Accounts → Manage Certificates → + → **Developer ID Application** (or developer.apple.com →
   Certificates).
2. Keychain Access → export that certificate with its private key as a `.p12` file with a password.
3. [appleid.apple.com](https://appleid.apple.com) → Sign-In and Security → App-Specific Passwords → create one for
   notarization.
4. Copy the Team ID from Membership details.
5. GitHub → the repository → Settings → Secrets and variables → Actions → add the five secrets (docs/SIGNING.md says
   how to base64 the `.p12`).

**One check for the team before the first signed build.** The Mac app reads the address of the browser tab that uses
the microphone by running `osascript` (`desktop/src-tauri/src/audio/platform/macos.rs`) and declares
`NSAppleEventsUsageDescription` in `desktop/src-tauri/Info.plist`, while `desktop/src-tauri/Entitlements.plist` lists
only `com.apple.security.device.audio-input` and `com.apple.security.network.client`. Under the Hardened Runtime,
sending Apple events to other apps is controlled by the Apple Events entitlement
`com.apple.security.automation.apple-events` ([Apple: Apple Events entitlement](https://developer.apple.com/documentation/bundleresources/entitlements/com.apple.security.automation.apple-events)).
Add it, then test browser-tab detection on the first notarized build.

**What changes for Mac users:** no "Open Anyway" step on first launch, and macOS keeps the microphone, system audio and
Screen Recording permissions across updates (docs/SIGNING.md).

### 3.4 iPhone app (v1.8 P8): what it will need later

| Item | Apple's rule (2026-11-16) | Source |
|---|---|---|
| Build tools | Since April 28, 2026: Xcode 26 or later with the iOS 26 SDK | [Upcoming requirements](https://developer.apple.com/news/upcoming-requirements/) |
| Bundle ID | Cannot change after a build is uploaded; must match Xcode | [App information](https://developer.apple.com/help/app-store-connect/reference/app-information/app-information) |
| Privacy | A privacy policy link in App Store Connect and inside the app (Guideline 5.1.1(i)); App Privacy details for every new app and update; approved reasons for "required reason" APIs since May 1, 2024 | [Review Guidelines](https://developer.apple.com/app-store/review/guidelines/) · [App privacy details](https://developer.apple.com/app-store/app-privacy-details/) · [Upcoming requirements](https://developer.apple.com/news/upcoming-requirements/) |
| Account deletion | "If your app supports account creation, you must also offer account deletion within the app." (5.1.1(v)) | [Review Guidelines](https://developer.apple.com/app-store/review/guidelines/) |
| Review account | Include demo account details "(and turn on your back-end service!)" (2.1); the demo account "must not expire" | [Review Guidelines](https://developer.apple.com/app-store/review/guidelines/) · [Platform version information](https://developer.apple.com/help/app-store-connect/reference/app-information/platform-version-information) |
| Recording | "Explicit user consent and … a clear visual and/or audible indication when recording" (2.5.14); background services only "for their intended purposes" such as audio (2.5.4), which applies to recording in the background | [Review Guidelines](https://developer.apple.com/app-store/review/guidelines/) |
| Sign-in | If Google Sign-In sets up the main account, the app must also offer an equivalent login that limits data to name and email, lets people keep their email private, and does not track for advertising (4.8); Sign in with Apple is the usual answer (it is in the v1.8 backlog) | [Review Guidelines](https://developer.apple.com/app-store/review/guidelines/) |
| Paid plans | Unlocking features needs in-app purchase (3.1.1). Plans bought on the website may be used in the app "provided those items are also available as in-app purchases within the app" (3.1.3(b)); sales only to organizations for their staff are an exception (3.1.3(c)); the United States storefront allows links to other ways to pay. "No prices or payment links" alone may not be enough if the iPhone app unlocks plans bought on the website: decide StoreKit or a free app before submitting | [Review Guidelines](https://developer.apple.com/app-store/review/guidelines/) |
| Data sent to outside services | 5.1.2(i): disclose clearly where personal data is shared with third parties, including outside processing services (the guideline names this case explicitly), and get explicit permission before doing so | [Review Guidelines](https://developer.apple.com/app-store/review/guidelines/) (last updated October 16, 2026) |
| Age rating | Answer the updated age rating questions (required since January 31, 2026) | [Upcoming requirements](https://developer.apple.com/news/upcoming-requirements/) |
| EU | Trader status (3.2, step 6) | same |
| Store texts, icon, screenshots | Section 5 | — |

---

## 4. Microsoft Partner Center (MSIX in the Microsoft Store)

### 4.1 Account type and cost (2026-11-16)

- Two types: Individual and **Company** ("publish apps under the name of a registered legal business entity").
  "With the new onboarding experience, there are **no registration fees** for either account type." An individual
  account cannot be changed into a company account later
  ([Open a developer account](https://learn.microsoft.com/en-us/windows/apps/publish/partner-center/open-a-developer-account), updated 2026-10-29).
- Store Policies: "Company accounts must be used for organizations, businesses, and any person acting in relation to
  their trade or profession" ([Microsoft Store Policies 7.20](https://learn.microsoft.com/en-us/windows/apps/publish/store-policies)).

### 4.2 Steps (company account)

From [Open a developer account](https://learn.microsoft.com/en-us/windows/apps/publish/partner-center/open-a-developer-account):

1. Go to [storedeveloper.microsoft.com](https://storedeveloper.microsoft.com), "the only supported entry point for
   company onboarding" → Get started → **Company account** (free).
2. Sign in with a personal Microsoft account or a Microsoft Entra ID work account.
3. Business verification: the D-U-N-S number ("recommended for faster verification") or official business documents
   (manual review, "up to 3-5 business days").
4. Contact details: the verification email must be a work address on the company's domain ("Personal emails like Gmail
   or Yahoo aren't supported"); the support contact entered here appears on the Store listing.
5. Accept the agreement. Verification runs in order: mandatory due diligence (blocking), then business and employment
   verification. Each step completes automatically "within a few seconds to a minute" or goes to manual review, which
   "typically take[s] 2–5 business days"; each verification type allows up to three appeals.
6. Select "Publish to Store" to open Partner Center (the status can take up to 30 minutes to appear).

### 4.3 The first MSIX submission

| Item | Rule | Source |
|---|---|---|
| Name | Reserve it up to three months before publishing; unused reservations are removed after three months | [Reserve your app's name](https://learn.microsoft.com/en-us/windows/apps/publish/publish-your-app/msix/reserve-your-apps-name) |
| Package identity | The manifest's identity values must match "View app identity details" in Partner Center; values are case-sensitive | [App package requirements](https://learn.microsoft.com/en-us/windows/apps/publish/publish-your-app/msix/app-package-requirements) |
| Signing | MSIX packages "don't have to be signed with a certificate rooted in a trusted certificate authority"; the Store re-signs them. An MSI or EXE installer would need our own code-signing certificate. | same |
| Restricted capabilities | A desktop app's `runFullTrust` is a restricted capability; explain why it is needed on the Submission options page | [App capability declarations](https://learn.microsoft.com/en-us/windows/apps/package-and-deploy/app-capability-declarations) |
| Age rating | IARC questionnaire; an IARC rating ID from another store can be reused | [Age ratings](https://learn.microsoft.com/en-us/windows/apps/publish/publish-your-app/msix/age-ratings) |
| Privacy policy | Required when the product "accesses, collects or transmits Personal Information" (10.5.1); URL entered in Partner Center | [Store Policies](https://learn.microsoft.com/en-us/windows/apps/publish/store-policies) |
| Review account | "If your product requires login credentials, provide us with a working demo account using the Notes for certification field." | same |
| Payments | Non-game products on PC "may either use a secure third-party purchase API or the Microsoft Store in-product purchase API" (10.8.1); the same for subscriptions (10.8.6) | same |
| Payouts | Only if we sell through Microsoft: Pakistan is supported for Microsoft Store payouts (PayPal is not); threshold USD 50 | [Payout details by region](https://learn.microsoft.com/en-us/partner-center/marketplace-offers/payment-thresholds-methods-timeframes) |

---

## 5. What every store needs from us

### 5.1 Addresses and mailbox

| Item | Today (checked 2026-11-16) | After the rename | Needed by |
|---|---|---|---|
| Privacy policy | `https://<server>/privacy` (live) | `https://<final-domain>/privacy` | Google Play, Apple, Microsoft |
| Account deletion page | `https://<server>/account/delete` (live) | `https://<final-domain>/account/delete` | Google Play (Data safety) |
| Terms | `https://<server>/terms` (live) | `https://<final-domain>/terms` | Linked from the apps |
| Support URL | None yet: `/contact`, `/help` and `/support` return 404 | `https://<final-domain>/contact` (the help and contact pages planned in v1.8) | Apple (required; "must … lead to actual contact information", [Platform version information](https://developer.apple.com/help/app-store-connect/reference/app-information/platform-version-information)); optional website for Play and Microsoft |
| Contact mailbox | `contact@<final-domain>` not created; a company mailbox exists on `skillleo.com` | `contact@<final-domain>` | Play's public developer email (an organization address), Apple and Microsoft work email, the Privacy Policy contact line, support |
| Company website | `https://skillleo.com` (live) | unchanged | D&B, Play (Search Console), Apple, Microsoft |

**Privacy Policy changes to make before the first submission** (found on 2026-11-16 in
`web/resources/views/legal/privacy.blade.php`):

1. The contact line points to a personal mailbox; use `contact@<final-domain>`.
2. Name the data the website build attaches to call recordings (the caller's name and number from the call log,
   contacts and call notifications) and the optional device check report; today the policy does not mention them.
3. Update the product name when the listing changes (Play's deletion page must use the listing name, section 2.9).

### 5.2 Texts

| Field | Google Play | Apple App Store | Microsoft Store |
|---|---|---|---|
| Name | 30 characters | 2–30 characters | Reserved name; short names display best (up to 256) |
| Short text | Short description, 80 characters | Subtitle, 30 characters; promotional text, 170 characters | Short description, up to 1,000 characters (about the first 270 are shown) |
| Long text | Full description, 4,000 characters | Description, 4,000 characters | Description, required, up to 10,000 characters |
| Other | — | Keywords, 100 bytes; support URL; copyright | Product features: up to 20, 200 characters each; "What's new", 1,500 characters |
| Sources | [Create and set up your app](https://support.google.com/googleplay/android-developer/answer/9859152) · [Best practices](https://support.google.com/googleplay/android-developer/answer/13393723) | [App information](https://developer.apple.com/help/app-store-connect/reference/app-information/app-information) · [Platform version information](https://developer.apple.com/help/app-store-connect/reference/app-information/platform-version-information) | [Store listing info](https://learn.microsoft.com/en-us/windows/apps/publish/publish-your-app/msix/add-and-edit-store-listing-info) · [Reserve your app's name](https://learn.microsoft.com/en-us/windows/apps/publish/publish-your-app/msix/reserve-your-apps-name) |

**Draft texts for the Play build (draft — Hassam confirms)**

- Name: `<Name>: Transcripts & Notes` (fits 30 characters with either name under consideration).
- Short description (78 characters): *Record meetings and voice notes, get accurate transcripts in Urdu and English.*
- Full description:

  > `<Name>` turns what you record into a written record you can search, share and keep.
  >
  > Record a meeting or a voice note with one tap, or share voice notes and audio files from other apps to
  > `<Name>`. Transcripts work for English, Urdu, Hindi and mixed speech, with readable versions in Roman Urdu, Urdu
  > and English.
  >
  > • One library on your phone, your computer and the web
  > • Search every transcript
  > • Bookmarks and notes
  > • Export to Word, PDF, text and subtitles (SRT, VTT)
  > • Share a recording with a link that expires, with an optional password
  > • Voice keyboard: dictate into any app
  > • Live Translate: subtitles for a video or a game's voice chat, using Android's screen-audio capture after you agree
  >
  > Private by design
  > • Recordings are encrypted on your phone, on the way to our server and in storage
  > • No ads and no tracking
  > • Lock the app with your fingerprint or face
  > • Delete any recording, or your whole account, at any time
  >
  > A notification is always shown while `<Name>` records. Recording laws differ by country: tell the people you record
  > where the law requires it.
  >
  > Some features need a paid plan.

The Apple and Microsoft texts start from the same draft. The Windows text may describe call capture on the computer,
which the desktop app does.

### 5.3 Icons and graphics

| Store | Asset | Specification | Source |
|---|---|---|---|
| Google Play | App icon | 512 × 512 px, 32-bit PNG with alpha, up to 1,024 KB | [Preview assets](https://support.google.com/googleplay/android-developer/answer/9866151) |
| Google Play | Feature graphic | 1,024 × 500 px, JPEG or 24-bit PNG without alpha | same |
| Apple | App icon | 1024 × 1024 px; an Icon Composer file (layered) or a PNG | [Human Interface Guidelines: App icons](https://developer.apple.com/design/human-interface-guidelines/app-icons) |
| Microsoft | 1:1 app tile icon | 300 × 300 px, recommended (otherwise the Store uses the image in the package) | [Screenshots and images](https://learn.microsoft.com/en-us/windows/apps/publish/publish-your-app/msix/screenshots-and-images) |
| Microsoft | 16:9 Super hero art (optional) | 1,920 × 1,080 or 3,840 × 2,160 px PNG, no text in the image | same |

### 5.4 Screenshots

| Store | Required | Size and format | Source |
|---|---|---|---|
| Google Play, phone | At least 2 to publish; up to 8 per device type | JPEG or 24-bit PNG, no alpha; each side 320–3,840 px; the long side at most twice the short side. For recommendation spots: at least 4 screenshots, 1,080 × 1,920 px or larger (9:16) | [Preview assets](https://support.google.com/googleplay/android-developer/answer/9866151) |
| Google Play, tablets | Only if we list for large screens: at least 4 | 1,080–7,680 px, 16:9 or 9:16 | same |
| Apple, iPhone | At least 1 for "iPhone with Dynamic Island (medium display)"; up to 10 | 1,179 × 2,556 or 1,206 × 2,622 px portrait; JPEG or PNG without transparency | [Screenshot specifications](https://developer.apple.com/help/app-store-connect/reference/screenshot-specifications) |
| Apple, iPad | At least 1 for 13-inch, if the app runs on iPad | 2,064 × 2,752 or 2,048 × 2,732 px portrait | same |
| Microsoft, desktop | At least 1; up to 10 (at least 4 recommended) | PNG, 1,366 × 768 px or larger (up to 3,840 × 2,160), up to 50 MB; captions up to 200 characters | [Screenshots and images](https://learn.microsoft.com/en-us/windows/apps/publish/publish-your-app/msix/screenshots-and-images) |

Screenshots come from the real app (light theme) and show only the review account's sample content.

### 5.5 Content rating questionnaires

| Store | Where | Notes |
|---|---|---|
| Google Play | App content → Content rating (IARC questionnaire) | Ratings from several authorities; an appeal link comes in the certificate email ([Content ratings](https://support.google.com/googleplay/android-developer/answer/9898843)) |
| Microsoft | Age ratings page of the submission (IARC) | The IARC rating ID from Google Play can be entered instead of answering again ([Age ratings](https://learn.microsoft.com/en-us/windows/apps/publish/publish-your-app/msix/age-ratings)) |
| Apple | App Store Connect → age rating | The updated questions have been required since January 31, 2026 ([Upcoming requirements](https://developer.apple.com/news/upcoming-requirements/)) |

Draft answers (**draft — Hassam confirms**): category "Utility, Productivity, Communication or Other"; no violence,
sexual content, bad language, drugs, gambling or frightening content; people **can** share content with others (share
links, sending a recording to a teammate); the app does not share the person's location; no purchases inside the Play
build; not a web browser.

### 5.6 Review account on the server (no real user data)

One account serves Google, Apple and Microsoft reviewers. About 30 minutes.

1. **Mailbox.** Create a mailbox used only for store review on the company domain (for example `review@` on
   `skillleo.com`, later on `<final-domain>`).
2. **Sign up.** If production sign-up is Off or Invite only, set Admin panel → Sign-up → "Who can sign up" to On for a
   few minutes (docs/ADMIN_GUIDE.md, "Sign-up"). Sign up at `/sign-up` with the review mailbox: email → 6-digit code →
   name "Store Review" → Terms. Put the setting back.
3. **Password.** Sign-up sets no password: use "Forgot password?" on the sign-in page to set a long random one. Keep it
   in your password manager, never in the repository.
4. **Two-step sign-in stays off** for this account. The app's password sign-in asks for a code only when two-step
   sign-in is on (`web/app/Services/Accounts/DeviceSignIn.php`), and Google asks for reusable credentials that work
   without contacting us ([Sign-in details for review](https://support.google.com/googleplay/android-developer/answer/15748846)).
5. **Plan and limits.** Admin panel → Customers → the account → Change plan → a paid plan with an end date 6–12 months
   ahead; Features and limits → raise the number of devices, because reviewers sign in on several phones
   (docs/ADMIN_GUIDE.md, "Customers" and "Sign-up").
6. **Sample content, nothing real.** On a test phone, record two or three short scripted voice notes (for example a
   staged meeting read by colleagues who agree), share one sample audio file into the app, and create a project
   "Sample project" with a short text file. Mark each recording **Keep** so the retention period does not erase it
   (README, "Retention"). No customer recordings, real names or phone numbers.
7. **Staff note.** Admin panel → Customers → Notes: "Store review account. Do not delete or suspend."
8. **Lockout.** Five failed password attempts lock the address for 15 minutes, so give reviewers the exact password.
9. **Before each submission:** sign in once with the account to confirm the password still works; Google may reject an
   app when the given password fails ([Sign-in details for review](https://support.google.com/googleplay/android-developer/answer/15748846)).

Where to enter it: Google Play → App content → App access ("Any other instructions" for anything special); Apple →
App Review Information → Sign-in required; Microsoft → Notes for certification.

Instructions template for reviewers (**draft — Hassam confirms**; the team fills in the exact taps):

> Sign in: open the app, tap Sign in, enter the email and password below. No code is needed for this account.
> [Only if the Play build has no default server: when asked for a server, enter `<server address>`.]
> The Library has sample recordings. To record: [taps]. To try Live Translate: [taps], then accept Android's
> screen-capture prompt.
> Email: `<review mailbox>` · Password: `<password>`

---

## Sources (all checked on 2026-11-16)

- **D-U-N-S:** [Apple: D-U-N-S Number](https://developer.apple.com/support/D-U-N-S/) ·
  [Apple: D-U-N-S Lookup](https://developer.apple.com/enroll/duns-lookup/) ·
  [D&B: Get a D-U-N-S](https://www.dnb.com/en-us/smb/duns/get-a-duns.html) ·
  [SECP: FAQs on incorporation](https://www.secp.gov.pk/wp-content/uploads/2025/09/FAQs-Incorporaiton.pdf)
- **Google Play Console Help:** [Create and set up your account](https://support.google.com/googleplay/android-developer/answer/6112435) ·
  [Choose an account type](https://support.google.com/googleplay/android-developer/answer/13634885) ·
  [Required information](https://support.google.com/googleplay/android-developer/answer/13628312) ·
  [Verify your identity](https://support.google.com/googleplay/android-developer/answer/10841920) ·
  [Documents: Pakistan](https://support.google.com/googleplay/android-developer/answer/15633622?co=GENIE.CountryCode%3DPK) ·
  [Testing requirements, personal accounts](https://support.google.com/googleplay/android-developer/answer/14151465) ·
  [Test tracks](https://support.google.com/googleplay/android-developer/answer/9845334) ·
  [Registering Play package names](https://support.google.com/googleplay/android-developer/answer/16984799) ·
  [Registering Android package names](https://support.google.com/googleplay/android-developer/answer/16761053) ·
  [Adding additional keys](https://support.google.com/googleplay/android-developer/answer/16762301) ·
  [Foreground services](https://support.google.com/googleplay/android-developer/answer/13392821) ·
  [SMS and Call Log](https://support.google.com/googleplay/android-developer/answer/10208820) ·
  [Data safety](https://support.google.com/googleplay/android-developer/answer/10787469) ·
  [Account deletion](https://support.google.com/googleplay/android-developer/answer/13327111) ·
  [Prepare your app for review](https://support.google.com/googleplay/android-developer/answer/9859455) ·
  [Sign-in details for review](https://support.google.com/googleplay/android-developer/answer/15748846) ·
  [Content ratings](https://support.google.com/googleplay/android-developer/answer/9898843) ·
  [Target audience](https://support.google.com/googleplay/android-developer/answer/9867159) ·
  [Target API level](https://support.google.com/googleplay/android-developer/answer/11926878) ·
  [Play App Signing](https://support.google.com/googleplay/android-developer/answer/9842756) ·
  [Payments policy](https://support.google.com/googleplay/android-developer/answer/9858738) ·
  [Device and Network Abuse](https://support.google.com/googleplay/android-developer/answer/9888379) ·
  [Preview assets](https://support.google.com/googleplay/android-developer/answer/9866151) ·
  [Create and set up your app](https://support.google.com/googleplay/android-developer/answer/9859152)
- **Android Developers:** [Android developer verification](https://developer.android.com/developer-verification) ·
  [Guides](https://developer.android.com/developer-verification/guides) ·
  [FAQ](https://developer.android.com/developer-verification/guides/faq) ·
  [Register on Google Play Console](https://developer.android.com/developer-verification/guides/google-play-console) ·
  [Foreground service types](https://developer.android.com/develop/background-work/services/fgs/service-types) ·
  [Manifest.permission](https://developer.android.com/reference/android/Manifest.permission) ·
  [Android Developer Console Help: identity](https://support.google.com/android-developer-console/answer/16641416)
- **Apple:** [Enrollment](https://developer.apple.com/programs/enroll/) ·
  [Program enrollment help](https://developer.apple.com/support/enrollment/) ·
  [Developer ID](https://developer.apple.com/developer-id/) ·
  [Notarizing macOS software](https://developer.apple.com/documentation/security/notarizing-macos-software-before-distribution) ·
  [Apple Events entitlement](https://developer.apple.com/documentation/bundleresources/entitlements/com.apple.security.automation.apple-events) ·
  [App Review Guidelines](https://developer.apple.com/app-store/review/guidelines/) ·
  [Upcoming requirements](https://developer.apple.com/news/upcoming-requirements/) ·
  [App privacy details](https://developer.apple.com/app-store/app-privacy-details/) ·
  [Screenshot specifications](https://developer.apple.com/help/app-store-connect/reference/screenshot-specifications) ·
  [App information](https://developer.apple.com/help/app-store-connect/reference/app-information/app-information) ·
  [Platform version information](https://developer.apple.com/help/app-store-connect/reference/app-information/platform-version-information)
- **Microsoft Learn:** [Open a developer account](https://learn.microsoft.com/en-us/windows/apps/publish/partner-center/open-a-developer-account) ·
  [Store Policies 7.20](https://learn.microsoft.com/en-us/windows/apps/publish/store-policies) ·
  [App package requirements](https://learn.microsoft.com/en-us/windows/apps/publish/publish-your-app/msix/app-package-requirements) ·
  [Reserve your app's name](https://learn.microsoft.com/en-us/windows/apps/publish/publish-your-app/msix/reserve-your-apps-name) ·
  [Store listing info](https://learn.microsoft.com/en-us/windows/apps/publish/publish-your-app/msix/add-and-edit-store-listing-info) ·
  [Screenshots and images](https://learn.microsoft.com/en-us/windows/apps/publish/publish-your-app/msix/screenshots-and-images) ·
  [Age ratings](https://learn.microsoft.com/en-us/windows/apps/publish/publish-your-app/msix/age-ratings) ·
  [App capability declarations](https://learn.microsoft.com/en-us/windows/apps/package-and-deploy/app-capability-declarations) ·
  [Payout details by region](https://learn.microsoft.com/en-us/partner-center/marketplace-offers/payment-thresholds-methods-timeframes)
