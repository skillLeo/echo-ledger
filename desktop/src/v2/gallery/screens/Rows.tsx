import { useState } from "react";
import {
  Icons, LABEL_COLOURS, List, ListRow, ListSection, Menu, SourceMark, firstSentence, type RowIndicators, type SourceDevice,
} from "../../components";
import type { Lang } from "../content";

const noop = () => undefined;

/** The before picture's shapes (D12), with invented recordings: mixed Urdu and English, a label-only transcript, a long Urdu title. */
type Sample = {
  id: string; app: string; device: SourceDevice; title: string; preview: string; when: string; duration: string;
  section: "pinned" | "today"; indicators?: RowIndicators;
};

const SAMPLES = (w: Words): Sample[] => [
  {
    id: "checkin", app: "whatsapp", device: "desktop", title: "Check-in call about the March shipment", section: "pinned", when: "15 Nov", duration: "1:19",
    preview: "Client: Hello, can you hear me now? Me: the line. Client: Is the shipment on time? Client: All good? Client: Yeah, can you…",
    indicators: { pinned: true, labels: [{ name: w.labels.acme, colour: LABEL_COLOURS.blue }], words: w.ind },
  },
  {
    id: "supplier", app: "whatsapp", device: "phone", title: "Call with Imran Traders", section: "pinned", when: "15 Nov", duration: "1:13",
    preview: "Me: Thank you. Client: Assalam o alaikum, is this a good time to talk? Client: Is the order ready? Client: Can you se…",
    indicators: { pinned: true, words: w.ind },
  },
  {
    id: "delivery", app: "voice_note", device: "phone", title: "ڈیلیوری کی تاخیر پر شکایت اور نئی تاریخ", section: "pinned", when: "15 Nov", duration: "6:52",
    preview: "Speaker: آرڈر کب تک پہنچ جائے گا یہ بتا دیں Speaker: …",
    indicators: { pinned: true, words: w.ind },
  },
  {
    id: "design", app: "google_meet", device: "desktop", title: "Design - review", section: "pinned", when: "1 Nov", duration: "44:27",
    preview: "Me: ذرا دوبارہ بتائیں، اب ٹھیک ہے continue کریں Client: جی ڈیٹا ساری فائلوں میں ہے…",
    indicators: { pinned: true, important: true, words: w.ind },
  },
  {
    id: "unclear", app: "voice_note", device: "phone", title: "Unclear recording", section: "today", when: "5:09 am", duration: "0:02",
    preview: "Speaker: .", indicators: { unread: true, words: w.ind },
  },
  {
    id: "prices", app: "voice_note", device: "phone", title: "سپلائر کے سوالات اور قیمتوں کی فہرست", section: "today", when: "12:36 am", duration: "3:05",
    preview: "Speaker: پہلا سوال یہ ہے کہ نئی قیمتیں کب سے لاگو ہوں گی۔ Speaker: …",
    indicators: {
      labels: [
        { name: w.labels.invoice, colour: LABEL_COLOURS.amber }, { name: w.labels.supplier, colour: LABEL_COLOURS.teal }, { name: w.labels.lahore, colour: LABEL_COLOURS.green },
      ],
      words: w.ind,
    },
  },
];

const WORDS = {
  en: {
    title: "Library rows", list: "Recordings", pinned: "Pinned", today: "Today", noSpeech: "No speech", more: (t: string) => `More for ${t}`,
    rename: "Rename", remove: "Delete",
    ind: { unread: "Unread", pinned: "Pinned", important: "Important" },
    labels: { acme: "Acme Holdings", invoice: "Invoice", supplier: "Supplier", lahore: "Lahore office" },
    apps: { whatsapp: "WhatsApp", voice_note: "Voice note", google_meet: "Google Meet" } as Record<string, string>,
    devices: { phone: "on a phone", desktop: "on a computer", web: "on the web" },
  },
  ur: {
    title: "لائبریری کی قطاریں", list: "ریکارڈنگز", pinned: "پن شدہ", today: "آج", noSpeech: "کوئی گفتگو نہیں", more: (t: string) => `${t} کے مزید کام`,
    rename: "نام بدلیں", remove: "حذف کریں",
    ind: { unread: "نہ پڑھی گئی", pinned: "پن شدہ", important: "اہم" },
    labels: { acme: "ایکمی ہولڈنگز", invoice: "انوائس", supplier: "سپلائر", lahore: "لاہور دفتر" },
    apps: { whatsapp: "WhatsApp", voice_note: "وائس نوٹ", google_meet: "Google Meet" } as Record<string, string>,
    devices: { phone: "فون پر", desktop: "کمپیوٹر پر", web: "ویب پر" },
  },
};
type Words = (typeof WORDS)[Lang];

/** The Library's rows as D12 draws them, with the before picture's recordings: the "after" to its "before". */
export function RowsScreen({ lang }: { lang: Lang }) {
  const w = WORDS[lang];
  const [selected, setSelected] = useState("supplier");
  const samples = SAMPLES(w);
  const sections = [["pinned", w.pinned], ["today", w.today]] as const;
  return (
    <div className="g-page g-page--wide">
      <header className="g-header"><h1 className="el-title-l">{w.title}</h1></header>
      <List label={w.list}>
        {sections.map(([key, name]) => {
          const rows = samples.filter((s) => s.section === key);
          return [
            <ListSection key={key} title={name} count={rows.length} collapsed={false} onToggle={noop} />,
            ...rows.map((s) => {
              const snippet = firstSentence(s.preview);
              return (
                <ListRow key={s.id} variant="library" rowId={s.id} selected={selected === s.id} onClick={() => setSelected(s.id)}
                  leading={<SourceMark app={s.app} device={s.device} label={`${w.apps[s.app]}${lang === "ur" ? "، " : ", "}${w.devices[s.device]}`} />}
                  title={s.title} meta={snippet ?? w.noSpeech} indicators={s.indicators}
                  endTop={s.when} endBottom={s.duration}
                  actions={<Menu label={w.more(s.title)} items={[{ label: w.rename, icon: Icons.RenameIcon }, { label: w.remove, icon: Icons.TrashIcon, danger: true }]}
                    button={{ variant: "quiet", size: "sm", icon: Icons.MoreIcon }} />} />
              );
            }),
          ];
        })}
      </List>
    </div>
  );
}
