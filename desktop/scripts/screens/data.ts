// Sample data for the screenshot fixture: the phone's design screens use the same people and calls, so the desktop
// and the phone pictures can be compared side by side.
import type { ScreenMoment, ActivityGroup, ActivityItem, CallNotes, ClientFolder, ClientWorkspace, FilingRule, LibraryItem, LibraryViews, PendingItem, RequestLink, Segment, ShareLink, SharedItem, SkippedItem, TranscriptDetail } from "../../src/lib/ipc";
import { makeItem, makeScreen, makeTranscript } from "../../src/test/fixtures";

const DAY = 86_400_000;
const at = (daysAgo: number, hour: number, minute = 0): string => {
  const d = new Date(Date.now() - daysAgo * DAY);
  d.setHours(hour, minute, 0, 0);
  return d.toISOString();
};
const client = (id: string, name: string) => ({ id, name, color: null, avatar: null });
const ACME_PATH = [{ id: "c-acme", name: "Acme Holdings" }];

// v1.5: projects with subfolders (Acme Holdings › Invoices, Contracts).
export const CLIENTS: ClientFolder[] = [
  { id: "c-acme", name: "Acme Holdings", session_count: 2, ai_notes: true, pinned: true, parent_id: null, path: [], children_count: 2, nested_session_count: 4 },
  { id: "c-acme-inv", name: "Invoices", session_count: 1, ai_notes: true, parent_id: "c-acme", path: ACME_PATH, children_count: 0, nested_session_count: 1 },
  { id: "c-acme-con", name: "Contracts", session_count: 1, ai_notes: true, parent_id: "c-acme", path: ACME_PATH, children_count: 0, nested_session_count: 1 },
  { id: "c-north", name: "Northwind", session_count: 1, ai_notes: true, pinned: true, parent_id: null, path: [], children_count: 0, nested_session_count: 1 },
  { id: "c-aria", name: "Aria Studio", session_count: 1, ai_notes: false, parent_id: null, path: [], children_count: 0, nested_session_count: 1 },
  { id: "c-zed", name: "Zed Logistics", session_count: 0, ai_notes: true, parent_id: null, path: [], children_count: 0, nested_session_count: 0 },
];

const ready = (id: string, preview: string) => ({
  final_status: "completed", final_transcript_id: `t-${id}`, live_available: false, preview, final_language: "en", final_provider: "assemblyai",
  final_provider_label: "AssemblyAI", final_model: "universal-2",
});

export const ITEMS: LibraryItem[] = [
  makeItem("r1", { title: "Weekly sync with Northwind", source_app: "zoom", source_app_label: "Zoom", source_device: "desktop", source_device_label: "Desktop",
    client: client("c-north", "Northwind"), opened: false, started_at: at(0, 9, 0), duration_ms: 2_530_000, status: "completed", screen_count: 3, transcript: ready("r1", "Let us go over the roadmap for the next quarter.") }),
  // v1.5: an Urdu call a backup engine transcribed ("Backup engine — Re-transcribe?").
  makeItem("r2", { title: "Invoice questions", source_app: "whatsapp", source_app_label: "WhatsApp", source_device: "phone", source_device_label: "Phone", pinned: true,
    client: client("c-aria", "Aria Studio"), started_at: at(0, 7, 10), duration_ms: 742_000, status: "completed", language: "ur", language_chip: "UR",
    transcript: { ...ready("r2", "Assalam o alaikum, invoice ka masla hai."), final_language: "ur" } }),
  makeItem("r3", { title: "Voice note", source_app: "voice_note", source_app_label: "Voice note", source_device: "phone", source_device_label: "Phone",
    started_at: at(0, 8, 12), duration_ms: 65_000, status: "processing", transcript: { final_status: "processing", final_transcript_id: "t-r3", live_available: false, preview: null } }),
  makeItem("r4", { title: "Call with +92 300 1234567", source_app: "whatsapp", source_app_label: "WhatsApp", source_device: "phone", source_device_label: "Phone",
    started_at: at(1, 18, 2), duration_ms: 402_000, status: "failed", transcript: { final_status: "failed", final_transcript_id: "t-r4", live_available: false, preview: null } }),
  makeItem("r5", { title: "Kick-off: Contoso website", source_app: "microsoft_teams", source_app_label: "Microsoft Teams", source_device: "desktop", source_device_label: "Desktop",
    client: client("c-acme", "Acme Holdings"), started_at: at(3, 15, 30), duration_ms: 135_000, status: "completed", language: "en", language_chip: "EN", marker_count: 1, tags: ["Budget"],
    labels: ["Kick-off"], filed_by: "rule", title_auto: true,
    transcript: { ...ready("r5", "Welcome everyone, thanks for joining the kick-off."), script_warning: "latin" } }),
  // v1.5: recordings in Acme's folders.
  makeItem("r8", { title: "March invoice follow-up", source_app: "whatsapp_business", source_app_label: "WhatsApp Business", source_device: "phone", source_device_label: "Phone",
    client: client("c-acme-inv", "Invoices"), important: true, opened: false, started_at: at(2, 10, 20), duration_ms: 318_000, status: "completed", transcript: ready("r8", "The March invoice is still open.") }),
  makeItem("r9", { title: "Contract review", source_app: "zoom", source_app_label: "Zoom", source_device: "desktop", source_device_label: "Desktop",
    client: client("c-acme-con", "Contracts"), started_at: at(4, 14, 0), duration_ms: 1_920_000, status: "completed", transcript: ready("r9", "Clause seven needs a change.") }),
  makeItem("r6", { title: "Budget review with Acme", source_app: "google_meet", source_app_label: "Google Meet", source_device: "web", source_device_label: "Web",
    client: client("c-acme", "Acme Holdings"), started_at: at(5, 11, 0), duration_ms: 1_085_000, status: "completed", labels: ["Invoice"], transcript: ready("r6", "The budget for phase one is fixed.") }),
  makeItem("r7", { title: "Voice note", source_app: "voice_note", source_app_label: "Voice note", source_device: "desktop", source_device_label: "Desktop",
    started_at: at(8, 17, 40), duration_ms: 12_000, status: "completed", transcript: { final_status: "failed", final_transcript_id: null, live_available: false, preview: null, final_error_code: "no_audio" } }),
  // Titles in Urdu and in Urdu with English words, long enough to be cut: each is cut at its own end.
  makeItem("r10", { title: "ادائیگی کا منصوبہ، قرض کی واپسی اور اگلے مہینے کی قسطوں پر تفصیلی بات چیت", source_app: "whatsapp", source_app_label: "WhatsApp",
    source_device: "phone", source_device_label: "Phone", started_at: at(12, 16, 5), duration_ms: 742_000, status: "completed", transcript: ready("r10", "السلام علیکم، قسطوں کے بارے میں بات کرتے ہیں۔") }),
  makeItem("r11", { title: "Payment plan میں loan کی واپسی اور next month ki installments", source_app: "phone_call", source_app_label: "Phone call",
    source_device: "phone", source_device_label: "Phone", started_at: at(13, 11, 20), duration_ms: 318_000, status: "completed", transcript: ready("r11", "Payment plan ke baare mein baat karte hain.") }),
];

const seg = (channel: number, speaker: string, startS: number, endS: number, text: string, extra: Partial<Segment> = {}): Segment =>
  ({ channel, speaker, start_ms: startS * 1000, end_ms: endS * 1000, text, ...extra });

const SEGMENTS: Segment[] = [
  seg(1, "Me", 0, 9, "Welcome everyone, thanks for joining the kick-off for the Contoso website."),
  seg(2, "Client", 11, 24, "Thanks for having us. We want the new shop live before the holiday sales."),
  seg(2, "Client", 24, 33, "Login, a product catalogue and Stripe payments are the must-haves."),
  seg(1, "Me", 35, 47, "We will build it on la ravel with a Vue front end, so the team can keep it running."),
  seg(2, "Client", 49, 62, "Sounds good. Ali owns the content, and Sara signs off on the design."),
  seg(1, "Me", 64, 80, "Then let us agree on November 15 for the launch and a review every Friday.", {
    words: [
      { text: "Then", start_ms: 64_000, end_ms: 64_400, confidence: 0.98 }, { text: "let", start_ms: 64_400, end_ms: 64_600, confidence: 0.97 },
      { text: "us", start_ms: 64_600, end_ms: 64_800, confidence: 0.97 }, { text: "agree", start_ms: 64_800, end_ms: 65_300, confidence: 0.95 },
      { text: "on", start_ms: 65_300, end_ms: 65_500, confidence: 0.96 }, { text: "November", start_ms: 65_500, end_ms: 66_200, confidence: 0.93 },
      { text: "15", start_ms: 66_200, end_ms: 66_700, confidence: 0.41 }, { text: "for", start_ms: 66_700, end_ms: 66_900, confidence: 0.96 },
      { text: "the", start_ms: 66_900, end_ms: 67_000, confidence: 0.97 }, { text: "launch", start_ms: 67_000, end_ms: 67_600, confidence: 0.95 },
      { text: "and", start_ms: 67_600, end_ms: 67_800, confidence: 0.96 }, { text: "a", start_ms: 67_800, end_ms: 67_900, confidence: 0.94 },
      { text: "review", start_ms: 67_900, end_ms: 68_500, confidence: 0.95 }, { text: "every", start_ms: 68_500, end_ms: 68_900, confidence: 0.96 },
      { text: "Friday.", start_ms: 68_900, end_ms: 69_600, confidence: 0.97 },
    ],
  }),
  seg(2, "Client", 82, 95, "Perfect. Can you send the estimate for phase one by Monday?"),
  seg(1, "Me", 97, 108, "Yes, I will send it on Monday with the timeline."),
  seg(2, "Client", 110, 121, "One change: we want guest checkout as well, not only accounts."),
  seg(1, "Me", 123, 131, "Noted. I will add guest checkout to the scope."),
];

export const TRANSCRIPTS: Record<string, TranscriptDetail> = {
  // The failed call: the server's message names its provider; the page says why in its own words (D-130).
  "t-r4": makeTranscript({ id: "t-r4", kind: "final", provider: "gemini", status: "failed", segments: [], error: "Gemini could not read the audio.", error_code: "provider_rejected" }),
  "t-r2": makeTranscript({
    id: "t-r2", kind: "final", provider: "assemblyai", model: "universal-2", status: "completed", language: "ur", backup_engine: true, backup_reason: "waited",
    segments: [
      seg(2, "Client", 1, 6, "السلام علیکم، invoice کا تھوڑا سا مسئلہ ہے۔"),
      seg(1, "Me", 7, 12, "جی بتائیں، کون سی invoice؟"),
      seg(2, "Client", 13, 20, "دوسری والی، اس میں payment terms غلط لکھے ہیں۔"),
    ],
  }),
  "t-r5": makeTranscript({
    id: "t-r5", kind: "final", provider: "assemblyai", model: "universal-2", status: "completed", language: "en", segments: SEGMENTS,
    views: { available: ["roman", "urdu"], status: "ready", default: "original" },
    suggestions: [{ id: 0, index: 3, from: "la ravel", to: "Laravel", reason: "the PHP framework in the brief", status: "open" }],
  }),
};
export const VIEWS = {
  status: "ready", available: ["roman", "urdu"], model: "claude-sonnet", made_at: at(3, 16), error: null, english: null,
  roman: SEGMENTS.map((s) => s.text),
  urdu: [
    "سب کو خوش آمدید، کونٹوسو ویب سائٹ کے آغاز میں شامل ہونے کا شکریہ۔", "ہمیں بلانے کا شکریہ۔ ہم چاہتے ہیں کہ نئی دکان چھٹیوں کی سیل سے پہلے چل جائے۔",
    "لاگ اِن، مصنوعات کی فہرست اور Stripe ادائیگیاں لازمی ہیں۔", "ہم اسے Laravel اور Vue پر بنائیں گے تاکہ ٹیم اسے چلا سکے۔",
    "ٹھیک ہے۔ علی مواد کے ذمہ دار ہیں اور سارہ ڈیزائن کی منظوری دیں گی۔", "تو لانچ کے لیے 15 نومبر اور ہر جمعہ کو جائزہ طے کر لیتے ہیں۔",
    "بہت خوب۔ کیا آپ پیر تک پہلے مرحلے کا تخمینہ بھیج سکتے ہیں؟", "جی، میں پیر کو ٹائم لائن کے ساتھ بھیج دوں گا۔",
    "ایک تبدیلی: ہمیں صرف اکاؤنٹس نہیں، مہمان چیک آؤٹ بھی چاہیے۔", "نوٹ کر لیا۔ میں مہمان چیک آؤٹ کو دائرہ کار میں شامل کر دوں گا۔",
  ],
};

export const NOTES_R5: CallNotes = {
  id: "n-r5", session_id: "r5", status: "ready", error: null, language: "en", created_at: at(3, 16, 10),
  session: { id: "r5", title: "Kick-off: Contoso website", started_at: at(3, 15, 30), duration_ms: 135_000 },
  sections: [
    { key: "asked_for", heading: "Asked for", items: [{ text: "Login, a product catalogue and Stripe payments", verify: false, lines: [2], at_ms: 24_000 }] },
    { key: "changes", heading: "Changes", items: [{ text: "Guest checkout as well as accounts", verify: false, lines: [8], at_ms: 110_000, kind: "add" }] },
    { key: "decisions", heading: "Decisions", items: [
      { text: "Launch on November 15", verify: true, lines: [5], at_ms: 64_000 },
      { text: "Review every Friday", verify: false, lines: [5], at_ms: 64_000 },
    ] },
    { key: "my_actions", heading: "My action items", items: [{ text: "Send the phase one estimate with the timeline by Monday", verify: false, lines: [7], at_ms: 97_000 }] },
  ],
};
const NOTES_R6: CallNotes = {
  id: "n-r6", session_id: "r6", status: "ready", error: null, language: "en", created_at: at(5, 12),
  session: { id: "r6", title: "Budget review with Acme", started_at: at(5, 11, 0), duration_ms: 1_085_000 },
  sections: [{ key: "numbers", heading: "Deadlines, prices, numbers", items: [{ text: "Phase one budget fixed at $12,000", verify: false, lines: [0], at_ms: 3_000 }] }],
};

export const WORKSPACE: ClientWorkspace = {
  client: { id: "c-acme", name: "Acme Holdings", ai_notes: true },
  ai: { configured: true, spent_usd: 0.42, budget_usd: 5, client_spent_usd: 0.12 },
  brief: {
    sections: {
      goal: "An online shop for Contoso, live before the holiday sales.",
      scope: ["Login and accounts", "Product catalogue", "Stripe payments", "Guest checkout"],
      constraints: ["Built on Laravel and Vue"], deadlines: ["Launch on November 15", "Estimate by Monday"], budget: ["Phase one: $12,000"],
      people: ["Ali (content owner)", "Sara (design sign-off)"], glossary: ["Laravel: the PHP framework"],
    },
    edited: false, status: "ready", error: null, generated_at: at(3, 16, 12), updated_at: at(3, 16, 12),
  },
  memory: [
    { id: "m1", kind: "decision", text: "Launch on November 15", happened_on: at(3, 12).slice(0, 10), pinned: true, source: { type: "call", id: "r5", at_ms: 64_000 } },
    { id: "m2", kind: "decision", text: "Review every Friday", happened_on: at(3, 12).slice(0, 10), pinned: false, source: { type: "call", id: "r5", at_ms: 64_000 } },
    { id: "m3", kind: "question", text: "Who writes the product descriptions?", happened_on: at(3, 12).slice(0, 10), pinned: false, source: { type: "user" } },
    { id: "m4", kind: "change", text: "Guest checkout added to the scope", happened_on: at(3, 12).slice(0, 10), pinned: false, source: { type: "call", id: "r5", at_ms: 110_000 } },
    { id: "m5", kind: "scope", text: "Stripe is the only payment provider for now", happened_on: at(5, 12).slice(0, 10), pinned: false, source: { type: "file", id: "f1" } },
  ],
  notes: [NOTES_R5, NOTES_R6],
  files: [
    { id: "f3", name: "brand-guide.docx", mime: "application/vnd.openxmlformats-officedocument.wordprocessingml.document", size_bytes: 482_100, pages: null, status: "extracting", error: null, instructions: false, created_at: at(0, 9) },
    { id: "f1", name: "scope.pdf", mime: "application/pdf", size_bytes: 182_340, pages: 4, status: "ready", error: null, instructions: false, created_at: at(4, 10) },
    { id: "f2", name: "House rules", mime: "text/plain", size_bytes: 1_204, pages: null, status: "ready", error: null, instructions: true, created_at: at(4, 10) },
  ],
};

export const ANSWER = {
  answer: "The launch is on November 15. You agreed it in the kick-off call, and the scope document lists it as the go-live date. The estimate for phase one is due on Monday.",
  citations: [
    { type: "call", session_id: "r5", title: "Kick-off: Contoso website", at_ms: 64_000, quote: "let us agree on November 15 for the launch" },
    { type: "file", file_id: "f1", title: "scope.pdf", page: 2, quote: "Go-live: November 15" },
  ],
};

export const PENDING: PendingItem[] = [
  { id: "p1", sourceApp: "whatsapp", auto: true, mode: "call", title: null, clientId: null, clientName: null, startedAt: at(0, 9, 0), endedAt: at(0, 9, 2), durationMs: 125_000, firstLine: "Assalam o alaikum, kya aap ne invoice dekha?", deadlineAt: null, language: null },
  { id: "p2", sourceApp: "zoom", auto: true, mode: "call", title: "Design check-in", clientId: "c-acme", clientName: "Acme Holdings", startedAt: at(1, 18, 30), endedAt: at(1, 18, 31), durationMs: 36_000, firstLine: null, deadlineAt: null, language: "en" },
];

export const ACTIVITY: ActivityItem[] = [
  { id: "a1", kind: "transcript_failed", title: "Transcription failed", body: "Call with +92 300 1234567 · open it to retry", session_id: "r4", read: false, at: at(0, 6, 2) },
  { id: "a2", kind: "transcript_ready", title: "Transcript ready", body: "Weekly sync with Northwind", session_id: "r1", read: false, at: at(0, 9, 40) },
  { id: "a3", kind: "transcript_ready", title: "Transcript ready", body: "Invoice questions", session_id: "r2", read: true, at: at(2, 8) },
  { id: "a4", kind: "device_paired", title: "New device", body: "Pixel 9 was paired", session_id: null, read: true, at: at(5, 10) },
];

/**
 * `&actors=1` (contract 2.0.0-aa, decision 27): items about a person. The kinds that show a face: a teammate with a
 * picture (PERSON_PHOTO) who sent a recording, and one without who asks to upgrade, in their colour; a receipt asked
 * for keeps its tile, whoever it is about.
 */
export const ACTIVITY_PEOPLE: ActivityItem[] = [
  { id: "p1", kind: "shared_with_you", title: "Ayesha Khan sent you Budget review with Acme", body: "Please check the numbers before Monday", session_id: "r10", read: false, at: at(0, 11, 5),
    actor: { id: 12, name: "Ayesha Khan", avatar_url: "https://ledger.example.com/api/avatars/12/1763700000?signature=fixture", avatar_version: 1763700000, avatar_color: 1 } },
  { id: "p2", kind: "upgrade_requested", title: "Bilal Qureshi asked to upgrade to Pro", body: "A workspace member's request", session_id: null, read: false, at: at(0, 10, 50),
    actor: { id: 7, name: "Bilal Qureshi", avatar_url: null, avatar_version: null, avatar_color: 4 } },
  { id: "p3", kind: "upgrade_receipt_requested", title: "Sara Ahmed asked for your receipt", body: "Send it from Plan to finish the upgrade", session_id: null, read: false, at: at(0, 10, 40),
    actor: { id: 9, name: "Sara Ahmed", avatar_url: null, avatar_version: null, avatar_color: 2 } },
];
/** The teammate's picture in the fixture: a drawn portrait, as the core hands the window a data: URL. */
export const PERSON_PHOTO = `data:image/svg+xml;utf8,${encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#c7a17a"/><circle cx="32" cy="26" r="12" fill="#f1d3b5"/><path d="M12 64c2-14 10-20 20-20s18 6 20 20z" fill="#3b5b7a"/><path d="M19 24c0-10 6-15 13-15s13 5 13 15c-3-5-8-7-13-7s-10 2-13 7z" fill="#2b1d14"/></svg>')}`;

/**
 * `&grouped=1` (UX-08, contract 2.0.0-r): three uploads that didn't finish (one of them twice), one that went up after all
 * ("Uploaded", resolved), the group the server makes of them and its one Activity number.
 */
export const UPLOADS_FAILED: ActivityItem[] = [
  { id: "g1", kind: "upload_failed", title: "Upload didn't finish", body: "WhatsApp call with Omar Khan", session_id: "r4", read: false, at: at(0, 10, 12), group: "upload_failed", resolved: false, repeat_count: 2 },
  { id: "g2", kind: "upload_failed", title: "Upload didn't finish", body: "Voice note", session_id: "r3", read: false, at: at(0, 9, 55), group: "upload_failed", resolved: false, repeat_count: 1 },
  { id: "g3", kind: "upload_failed", title: "Upload didn't finish", body: "Zoom call · Acme kick-off", session_id: "r2", read: true, at: at(0, 8, 20), group: "upload_failed", resolved: false, repeat_count: 1 },
];
export const ACTIVITY_GROUPED: ActivityItem[] = [
  ...UPLOADS_FAILED,
  ...ACTIVITY,
  { id: "g4", kind: "upload_failed", title: "Uploaded", body: "Call with Sara", session_id: "r1", read: true, at: at(1, 18, 5), group: "upload_failed", resolved: true, repeat_count: 1 },
];
export const ACTIVITY_GROUPS: ActivityGroup[] = [
  { group: "upload_failed", count: 3, unread: 2, session_ids: ["r4", "r3", "r2"], at: at(0, 10, 12) },
];

/** Activity's "Skipped 3 short calls" (v1.5): restorable for 24 hours. */
export const SKIPPED: SkippedItem[] = [
  { id: "s1", sourceApp: "whatsapp", title: null, callerName: null, startedAt: at(0, 10, 4), durationMs: 6_000, reason: "too_short", expiresAt: at(-1, 10, 4) },
  { id: "s2", sourceApp: "zoom", title: null, callerName: "Omar Khan", startedAt: at(0, 9, 41), durationMs: 95_000, reason: "no_speech", expiresAt: at(-1, 9, 42) },
  { id: "s3", sourceApp: "microsoft_teams", title: "Stand-up", callerName: null, startedAt: at(0, 8, 30), durationMs: 4_000, reason: "too_short", expiresAt: at(-1, 8, 30) },
];

/** v1.5: the smart folders' counts. */
export const LIBRARY_VIEWS: LibraryViews = {
  views: { today: 3, this_week: 6, waiting: 0, bookmarked: 1, failed: 1, pinned: 1 },
  labels: [{ label: "Invoice", count: 1 }, { label: "Kick-off", count: 1 }],
  folders: { inbox: 3, whatsapp: 2, whatsapp_business: 1, google_meet: 1, zoom: 2, teams: 1, phone: 0, voice_notes: 2, imported: 1, shared: 2 },
};

/** Acme's filing rules (v1.5). */
export const RULES: Record<string, FilingRule[]> = {
  "c-acme": [
    { id: 1, kind: "title", value: "Contoso", created_at: at(9, 10) },
    { id: 2, kind: "app", value: "microsoft_teams", created_at: at(9, 10) },
    { id: 3, kind: "contact", value: "sara@contoso.com", created_at: at(6, 12) },
  ],
};

/** Share links of the Contoso kick-off (v1.5). */
export const SHARES: ShareLink[] = [
  { id: "sh2", session_id: "r5", has_password: false, include_audio: true, expires_at: at(-6, 23, 59), revoked_at: null, active: true, view_count: 3, last_viewed_at: at(1, 16, 5),
    created_by: "You", created_at: at(2, 11), kind: "session", client_id: null, audience: "people", people: ["sara@contoso.com", "ali@contoso.com"], allow_download: true },
  { id: "sh1", session_id: "r5", has_password: true, include_audio: false, expires_at: null, revoked_at: at(2, 9), active: false, view_count: 0, last_viewed_at: null,
    created_by: "You", created_at: at(3, 16), kind: "session", client_id: null, audience: "password", people: [], allow_download: false },
];

/** What teammates sent (v1.5). */
export const SHARED: SharedItem[] = [
  { id: "sw1", kind: "session", from: { name: "Imran", email: "imran@example.com" }, note: "For tomorrow's review, the part about the launch date.", title: "Call with Omar", sent_at: at(0, 8, 45), saved_at: null, client: null,
    session: makeItem("x1", { title: "Call with Omar", source_app: "whatsapp", source_app_label: "WhatsApp", source_device: "phone", source_device_label: "Phone", client: client("x-omar", "Omar Traders"),
      started_at: at(1, 17, 20), duration_ms: 486_000, status: "completed", transcript: ready("x1", "Assalam o alaikum Omar bhai.") }) },
  { id: "sw2", kind: "project", from: { name: "Ana", email: "ana@contoso.com" }, note: null, title: "Northwind onboarding", sent_at: at(2, 14), saved_at: null, session: null, client: { id: "x-nw", name: "Northwind onboarding" } },
];

/** Request links (v1.5). */
export const REQUESTS: RequestLink[] = [
  { id: "rq1", title: "Send me your voice notes", client_id: null, expires_at: at(-5, 23, 59), max_files: 20, max_mb: 100, received: 4, revoked_at: null, created_at: at(2, 9) },
  { id: "rq2", title: "Contoso documents", client_id: "c-acme", expires_at: at(-12, 23, 59), max_files: 10, max_mb: 50, received: 1, revoked_at: null, created_at: at(4, 9) },
];

/** Settings → Account (v1.5). */
export const ACCOUNT = {
  name: "Ayesha Khan",
  email: "ayesha@example.test",
  email_verified: true,
  two_factor: true,
  google: true,
  created_at: "2026-11-01T09:00:00+00:00",
  workspace: { id: 1, name: "Acme team", role: "owner" },
  deletes_at: null,
  // v1.6 shell: the server's operator (the avatar menu offers the admin panel) with two workspaces to switch between.
  super_admin: true,
  workspaces: [{ id: 1, name: "Acme team", role: "owner", current: true }, { id: 2, name: "Personal", role: "owner" }],
};

/** The Northwind call's screens (meeting assets, v1.8 P4): the first two with the server's name and what was said. */
export const SCREENS_R1: ScreenMoment[] = [
  makeScreen(1, 12_000, { id: "r1-s1", description: "Roadmap slide, Q1 priorities", ocr_text: "Q1 ROADMAP\n1. Billing page\n2. Team invites\n3. Mobile sign-in",
    file_name: "S01_0-12_roadmap-slide-q1-priorities.webp", said: "Sara (Acme): Let me share the roadmap.\nYou: The billing page comes first?" }),
  makeScreen(2, 95_000, { id: "r1-s2", description: "The billing page, Pay button highlighted", file_name: "S02_1-35_the-billing-page-pay-button-highlighted.webp", said: null }),
  makeScreen(3, 610_000, { id: "r1-s3", description: "Budget table for phase one" }),
];

/** A plain picture for a screen in fixture mode: a grey page with its title, as an SVG data URL. */
export function screenPictureFor(url: string): string {
  const n = /screens\/(\d+)/.exec(url)?.[1] ?? "1";
  const title = SCREENS_R1[Number(n) - 1]?.description ?? "Screen";
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720" viewBox="0 0 1280 720"><rect width="1280" height="720" fill="#F4F4F2"/>`
    + `<rect x="0" y="0" width="1280" height="64" fill="#E4E4E0"/><rect x="64" y="128" width="720" height="40" fill="#C9C9C4"/>`
    + `<rect x="64" y="200" width="1100" height="20" fill="#DCDCD7"/><rect x="64" y="240" width="980" height="20" fill="#DCDCD7"/><rect x="64" y="280" width="1040" height="20" fill="#DCDCD7"/>`
    + `<text x="64" y="420" font-family="sans-serif" font-size="48" fill="#3A3A36">${title}</text></svg>`;
  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}

/** Help (2.0.0-d): a few of the user guide's sections, one to read, and the Windows release notes. */
export const HELP_GUIDES = [
  { id: "after-a-call", title: "After a call", excerpt: "When a call ends, EchoLedger asks what to keep: the transcript, the audio only, or nothing." },
  { id: "record-automatically", title: "Record automatically", excerpt: "Calls in WhatsApp, Google Meet, Zoom and Teams start recording on their own." },
  { id: "live-captions", title: "Live captions", excerpt: "Captions of both sides of a call, as people speak." },
  { id: "screen-moments", title: "Screens from your meetings", excerpt: "What a meeting showed, kept at its moments, with what was said around it." },
];
export const HELP_GUIDE = {
  id: "after-a-call", title: "After a call",
  markdown: "## After a call\n\nWhen a call ends, **EchoLedger** asks what to keep. The card stays on screen until you choose.\n\n"
    + "1. **Transcribe** writes the words and keeps the audio.\n2. **Save audio** keeps only the sound; you can transcribe it later.\n3. **Delete** removes it. You can undo for a few seconds.\n\n"
    + "| Choice | What it keeps |\n|---|---|\n| Transcribe | The audio and the transcript |\n| Save audio | The audio |\n| Delete | Nothing |\n\n"
    + "To skip the question for every call, open Settings › After a call and choose `Transcribe every call`.",
};
export const WHATS_NEW = [
  { version: "2.0.1", platform: "windows", released_at: "2026-11-15T10:00:00Z", notes: "- Screens from a meeting on the recording page, with what was said around each one\n- Help inside the app: guides, what's new and Contact support\n- Undo with Ctrl+Z after deleting a recording" },
  { version: "2.0.0", platform: "windows", released_at: "2026-11-01T10:00:00Z", notes: "The new look: Home, Library, Live and Ask in one sidebar, and Settings you can search." },
];
