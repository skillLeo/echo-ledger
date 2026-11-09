// Every setting of the desktop app in one list (v1.7 Settings): its id, title, page, kind, options and the words search
// knows it by. Settings renders its rows from these entries (titles are never written twice), the search box and the
// command palette search them (lib/settingsIndex.ts), and SettingsView.test.tsx keeps rows and entries equal both ways.
// Ids, titles, options and keywords follow the shared settings registry, so the phone, the console and this app use
// the same names in the same order.

export type SettingKind = "page" | "switch" | "choice" | "segmented" | "slider" | "action" | "link" | "info" | "list" | "help";
/** The headers of the Settings root, in order. */
export type RootSection = "Recording" | "Transcription" | "App" | "Account" | "Support" | "";
export const ROOT_SECTIONS: readonly RootSection[] = ["Recording", "Transcription", "App", "Account", "Support", ""];

export interface SettingOption {
  value: string;
  label: string;
  default?: boolean;
}

export interface SettingRequires {
  /** Only on this computer's system. */
  os?: "macos" | "windows";
  /** Only when signed in to an account. */
  account?: boolean;
  /** Only while the server sends plans (entitlements). */
  plans?: boolean;
}

export interface SettingEntry {
  id: string;
  title: string;
  /** The page the row is on; for a page, the page it opens from (null: the Settings root). */
  page: string | null;
  /** The root header a root row or root page sits under. */
  root?: RootSection;
  /** The header inside the page. */
  section?: string;
  kind: SettingKind;
  description?: string;
  options?: readonly SettingOption[];
  /** Extra titles searched like the title, never shown ("Transcription language"). */
  aliases?: readonly string[];
  keywords?: readonly string[];
  /** Roman Urdu spellings. */
  ru?: readonly string[];
  /** Urdu script. */
  ur?: readonly string[];
  requires?: SettingRequires;
  /** Shown in the upgrade flow rather than as a row of its page. */
  flow?: boolean;
  /** "Reset to defaults" on its page puts it back. */
  resettable?: boolean;
  /** Help entries: the whole answer (its first sentence is the result's second line) and the setting it opens. */
  answer?: string;
  target?: string;
}

const opts = (...pairs: (readonly [string, string, boolean?])[]): SettingOption[] => pairs.map(([value, label, isDefault]) => ({ value, label, ...(isDefault ? { default: true } : {}) }));

/** The call apps Calls & meetings lists, in order (CaptureSettings' AUTO_RECORD_APPS). */
export const CALL_APPS: readonly (readonly [string, string])[] = [
  ["whatsapp", "WhatsApp"], ["whatsapp_business", "WhatsApp Business"], ["whatsapp_web", "WhatsApp Web"],
  ["google_meet", "Google Meet"], ["zoom", "Zoom"], ["microsoft_teams", "Microsoft Teams"],
];

/** Notification kinds (the 11 of the core, plus Plan & payments), in the phone's channel order. */
export const NOTIFICATION_KINDS: readonly (readonly [string, string, string])[] = [
  ["recording", "Recording", "A recording that could not start or was not saved."],
  ["transcription", "Transcription", "A transcript is ready, or could not be made."],
  ["enhancement", "Enhancement", "Transcript views, briefs, call notes and answers that are ready."],
  ["uploads", "Uploads", "An upload that needs you."],
  ["call_capture", "Call capture & readiness", "Calls that won't be recorded fully. Quiet hours never hold these back."],
  ["sharing", "Sharing", "Something shared with you."],
  ["account", "Account & security", "A new device signed in to your account."],
  ["usage", "Usage & limits", "The monthly transcription limit."],
  ["plan_payments", "Plan & payments", "Your upgrade requests, receipts and when your plan ends."],
  ["live_translate", "Live Translate", "Live Translate news."],
  ["app_updates", "App updates", "A new version is ready to install."],
  ["other", "Other", "Anything else."],
];

/** The global shortcuts, in Settings' order. */
export const SHORTCUT_ACTIONS: readonly (readonly [string, string])[] = [
  ["record_now", "Record now"],
  ["toggle_captions", "Show or hide captions"],
  ["toggle_saving", "Start or stop saving"],
  ["copy_last2_min", "Copy the last 2 minutes"],
  ["dictation", "Dictation"],
  ["voice_note", "Capture a voice note"],
  ["mark", "Bookmark this moment"],
];

/** The questions Help answers (desktop wording). */
export const HELP_ARTICLES: readonly SettingEntry[] = [
  { id: "help.article.pay", title: "How do I pay for Pro or Ultra?", page: "help", kind: "help", target: "plan.upgrade", requires: { plans: true },
    answer: "Click Upgrade, choose a plan and send the amount to our bank account. Then add a screenshot of the receipt." },
  { id: "help.article.approval_time", title: "How long does approval take?", page: "help", kind: "help", target: "plan.request", requires: { plans: true },
    answer: "We check receipts every day. Most are done within a few hours." },
  { id: "help.article.receipt", title: "How do I send my payment screenshot?", page: "help", kind: "help", target: "plan.receipt", requires: { plans: true },
    answer: "Open Settings › Plan & usage, open your request and choose Add receipt. You can also paste a screenshot or drop the file on the window." },
  { id: "help.article.free_key", title: "How do transcripts work on Free?", page: "help", kind: "help", target: "key", requires: { plans: true },
    answer: "On Free, transcripts use your own free Groq key. Create a key at console.groq.com and paste it in Settings › Transcription key." },
  { id: "help.article.minutes_reset", title: "When do my minutes reset?", page: "help", kind: "help", target: "plan.usage.minutes", requires: { plans: true },
    answer: "Your minutes reset on the 1st of each month. Unused minutes don't carry over." },
  { id: "help.article.dark_mode", title: "How do I turn on dark mode?", page: "help", kind: "help", target: "appearance.theme",
    answer: "Go to Settings › Appearance › Theme and choose Dark." },
  { id: "help.article.delete", title: "How do I delete my account?", page: "help", kind: "help", target: "privacy.delete_account", requires: { account: true },
    answer: "Go to Settings › Privacy & data › Delete my account. We erase your recordings and data." },
];

export const SETTINGS: readonly SettingEntry[] = [
  // ---- Recording ----------------------------------------------------------------------------------------------------
  { id: "calls", title: "Calls & meetings", page: null, root: "Recording", kind: "page",
    keywords: ["call recording", "auto record", "meeting", "whatsapp call", "call"], ru: ["call", "kol", "call record", "mulaqat"], ur: ["کال", "ریکارڈنگ", "میٹنگ"] },
  { id: "calls.auto", title: "Auto-record calls", page: "calls", section: "Record automatically", kind: "switch", resettable: true,
    keywords: ["automatic", "record calls"], ru: ["khud record"], ur: ["خودکار ریکارڈ"] },
  ...CALL_APPS.map(([app, name]): SettingEntry => ({ id: `calls.app.${app}`, title: name, page: "calls", section: "Record automatically", kind: "switch", resettable: true,
    keywords: [name.toLowerCase(), "auto record"] })),
  { id: "calls.ask_unknown", title: "Ask about unknown apps", page: "calls", section: "Record automatically", kind: "switch", resettable: true,
    description: "When an app EchoLedger doesn't know starts using the microphone, a small prompt asks whether to record it.",
    keywords: ["unknown app", "unrecognised app", "prompt", "ask me"] },
  { id: "calls.browser_tabs", title: "Read browser tab addresses", page: "calls", section: "Record automatically", kind: "switch", requires: { os: "macos" }, resettable: true,
    keywords: ["google meet browser", "tab", "url"] },
  { id: "calls.after", title: "After a call", page: "calls", section: "After a call", kind: "choice", resettable: true,
    options: opts(["keep", "Keep automatically", true], ["ask", "Ask me"]), keywords: ["keep", "save", "discard", "ask before saving"], ru: ["baad mein", "save karo"], ur: ["کال کے بعد"] },
  { id: "calls.skip_short", title: "Skip calls shorter than", page: "calls", section: "After a call", kind: "choice", resettable: true,
    options: opts(["0", "Never"], ["5", "5 seconds"], ["10", "10 seconds", true], ["20", "20 seconds"], ["30", "30 seconds"]),
    keywords: ["short calls", "skip", "minimum length"], ru: ["chhoti call"], ur: ["مختصر کال"] },
  { id: "calls.sites", title: "Call sites", page: "calls", section: "Websites", kind: "page", keywords: ["never record", "website rules", "browser"] },

  { id: "mic", title: "Microphone & audio", page: null, root: "Recording", kind: "page",
    keywords: ["microphone", "mic", "audio", "sound", "input"], ru: ["awaz", "aawaz", "mike", "mic"], ur: ["آواز", "مائیک"] },
  { id: "mic.device", title: "Microphone", page: "mic", kind: "choice", resettable: true,
    keywords: ["input device", "record from", "bluetooth", "headset", "earbuds", "usb mic"], ru: ["mike"], ur: ["مائیک"] },
  { id: "mic.levels", title: "Microphone level", page: "mic", kind: "info", keywords: ["level", "meter", "volume", "computer sound"], ru: ["awaz ki satah"] },
  { id: "mic.test", title: "Test recording", page: "mic", kind: "action", keywords: ["test", "try", "check mic"], ru: ["test karo"], ur: ["ٹیسٹ"] },
  { id: "mic.far_side_warning", title: "Silent caller warning", page: "mic", kind: "switch", resettable: true,
    description: "Warns you on the recording pill when a call hasn't heard the other side for 30 seconds.",
    keywords: ["silent", "other side silent", "other person not heard", "far side", "warn me"], ru: ["dusri taraf", "khamosh"], ur: ["خاموش"] },
  { id: "mic.perm.microphone", title: "Microphone", page: "mic", section: "Permissions", kind: "info", keywords: ["permission", "allow", "access"], ru: ["ijazat"], ur: ["اجازت"] },
  { id: "mic.perm.system_audio", title: "Screen and system audio", page: "mic", section: "Permissions", kind: "info", requires: { os: "macos" },
    keywords: ["permission", "allow", "access", "system audio", "other side"], ru: ["ijazat"], ur: ["اجازت"] },

  { id: "captions", title: "Captions", page: null, root: "Recording", kind: "page", keywords: ["captions", "live text", "overlay", "bubble"], ru: ["caption", "likhai"], ur: ["کیپشن"] },
  { id: "captions.enabled", title: "Live captions while recording", page: "captions", kind: "switch", resettable: true, keywords: ["live captions", "subtitles while recording"] },
  { id: "captions.screen_share", title: "Show during screen share", page: "captions", kind: "switch", resettable: true,
    description: "When this is off, you still see your captions, but people watching your shared screen don't.",
    keywords: ["screen sharing", "presentation", "hide overlays"] },
  { id: "captions.style", title: "Style", page: "captions", kind: "page", keywords: ["caption style", "look"] },
  { id: "captions.size", title: "Text size", page: "captions.style", kind: "segmented", resettable: true,
    options: opts(["small", "Small"], ["medium", "Medium", true], ["large", "Large"]), keywords: ["font size", "bigger text", "smaller"], ru: ["bara", "chhota"], ur: ["سائز"] },
  { id: "captions.lines", title: "Lines", page: "captions.style", kind: "segmented", resettable: true,
    options: opts(["2", "2"], ["3", "3", true], ["5", "5"]), keywords: ["rows", "how many lines"], ru: ["lines"] },
  { id: "captions.position", title: "Position", page: "captions.style", kind: "choice", resettable: true,
    options: opts(["bottom_right", "Bottom right", true], ["top_right", "Top right"], ["bottom_left", "Bottom left"], ["top_left", "Top left"]),
    keywords: ["top", "bottom", "place", "corner"], ru: ["upar", "neeche"], ur: ["اوپر", "نیچے"] },
  { id: "captions.width", title: "Width", page: "captions.style", kind: "segmented", resettable: true,
    options: opts(["narrow", "Narrow"], ["normal", "Normal", true], ["wide", "Wide"]), keywords: ["wide", "narrow"] },
  { id: "captions.autohide", title: "Hide when nobody speaks", page: "captions.style", kind: "choice", resettable: true,
    options: opts(["0", "Never", true], ["5", "After 5 seconds"], ["10", "After 10 seconds"], ["30", "After 30 seconds"]), keywords: ["auto hide", "fade"], ru: ["chupao"] },
  { id: "captions.opacity", title: "Background opacity", page: "captions.style", kind: "slider", resettable: true, keywords: ["transparency", "see through"] },
  { id: "captions.compact", title: "Compact captions", page: "captions.style", kind: "switch", resettable: true,
    description: "Shows speakers by colour instead of the Me and Client labels.", keywords: ["small", "compact"] },
  { id: "captions.me_colour", title: "Colour for Me", page: "captions.style", kind: "choice", resettable: true, keywords: ["color", "my colour", "speaker colour"], ru: ["mera rang"], ur: ["رنگ"] },
  { id: "captions.client_colour", title: "Colour for Client", page: "captions.style", kind: "choice", resettable: true, keywords: ["color", "other person", "client colour"], ru: ["client ka rang"], ur: ["رنگ"] },

  { id: "screen", title: "Screen moments", page: null, root: "Recording", kind: "page", keywords: ["screenshots", "shared screen", "capture"], ru: ["screenshot"], ur: ["اسکرین"] },
  { id: "screen.enabled", title: "Keep screen moments", page: "screen", kind: "switch", resettable: true,
    description: "The recording pill shows when screens are on. One click there pauses them.", keywords: ["screen moments", "screenshots"] },
  { id: "screen.target", title: "Capture", page: "screen", kind: "choice", resettable: true,
    options: opts(["meeting_window", "The meeting window", true], ["whole_screen", "The whole screen"]), keywords: ["meeting window", "whole screen"] },
  { id: "screen.border", title: "Capture border", page: "screen", kind: "info", requires: { os: "windows" }, keywords: ["yellow border", "windows"] },
  { id: "screen.permission", title: "Screen Recording", page: "screen", kind: "info", requires: { os: "macos" }, keywords: ["macos permission", "screen recording"] },
  { id: "screen.excluded", title: "Never capture these apps", page: "screen", kind: "page", keywords: ["excluded apps", "password managers", "private apps"] },

  { id: "voice", title: "Voice notes & dictation", page: null, root: "Recording", kind: "page",
    keywords: ["voice note", "dictation", "voice typing"], ru: ["awaz se likhna", "voice note"], ur: ["وائس نوٹ"] },
  { id: "voice.shortcut_mode", title: "How the shortcut works", page: "voice", section: "Dictation", kind: "choice", resettable: true,
    options: opts(["toggle", "Press to start, press again to insert", true], ["push_to_talk", "Hold the shortcut while speaking"]), keywords: ["hotkey", "push to talk", "toggle"] },
  { id: "voice.output", title: "Dictation output", page: "voice", section: "Dictation", kind: "choice", resettable: true,
    options: opts(["type", "Type into the focused app", true], ["clipboard", "Copy to clipboard"]), keywords: ["paste", "type", "clipboard"] },
  { id: "voice.cleanup", title: "Clean up the text", page: "voice", section: "Dictation", kind: "switch", resettable: true,
    description: "Removes “um” and repeated words, and adds capitals and a full stop.", keywords: ["punctuation", "filler words", "um"], ru: ["saaf"] },
  { id: "voice.sounds", title: "Start and stop sounds", page: "voice", section: "Dictation", kind: "switch", resettable: true, keywords: ["beep", "sound", "chime"], ru: ["awaz", "beep"], ur: ["آواز"] },
  { id: "voice.replacements", title: "Replacements", page: "voice", section: "Dictation", kind: "page", keywords: ["dictionary", "when i say", "replace"], ru: ["badlo"] },
  { id: "voice.snippets", title: "Snippets", page: "voice", section: "Dictation", kind: "page", keywords: ["text expansion", "phrase", "template"] },
  { id: "voice.history", title: "Recent dictations", page: "voice", section: "Dictation", kind: "page", keywords: ["history", "clear history"], ru: ["purani"], ur: ["تاریخ"] },

  // ---- Transcription ------------------------------------------------------------------------------------------------
  { id: "transcription.language", title: "Language", page: null, root: "Transcription", kind: "choice", aliases: ["Transcription language"], resettable: true,
    keywords: ["language", "roman urdu", "english", "urdu"], ru: ["zuban", "zaban", "boli", "angrezi", "arabi", "roman"], ur: ["زبان", "اردو", "انگریزی"] },
  { id: "words", title: "Custom words", page: null, root: "Transcription", kind: "page",
    keywords: ["vocabulary", "keyterms", "names", "jargon", "spelling", "project terms"], ru: ["alfaz", "lafz", "naam", "hijje"], ur: ["الفاظ", "نام"] },
  { id: "words.project", title: "Use terms from project", page: "words", kind: "choice", resettable: true, keywords: ["client", "project keyterms"], ru: ["project"], ur: ["پروجیکٹ"] },
  { id: "key", title: "Transcription key", page: null, root: "Transcription", kind: "page", requires: { plans: true },
    keywords: ["groq", "api key", "own key", "whisper", "free transcription"], ru: ["chabi", "key"], ur: ["چابی"] },
  { id: "key.saved", title: "Saved key", page: "key", kind: "info", requires: { plans: true }, keywords: ["remove key", "ending in"] },

  // ---- App ----------------------------------------------------------------------------------------------------------
  { id: "appearance", title: "Appearance", page: null, root: "App", kind: "page", keywords: ["look", "display", "theme"], ru: ["shakal", "rang"], ur: ["ظاہری شکل"] },
  { id: "appearance.theme", title: "Theme", page: "appearance", kind: "segmented", resettable: true,
    options: opts(["system", "System", true], ["light", "Light"], ["dark", "Dark"]), keywords: ["dark mode", "night mode", "light mode", "black"], ru: ["raat", "andhera", "roshni", "kala"], ur: ["ڈارک", "تھیم"] },
  { id: "appearance.sidebar", title: "Sidebar style", page: "appearance", kind: "segmented", resettable: true,
    options: opts(["light", "Light", true], ["dark", "Dark"], ["grey", "Soft grey"]), keywords: ["sidebar colour", "menu"] },
  { id: "appearance.density", title: "Density", page: "appearance", kind: "segmented", resettable: true,
    options: opts(["comfortable", "Comfortable", true], ["compact", "Compact"]), keywords: ["spacing", "compact", "dense"] },
  { id: "appearance.clock", title: "Times", page: "appearance", kind: "segmented", resettable: true,
    options: opts(["12", "12-hour", true], ["24", "24-hour"]), keywords: ["clock", "time format", "am pm"], ru: ["waqt", "ghanta"], ur: ["وقت"] },

  { id: "notifications", title: "Notifications", page: null, root: "App", kind: "page", keywords: ["alerts", "push", "notification"], ru: ["ittila", "ittilaat", "alert", "ghanti"], ur: ["اطلاع", "اطلاعات"] },
  { id: "notifications.enabled", title: "Show notifications", page: "notifications", kind: "switch", resettable: true, keywords: ["turn off notifications", "mute", "silence"], ru: ["band karo"] },
  { id: "notifications.sounds", title: "Play sounds", page: "notifications", kind: "switch", resettable: true, keywords: ["sound", "silent", "chime"], ru: ["awaz"], ur: ["آواز"] },
  { id: "notifications.quiet", title: "Quiet hours", page: "notifications", kind: "switch", resettable: true, keywords: ["do not disturb", "dnd", "night", "sleep", "silent"], ru: ["khamosh", "neend", "raat"], ur: ["خاموش"] },
  { id: "notifications.quiet_from", title: "From", page: "notifications", kind: "choice", resettable: true, keywords: ["start time"] },
  { id: "notifications.quiet_to", title: "To", page: "notifications", kind: "choice", resettable: true, keywords: ["end time"] },
  ...NOTIFICATION_KINDS.map(([key, title, description]): SettingEntry => ({ id: `notifications.kind.${key}`, title, page: "notifications", section: "Notify me about", kind: "switch", resettable: true,
    description, keywords: ["vibration", "sound", "test"], ru: ["awaz"] })),
  { id: "notifications.test", title: "Send a test notification", page: "notifications", kind: "action", keywords: ["test"] },

  { id: "lock", title: "App lock", page: null, root: "App", kind: "page", keywords: ["lock", "fingerprint", "face unlock", "pin", "password", "security"], ru: ["tala", "taala", "angootha", "chehra"], ur: ["تالا", "پاس ورڈ"] },
  { id: "lock.enabled", title: "App lock", page: "lock", kind: "switch", resettable: true, keywords: ["lock app", "lock echoledger", "biometric", "touch id", "windows hello"], ru: ["tala lagao"] },
  { id: "lock.after", title: "Lock after", page: "lock", kind: "choice", resettable: true,
    options: opts(["1", "After 1 minute"], ["5", "After 5 minutes", true], ["15", "After 15 minutes"], ["60", "After 1 hour"], ["0", "Only when the app starts"]),
    keywords: ["timeout", "auto lock", "lock when not used"] },
  { id: "lock.pin", title: "PIN", page: "lock", kind: "action", keywords: ["pin code", "passcode", "unlock"] },
  { id: "lock.now", title: "Lock now", page: "lock", kind: "action", keywords: ["lock immediately"] },

  { id: "storage", title: "Storage", page: null, root: "App", kind: "page", keywords: ["space", "storage", "disk", "full", "gb"], ru: ["jagah", "memory"], ur: ["جگہ", "اسٹوریج"] },
  { id: "storage.used", title: "Your storage", page: "storage", kind: "info", keywords: ["used space", "quota"] },
  { id: "storage.count", title: "Recordings", page: "storage", kind: "info", keywords: ["number of recordings"] },
  { id: "storage.retention", title: "Recordings kept", page: "storage", kind: "info", keywords: ["retention", "auto delete", "how long"], ru: ["kitne din"] },
  { id: "storage.bin", title: "Bin", page: "storage", kind: "info", keywords: ["trash", "deleted", "restore"], ru: ["kachra", "mitaya hua"] },

  { id: "shortcuts", title: "Keyboard shortcuts", page: null, root: "App", kind: "page", keywords: ["hotkeys", "keys", "keyboard"] },
  ...SHORTCUT_ACTIONS.map(([action, title]): SettingEntry => ({ id: `shortcuts.global.${action}`, title, page: "shortcuts", section: "Global shortcuts", kind: "action", resettable: true, keywords: ["shortcut", "hotkey"] })),
  { id: "shortcuts.app", title: "In the app", page: "shortcuts", section: "In the app", kind: "list", keywords: ["keys", "search", "play pause", "find"] },
  { id: "app.autostart", title: "Start when I sign in", page: null, root: "App", kind: "switch", resettable: true, keywords: ["launch at login", "startup", "autostart", "open at login"] },

  // ---- Account ------------------------------------------------------------------------------------------------------
  { id: "profile", title: "Profile", page: null, root: "Account", kind: "page", requires: { account: true }, keywords: ["name", "email", "account", "me"], ru: ["naam", "mera account"], ur: ["پروفائل", "نام"] },
  { id: "profile.name", title: "Name", page: "profile", kind: "info", requires: { account: true }, keywords: ["change name"], ru: ["naam"], ur: ["نام"] },
  { id: "profile.email", title: "Email", page: "profile", kind: "info", requires: { account: true }, keywords: ["change email"], ru: ["email"], ur: ["ای میل"] },
  { id: "profile.workspace", title: "Workspace", page: "profile", kind: "info", requires: { account: true }, keywords: ["team", "role"], ru: ["team"], ur: ["ٹیم"] },
  { id: "profile.since", title: "Member since", page: "profile", kind: "info", requires: { account: true }, keywords: ["joined"] },
  { id: "profile.edit", title: "Edit profile", page: "profile", kind: "link", requires: { account: true }, keywords: ["edit", "change"] },

  { id: "plan", title: "Plan & usage", page: null, root: "Account", kind: "page", requires: { plans: true },
    keywords: ["plan", "package", "subscription", "billing", "usage", "limits", "minutes"], ru: ["package", "pakage", "plan", "qeemat", "keemat"], ur: ["پلان", "پیکج"] },
  { id: "plan.current", title: "Current plan", page: "plan", kind: "info", requires: { plans: true }, keywords: ["free", "pro", "ultra", "my plan"], ru: ["mera plan"] },
  { id: "plan.upgrade", title: "Upgrade", page: "plan", kind: "action", requires: { plans: true },
    keywords: ["upgrade", "buy", "premium", "pro", "ultra", "price", "pricing", "subscribe"], ru: ["khareedo", "upgrade"], ur: ["اپ گریڈ", "خریدیں"] },
  { id: "plan.usage.minutes", title: "Transcription", page: "plan", section: "This month", kind: "info", requires: { plans: true }, aliases: ["Transcription minutes"], keywords: ["minutes left", "limit", "usage", "quota"], ru: ["minute", "had"], ur: ["منٹ"] },
  { id: "plan.usage.storage", title: "Storage", page: "plan", section: "This month", kind: "info", requires: { plans: true }, keywords: ["storage limit", "gb"], ru: ["jagah"] },
  { id: "plan.usage.devices", title: "Devices", page: "plan", section: "This month", kind: "info", requires: { plans: true }, keywords: ["device limit"] },
  { id: "plan.usage.members", title: "Team members", page: "plan", section: "This month", kind: "info", requires: { plans: true }, keywords: ["seats", "people"], ru: ["log"] },
  { id: "plan.usage.uploads", title: "Uploads in the last 24 hours", page: "plan", section: "This month", kind: "info", requires: { plans: true }, flow: true, keywords: ["uploads", "daily limit"] },
  { id: "plan.included.retention_days", title: "Recordings kept", page: "plan", section: "Included in your plan", kind: "info", requires: { plans: true }, keywords: ["retention", "how long"] },
  { id: "plan.included.max_file_minutes", title: "Longest recording", page: "plan", section: "Included in your plan", kind: "info", requires: { plans: true }, keywords: ["file length", "hours"] },
  { id: "plan.included.uploads_per_day", title: "Uploads a day", page: "plan", section: "Included in your plan", kind: "info", requires: { plans: true }, keywords: ["uploads", "daily limit"] },
  { id: "plan.included.seats", title: "Team members", page: "plan", section: "Included in your plan", kind: "info", requires: { plans: true }, keywords: ["seats", "people"] },
  { id: "plan.compare", title: "See every feature", page: "plan", section: "Included in your plan", kind: "action", requires: { plans: true }, aliases: ["Compare plans"],
    keywords: ["features", "difference", "all features", "what's included"], ru: ["farq"], ur: ["موازنہ"] },
  { id: "plan.promo", title: "Promo code", page: "plan", section: "Payments", kind: "action", requires: { plans: true }, keywords: ["discount", "coupon", "voucher", "offer"], ru: ["discount", "riayat", "code"], ur: ["رعایت", "کوڈ"] },
  { id: "plan.request", title: "Upgrade request", page: "plan", kind: "info", requires: { plans: true }, flow: true, keywords: ["request", "pending", "status", "approval", "waiting"], ru: ["darkhwast", "intezar"], ur: ["درخواست"] },
  { id: "plan.payment_details", title: "Payment details", page: "plan", kind: "action", requires: { plans: true }, flow: true,
    keywords: ["bank", "iban", "account number", "raast", "jazzcash", "easypaisa", "nayapay", "sadapay", "transfer", "pay"], ru: ["bank", "adaigi", "paisay bhejo"], ur: ["بینک", "ادائیگی"] },
  { id: "plan.receipt", title: "Payment receipt", page: "plan", kind: "action", requires: { plans: true }, flow: true,
    keywords: ["screenshot", "proof", "receipt", "transaction id", "tid", "slip"], ru: ["raseed", "rasid", "saboot", "screenshot"], ur: ["رسید", "ثبوت"] },
  { id: "plan.history", title: "Payments", page: "plan", section: "Payments", kind: "list", requires: { plans: true }, flow: true, keywords: ["history", "past payments", "invoice"] },

  { id: "security", title: "Sign-in & security", page: null, root: "Account", kind: "page", requires: { account: true }, keywords: ["security", "sign in", "login", "password", "2fa"], ru: ["hifazat", "login"], ur: ["سیکیورٹی"] },
  { id: "security.password", title: "Password", page: "security", kind: "link", requires: { account: true }, keywords: ["change password", "reset password"], ru: ["password badlo"], ur: ["پاس ورڈ"] },
  { id: "security.two_step", title: "Two-step sign-in", page: "security", kind: "link", requires: { account: true },
    keywords: ["2fa", "two factor", "authenticator", "otp", "mfa", "2 step", "two step", "verification code"] },
  { id: "security.google", title: "Google sign-in", page: "security", kind: "info", requires: { account: true }, keywords: ["google", "gmail", "link google"], ru: ["google"] },
  { id: "security.activity", title: "Sign-in activity", page: "security", kind: "page", requires: { account: true }, keywords: ["audit log", "recent activity", "history"] },
  { id: "security.signout_all", title: "Sign out everywhere", page: "security", kind: "action", requires: { account: true }, keywords: ["log out all devices", "logout everywhere"], ru: ["sab se logout"] },

  { id: "devices", title: "Devices", page: null, root: "Account", kind: "page", keywords: ["phone", "laptop", "computer", "paired", "signed in"], ru: ["mobile", "laptop"], ur: ["ڈیوائس"] },
  { id: "privacy", title: "Privacy & data", page: null, root: "Account", kind: "page", keywords: ["privacy", "data", "gdpr"], ru: ["raazdari"], ur: ["رازداری"] },
  { id: "privacy.consent", title: "Recording consent", page: "privacy", kind: "page", keywords: ["consent", "law", "legal", "permission to record"], ru: ["ijazat", "qanoon"], ur: ["اجازت"] },
  { id: "privacy.export", title: "Export my data", page: "privacy", kind: "action", requires: { account: true }, keywords: ["download my data", "backup", "zip"], ru: ["data download"], ur: ["ڈیٹا"] },
  { id: "privacy.cancel_deletion", title: "Cancel deletion", page: "privacy", kind: "link", requires: { account: true }, keywords: ["keep my account", "undo delete", "stop deletion"] },
  { id: "privacy.delete_account", title: "Delete my account", page: "privacy", kind: "action", requires: { account: true },
    keywords: ["close account", "remove account", "delete account"], ru: ["account khatam", "account delete", "hatao"], ur: ["اکاؤنٹ ختم"] },

  // ---- Support ------------------------------------------------------------------------------------------------------
  { id: "help", title: "Help", page: null, root: "Support", kind: "page", keywords: ["support", "help", "problem", "not working"], ru: ["madad", "masla"], ur: ["مدد"] },
  { id: "help.mac_check", title: "Mac check", page: "help", kind: "page", requires: { os: "macos" }, keywords: ["macos permissions", "diagnostics", "report"] },
  { id: "about", title: "About", page: null, root: "Support", kind: "page", keywords: ["version", "app info"] },
  { id: "about.version", title: "Version", page: "about", kind: "info", keywords: ["version", "build"] },
  { id: "about.updates", title: "Check for updates", page: "about", kind: "action", keywords: ["update", "new version", "download"], ru: ["update", "naya"], ur: ["اپ ڈیٹ"] },
  { id: "about.device_name", title: "Device name", page: "about", kind: "link", keywords: ["computer name", "this computer"] },
  { id: "about.server", title: "Server", page: "about", kind: "info", keywords: ["server", "host"] },
  { id: "about.technical", title: "Technical details", page: "about", kind: "page", keywords: ["api", "certificate", "pinning", "secure connection"] },
  { id: "about.technical.secure", title: "Secure connection", page: "about.technical", kind: "info", keywords: ["certificate", "pinning", "verified"] },
  { id: "about.technical.address", title: "Server address", page: "about.technical", kind: "info", keywords: ["url", "host"] },
  { id: "about.export_settings", title: "Export settings", page: "about", kind: "action", keywords: ["backup settings", "json", "file"] },
  { id: "about.import_settings", title: "Import settings", page: "about", kind: "action", keywords: ["restore settings"] },
  { id: "about.privacy", title: "Privacy policy", page: "about", kind: "link", keywords: ["legal"] },
  { id: "about.terms", title: "Terms", page: "about", kind: "link", keywords: ["legal"] },

  { id: "signout", title: "Sign out", page: null, root: "", kind: "action", keywords: ["log out", "logout", "sign off", "exit", "unpair"], ru: ["logout", "bahar niklo"], ur: ["لاگ آؤٹ"] },
  ...HELP_ARTICLES,
];

const BY_ID = new Map(SETTINGS.map((e) => [e.id, e]));

/** One entry by id (throws for an id that is not in the registry, so a typo fails in tests at once). */
export function setting(id: string): SettingEntry {
  const entry = BY_ID.get(id);
  if (!entry) throw new Error(`No setting "${id}" in the registry`);
  return entry;
}

/** The entry, or none for an id that is not in the registry. */
export function findSetting(id: string): SettingEntry | undefined {
  return BY_ID.get(id);
}

/** The title of a setting: rows read it from here. */
export function titleOf(id: string): string {
  return setting(id).title;
}

/** The root header an entry sits under. */
export function rootOf(entry: SettingEntry): RootSection {
  if (entry.page === null) return entry.root ?? "";
  let current: SettingEntry | undefined = BY_ID.get(entry.page);
  while (current && current.page !== null) current = BY_ID.get(current.page);
  return current?.root ?? "";
}

/** `[root header, page title, sub-page title]`: where the entry lives; a page's own path ends with its title. */
export function pathOf(entry: SettingEntry): string[] {
  const chain: string[] = [];
  let current: SettingEntry | undefined = entry.kind === "page" ? entry : entry.page ? BY_ID.get(entry.page) : undefined;
  while (current) {
    chain.unshift(current.title);
    current = current.page ? BY_ID.get(current.page) : undefined;
  }
  const root = rootOf(entry);
  if (entry.page === null && entry.kind !== "page") chain.push(entry.title);
  return root ? [root, ...chain] : chain;
}

/** How deep an entry is: a root row or root page 1, a row on a page 2, a row on a sub-page 3. */
export function depthOf(entry: SettingEntry): number {
  let depth = 1;
  let current = entry.page ? BY_ID.get(entry.page) : undefined;
  while (current) {
    depth++;
    current = current.page ? BY_ID.get(current.page) : undefined;
  }
  return depth;
}

/** The breadcrumb a search result shows: the path without its last segment when that is the title ("App › Appearance"). */
export function breadcrumbOf(entry: SettingEntry): string {
  const path = pathOf(entry);
  if (path[path.length - 1] === entry.title) path.pop();
  return path.join(" › ");
}

/** The page a result opens: the entry's own page for a page, else the page it sits on (null: the root). */
export function pageFor(entry: SettingEntry): string | null {
  return entry.kind === "page" ? entry.id : entry.page;
}

/** The entries on one page, in registry order (null: the root rows and pages). */
export function entriesOn(page: string | null): SettingEntry[] {
  return SETTINGS.filter((e) => e.page === page && e.kind !== "help");
}

/** An option's label by value. */
export function optionLabel(id: string, value: string): string {
  return setting(id).options?.find((o) => o.value === value)?.label ?? value;
}
