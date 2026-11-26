// Sample content for the Gate 3 screens: Pakistani businesses, real durations, English and Urdu. Fictional people
// and companies; no placeholder copy.
import type { Segment } from "../components/media";

export type Lang = "en" | "ur";

export type RecordingRow = {
  id: string;
  app: string;
  title: string;
  meta: string;
  time: string;
  duration: string;
  day: "today" | "yesterday";
  untranscribed?: boolean;
};

type Copy = {
  nav: { label: string; home: string; library: string; live: string; ask: string; settings: string; ready: string };
  library: {
    title: string; record: string; importFiles: string; search: string; filters: string;
    all: string; today: string; untranscribed: string; voiceNotes: string; yesterday: string;
    untranscribedBadge: string; listLabel: string; toast: string; undo: string; dismiss: string;
    emptyTitle: string; emptyBody: string; clear: string;
  };
  rows: RecordingRow[];
  recording: {
    back: string; title: string; meta: string; status: string; share: string; move: string; remove: string; more: string;
    tabs: { transcript: string; summary: string; ask: string; moments: string; details: string }; tabsLabel: string;
    transcriptLabel: string; speed: string; play: string; pause: string; seek: string; of: string;
    segments: Segment[];
  };
  settings: {
    title: string; search: string; name: string; email: string; plan: string; usage: string; upgrade: string;
    groups: { title: string; rows: SettingRowData[] }[];
  };
};

export type SettingRowData = {
  icon: "record" | "after" | "captions" | "language" | "app-language" | "words" | "private" | "storage" | "lock" | "theme" | "bell" | "health" | "keyboard" | "help";
  title: string;
  value?: string;
  meta?: string;
  toggle?: boolean;
  status?: string;
};

export const COPY: Record<Lang, Copy> = {
  en: {
    nav: { label: "Main", home: "Home", library: "Library", live: "Live", ask: "Ask", settings: "Settings", ready: "Ready to record" },
    library: {
      title: "Library", record: "New recording", importFiles: "Import", search: "Search recordings", filters: "Filter recordings",
      all: "All", today: "Today", untranscribed: "Untranscribed", voiceNotes: "Voice notes", yesterday: "Yesterday",
      untranscribedBadge: "Untranscribed", listLabel: "Recordings", toast: "Voice note moved to Trash", undo: "Undo", dismiss: "Dismiss",
      emptyTitle: "No recordings match", emptyBody: "Try another word, or clear the filters to see every recording.", clear: "Clear filters",
    },
    rows: [
      { id: "r1", app: "whatsapp", title: "Bilal Qureshi · Qureshi Textiles", meta: "Payment terms for the March shipment", time: "11:42 am", duration: "18:24", day: "today" },
      { id: "r2", app: "google_meet", title: "Weekly sales review, Mehran Foods", meta: "Google Meet · 6 people · Karachi and Lahore offices", time: "10:05 am", duration: "46:10", day: "today" },
      { id: "r3", app: "voice_note", title: "Voice note", meta: "Call the customs agent about the Port Qasim container", time: "9:18 am", duration: "2:14", day: "today", untranscribed: true },
      { id: "r4", app: "phone_call", title: "Usman Chaudhry · Chaudhry Steel", meta: "Rate for 12 mm rebar, delivery to Gujranwala", time: "8:47 am", duration: "7:32", day: "today", untranscribed: true },
      { id: "r5", app: "zoom", title: "Audit kick-off with Habib & Co.", meta: "Zoom · Quarterly sales tax return and invoices", time: "4:30 pm", duration: "38:02", day: "yesterday" },
      { id: "r6", app: "whatsapp_business", title: "Fatima Malik · Lahore Leather Works", meta: "Sample approval for 300 jackets, FOB price", time: "2:12 pm", duration: "12:51", day: "yesterday" },
      { id: "r7", app: "microsoft_teams", title: "Interview: accounts officer", meta: "Microsoft Teams · Kamran Butt, second round", time: "11:00 am", duration: "32:05", day: "yesterday" },
      { id: "r8", app: "whatsapp", title: "Sadia Hassan · Zamzam Travels", meta: "Umrah group booking for 14 people in February", time: "9:40 am", duration: "5:48", day: "yesterday", untranscribed: true },
    ],
    recording: {
      back: "Library", title: "Payment terms for the March shipment",
      meta: "WhatsApp call with Bilal Qureshi · Qureshi Textiles · Today, 11:42 am", status: "Transcribed",
      share: "Share", move: "Move", remove: "Delete", more: "More actions",
      tabs: { transcript: "Transcript", summary: "Summary", ask: "Ask", moments: "Moments", details: "Details" }, tabsLabel: "Recording",
      transcriptLabel: "Transcript", speed: "Playback speed", play: "Play", pause: "Pause", seek: "Position", of: "of",
      segments: [
        { speaker: "You", start: 0, text: "Assalam o alaikum, Bilal sahib. I wanted to go over the March shipment before we send the proforma." },
        { speaker: "Bilal Qureshi", start: 6, text: "Walaikum assalam. The fabric is ready, forty thousand metres of cotton lawn. Packing finishes next Thursday." },
        { speaker: "You", start: 19, text: "Shall we keep the terms from last time? Thirty percent in advance and the balance against the bill of lading." },
        { speaker: "Bilal Qureshi", start: 31, text: "This time we need forty percent in advance. Yarn has gone up almost twelve percent since January." },
        { speaker: "You", start: 48, text: "All right. I will speak to finance and confirm by this evening." },
        { speaker: "Bilal Qureshi", start: 62, text: "Thank you. Please also send the LC copy on WhatsApp, the bank in Faisalabad is asking for it." },
      ],
    },
    settings: {
      title: "Settings", search: "Search settings", name: "Ayesha Siddiqui", email: "ayesha@qureshitextiles.pk",
      plan: "Free plan", usage: "212 of 300 minutes used this month", upgrade: "Upgrade",
      groups: [
        { title: "Recording", rows: [
          { icon: "record", title: "Auto-record sources", value: "WhatsApp, Meet, Zoom" },
          { icon: "after", title: "After a call", value: "Show the summary" },
          { icon: "captions", title: "Live captions", toggle: true },
        ] },
        { title: "Transcription & language", rows: [
          { icon: "language", title: "Transcript language", value: "Urdu and English" },
          { icon: "app-language", title: "App language", value: "English" },
          { icon: "words", title: "Custom words", value: "24 words" },
        ] },
        { title: "Privacy & data", rows: [
          { icon: "private", title: "Private overlay", meta: "Hidden from screen share", toggle: true },
          { icon: "storage", title: "Storage", value: "3.2 GB of 10 GB" },
          { icon: "lock", title: "App lock", value: "Off" },
        ] },
        { title: "Appearance & notifications", rows: [
          { icon: "theme", title: "Theme", value: "System" },
          { icon: "bell", title: "Notifications", value: "Calls and summaries" },
        ] },
        { title: "Help", rows: [
          { icon: "health", title: "Setup & health", status: "Ready" },
          { icon: "keyboard", title: "Shortcuts" },
          { icon: "help", title: "About & help", value: "Version 2.0.0" },
        ] },
      ],
    },
  },
  ur: {
    nav: { label: "مرکزی", home: "ہوم", library: "لائبریری", live: "لائیو", ask: "پوچھیں", settings: "ترتیبات", ready: "ریکارڈنگ کے لیے تیار" },
    library: {
      title: "لائبریری", record: "نئی ریکارڈنگ", importFiles: "درآمد", search: "ریکارڈنگز تلاش کریں", filters: "ریکارڈنگز فلٹر کریں",
      all: "سب", today: "آج", untranscribed: "ٹرانسکرپٹ باقی", voiceNotes: "وائس نوٹس", yesterday: "کل",
      untranscribedBadge: "ٹرانسکرپٹ باقی", listLabel: "ریکارڈنگز", toast: "وائس نوٹ ردی کی ٹوکری میں منتقل ہو گیا", undo: "واپس لیں", dismiss: "بند کریں",
      emptyTitle: "کوئی ریکارڈنگ نہیں ملی", emptyBody: "کوئی اور لفظ آزمائیں، یا تمام ریکارڈنگز دیکھنے کے لیے فلٹر ہٹا دیں۔", clear: "فلٹر ہٹائیں",
    },
    rows: [
      { id: "r1", app: "whatsapp", title: "بلال قریشی · قریشی ٹیکسٹائلز", meta: "مارچ کی شپمنٹ کے لیے ادائیگی کی شرائط", time: "صبح 11:42", duration: "18:24", day: "today" },
      { id: "r2", app: "google_meet", title: "ہفتہ وار سیلز جائزہ، مہران فوڈز", meta: "Google Meet · 6 افراد · کراچی اور لاہور دفاتر", time: "صبح 10:05", duration: "46:10", day: "today" },
      { id: "r3", app: "voice_note", title: "وائس نوٹ", meta: "پورٹ قاسم کنٹینر کے بارے میں کسٹمز ایجنٹ کو کال کریں", time: "صبح 9:18", duration: "2:14", day: "today", untranscribed: true },
      { id: "r4", app: "phone_call", title: "عثمان چوہدری · چوہدری اسٹیل", meta: "12 ملی میٹر سریے کا ریٹ، گوجرانوالہ ڈیلیوری", time: "صبح 8:47", duration: "7:32", day: "today", untranscribed: true },
      { id: "r5", app: "zoom", title: "حبیب اینڈ کمپنی کے ساتھ آڈٹ کا آغاز", meta: "Zoom · سہ ماہی سیلز ٹیکس ریٹرن اور انوائسز", time: "شام 4:30", duration: "38:02", day: "yesterday" },
      { id: "r6", app: "whatsapp_business", title: "فاطمہ ملک · لاہور لیدر ورکس", meta: "300 جیکٹوں کے نمونے کی منظوری، ایف او بی قیمت", time: "دوپہر 2:12", duration: "12:51", day: "yesterday" },
      { id: "r7", app: "microsoft_teams", title: "انٹرویو: اکاؤنٹس آفیسر", meta: "Microsoft Teams · کامران بٹ، دوسرا راؤنڈ", time: "صبح 11:00", duration: "32:05", day: "yesterday" },
      { id: "r8", app: "whatsapp", title: "سعدیہ حسن · زمزم ٹریولز", meta: "فروری میں 14 افراد کے عمرہ گروپ کی بکنگ", time: "صبح 9:40", duration: "5:48", day: "yesterday", untranscribed: true },
    ],
    recording: {
      back: "لائبریری", title: "مارچ کی شپمنٹ کے لیے ادائیگی کی شرائط",
      meta: "بلال قریشی کے ساتھ WhatsApp کال · قریشی ٹیکسٹائلز · آج، صبح 11:42", status: "ٹرانسکرپٹ تیار",
      share: "شیئر کریں", move: "منتقل کریں", remove: "حذف کریں", more: "مزید اختیارات",
      tabs: { transcript: "ٹرانسکرپٹ", summary: "خلاصہ", ask: "پوچھیں", moments: "اہم لمحات", details: "تفصیلات" }, tabsLabel: "ریکارڈنگ",
      transcriptLabel: "ٹرانسکرپٹ", speed: "چلانے کی رفتار", play: "چلائیں", pause: "روکیں", seek: "مقام", of: "میں سے",
      segments: [
        { speaker: "آپ", start: 0, text: "السلام علیکم بلال صاحب، پروفارما بھیجنے سے پہلے مارچ والی شپمنٹ پر بات کرنی تھی۔" },
        { speaker: "بلال قریشی", start: 6, text: "وعلیکم السلام۔ جی، کپڑا تیار ہے، چالیس ہزار میٹر کاٹن لان۔ پیکنگ اگلی جمعرات تک مکمل ہو جائے گی۔" },
        { speaker: "آپ", start: 19, text: "ادائیگی کی شرائط پچھلی بار کی طرح رکھیں؟ تیس فیصد ایڈوانس اور باقی بل آف لیڈنگ پر؟" },
        { speaker: "بلال قریشی", start: 31, text: "اس دفعہ ہمیں چالیس فیصد ایڈوانس چاہیے، جنوری سے سوت تقریباً بارہ فیصد مہنگا ہو گیا ہے۔" },
        { speaker: "آپ", start: 48, text: "ٹھیک ہے، میں فنانس سے بات کر کے آج شام تک کنفرم کر دیتی ہوں۔" },
        { speaker: "بلال قریشی", start: 62, text: "بہت شکریہ۔ ایل سی کی کاپی بھی واٹس ایپ کر دیجیے گا، فیصل آباد والا بینک مانگ رہا ہے۔" },
      ],
    },
    settings: {
      title: "ترتیبات", search: "ترتیبات تلاش کریں", name: "عائشہ صدیقی", email: "ayesha@qureshitextiles.pk",
      plan: "Free پلان", usage: "اس مہینے 300 میں سے 212 منٹ استعمال ہوئے", upgrade: "اپ گریڈ کریں",
      groups: [
        { title: "ریکارڈنگ", rows: [
          { icon: "record", title: "خودکار ریکارڈنگ کے ذرائع", value: "WhatsApp، Meet، Zoom" },
          { icon: "after", title: "کال کے بعد", value: "خلاصہ دکھائیں" },
          { icon: "captions", title: "لائیو کیپشنز", toggle: true },
        ] },
        { title: "ٹرانسکرپشن اور زبان", rows: [
          { icon: "language", title: "ٹرانسکرپٹ کی زبان", value: "اردو اور انگریزی" },
          { icon: "app-language", title: "ایپ کی زبان", value: "اردو" },
          { icon: "words", title: "مخصوص الفاظ", value: "24 الفاظ" },
        ] },
        { title: "پرائیویسی اور ڈیٹا", rows: [
          { icon: "private", title: "پرائیویٹ اوورلے", meta: "اسکرین شیئر میں نظر نہیں آتا", toggle: true },
          { icon: "storage", title: "اسٹوریج", value: "10 جی بی میں سے 3.2 جی بی" },
          { icon: "lock", title: "ایپ لاک", value: "بند" },
        ] },
        { title: "ظاہری شکل اور اطلاعات", rows: [
          { icon: "theme", title: "تھیم", value: "سسٹم" },
          { icon: "bell", title: "اطلاعات", value: "کالز اور خلاصے" },
        ] },
        { title: "مدد", rows: [
          { icon: "health", title: "سیٹ اپ اور حالت", status: "تیار" },
          { icon: "keyboard", title: "شارٹ کٹس" },
          { icon: "help", title: "مدد اور تعارف", value: "ورژن 2.0.0" },
        ] },
      ],
    },
  },
};
