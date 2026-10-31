// Sample data for the screenshot fixture: the phone's design screens use the same people and calls, so the desktop
// and the phone pictures can be compared side by side.
import type { ActivityItem, CallNotes, ClientFolder, ClientWorkspace, LibraryItem, PendingItem, Segment, TranscriptDetail } from "../../src/lib/ipc";
import { makeItem, makeTranscript } from "../../src/test/fixtures";

const DAY = 86_400_000;
const at = (daysAgo: number, hour: number, minute = 0): string => {
  const d = new Date(Date.now() - daysAgo * DAY);
  d.setHours(hour, minute, 0, 0);
  return d.toISOString();
};
const client = (id: string, name: string) => ({ id, name, color: null, avatar: null });

export const CLIENTS: ClientFolder[] = [
  { id: "c-acme", name: "Acme Holdings", session_count: 2, ai_notes: true },
  { id: "c-north", name: "Northwind", session_count: 1, ai_notes: true },
  { id: "c-aria", name: "Aria Studio", session_count: 1, ai_notes: false },
  { id: "c-zed", name: "Zed Logistics", session_count: 0, ai_notes: true },
];

const ready = (id: string, preview: string) => ({
  final_status: "completed", final_transcript_id: `t-${id}`, live_available: false, preview, final_language: "en", final_provider: "assemblyai",
  final_provider_label: "AssemblyAI", final_model: "universal-2",
});

export const ITEMS: LibraryItem[] = [
  makeItem("r1", { title: "Weekly sync with Northwind", source_app: "zoom", source_app_label: "Zoom", source_device: "desktop", source_device_label: "Desktop",
    client: client("c-north", "Northwind"), started_at: at(0, 9, 0), duration_ms: 2_530_000, status: "completed", transcript: ready("r1", "Let us go over the roadmap for the next quarter.") }),
  makeItem("r2", { title: "Invoice questions", source_app: "whatsapp", source_app_label: "WhatsApp", source_device: "phone", source_device_label: "Phone", pinned: true,
    client: client("c-aria", "Aria Studio"), started_at: at(0, 7, 10), duration_ms: 742_000, status: "completed", transcript: ready("r2", "Hi, thanks for calling.") }),
  makeItem("r3", { title: "Voice note", source_app: "voice_note", source_app_label: "Voice note", source_device: "phone", source_device_label: "Phone",
    started_at: at(0, 8, 12), duration_ms: 65_000, status: "processing", transcript: { final_status: "processing", final_transcript_id: "t-r3", live_available: false, preview: null } }),
  makeItem("r4", { title: "Call with +92 300 1234567", source_app: "whatsapp", source_app_label: "WhatsApp", source_device: "phone", source_device_label: "Phone",
    started_at: at(1, 18, 2), duration_ms: 402_000, status: "failed", transcript: { final_status: "failed", final_transcript_id: "t-r4", live_available: false, preview: null } }),
  makeItem("r5", { title: "Kick-off: Contoso website", source_app: "microsoft_teams", source_app_label: "Microsoft Teams", source_device: "desktop", source_device_label: "Desktop",
    client: client("c-acme", "Acme Holdings"), started_at: at(3, 15, 30), duration_ms: 135_000, status: "completed", language: "en", language_chip: "EN", marker_count: 1, tags: ["Budget"],
    labels: ["Kick-off"], transcript: ready("r5", "Welcome everyone, thanks for joining the kick-off.") }),
  makeItem("r6", { title: "Budget review with Acme", source_app: "google_meet", source_app_label: "Google Meet", source_device: "web", source_device_label: "Web",
    client: client("c-acme", "Acme Holdings"), started_at: at(5, 11, 0), duration_ms: 1_085_000, status: "completed", labels: ["Invoice"], transcript: ready("r6", "The budget for phase one is fixed.") }),
  makeItem("r7", { title: "Voice note", source_app: "voice_note", source_app_label: "Voice note", source_device: "desktop", source_device_label: "Desktop",
    started_at: at(8, 17, 40), duration_ms: 12_000, status: "completed", transcript: { final_status: "failed", final_transcript_id: null, live_available: false, preview: null, final_error_code: "no_audio" } }),
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
