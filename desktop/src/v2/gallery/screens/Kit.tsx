import { useRef, useState, type ReactNode } from "react";
import {
  BellButton, Card, Icons, LABEL_COLOURS, LabelChip, LabelDot, Menu, SORT_GROUP_OPTIONS, SearchField, SelectionBar, SortGroupMenu, StickyItemBar,
  copyMenuItems, type GroupKey, type LabelColour, type SelectionAction, type SortGroupOptions, type SortGroupValue, type SortKey,
} from "../../components";
import type { Lang } from "../content";

const noop = () => undefined;
/** A stored colour that is none of the eight (an older app's), written as its digits like LABEL_COLOURS. */
const OLDER_COLOUR = `#${"5B7C99"}`;

/** The words of the shared bars, in English and Urdu (the Urdu ones also show in the right-to-left case of the English page). */
const WORDS = {
  en: {
    title: "Shared bars",
    intro: "The selection bar, Sort and group, the item bar, the Copy menu, the bell and labels, in each of their states.",
    selection: "Selection bar", sort: "Sort and group", item: "Item bar", copy: "Copy menu", bell: "Activity", labels: "Labels",
    defaultCase: "Three picked", plain: "Default", removable: "Removable, and the dots alone", noneCase: "Nothing picked yet", manyCase: "Many actions in ⋮", rtlCase: "Right to left",
    disabledCase: "Can't be changed now", scrollCase: "Scroll the recording: the bar appears once its header is out of sight",
    toolbar: "Selected recordings", move: "Move to project", label: "Label", share: "Share or export", remove: "Delete",
    pin: "Pin", keep: "Keep forever", retranscribe: "Re-transcribe", ask: "Ask about these", rename: "Rename", copyMenu: "Copy",
    exportPdf: "Export as PDF", exportWord: "Export as Word", exportText: "Export as text",
    search: "Search recordings", recordingTitle: "Payment terms for the March shipment", recordingMenu: "Recording menu",
    line: "Bilal Qureshi: the second container leaves Karachi on the 14th, so the balance is due by the end of March.",
    unknown: "Older label", back: "Back",
    names: { grey: "Personal", red: "Urgent", orange: "Follow up", amber: "Invoice", green: "Paid", teal: "Supplier", blue: "Lahore office", pink: "Family" },
  },
  ur: {
    title: "مشترکہ بار",
    intro: "انتخاب کا بار، ترتیب اور گروپ، آئٹم بار، کاپی مینو، گھنٹی اور لیبلز، اپنی ہر حالت میں۔",
    selection: "انتخاب کا بار", sort: "ترتیب اور گروپ", item: "آئٹم بار", copy: "کاپی مینو", bell: "سرگرمی", labels: "لیبلز",
    defaultCase: "تین منتخب", plain: "عام حالت", removable: "ہٹانے کے بٹن کے ساتھ، اور صرف نقطے", noneCase: "ابھی کچھ منتخب نہیں", manyCase: "⋮ میں کئی کام", rtlCase: "دائیں سے بائیں",
    disabledCase: "ابھی تبدیل نہیں ہو سکتا", scrollCase: "ریکارڈنگ اسکرول کریں: سرخی نظر سے ہٹتے ہی بار آ جاتا ہے",
    toolbar: "منتخب ریکارڈنگز", move: "پروجیکٹ میں منتقل کریں", label: "لیبل", share: "شیئر یا ایکسپورٹ", remove: "حذف کریں",
    pin: "پن کریں", keep: "ہمیشہ رکھیں", retranscribe: "دوبارہ ٹرانسکرائب کریں", ask: "ان کے بارے میں پوچھیں", rename: "نام بدلیں", copyMenu: "کاپی",
    exportPdf: "PDF کے طور پر ایکسپورٹ", exportWord: "Word کے طور پر ایکسپورٹ", exportText: "متن کے طور پر ایکسپورٹ",
    search: "ریکارڈنگز تلاش کریں", recordingTitle: "ادائیگی کا منصوبہ، قرض کی واپسی", recordingMenu: "ریکارڈنگ مینو",
    line: "بلال قریشی: دوسرا کنٹینر 14 تاریخ کو کراچی سے روانہ ہوگا، اس لیے باقی رقم مارچ کے آخر تک واجب الادا ہے۔",
    unknown: "پرانا لیبل", back: "واپس",
    names: { grey: "ذاتی", red: "فوری", orange: "فالو اپ", amber: "انوائس", green: "ادا شدہ", teal: "سپلائر", blue: "لاہور دفتر", pink: "خاندان" },
  },
} as const;

type W = (typeof WORDS)[Lang];

/** The bell's name with a count, in the page's language. */
const unreadLabel = (lang: Lang) => (lang === "ur" ? (n: number) => `${WORDS.ur.bell}، ${n} نئی` : undefined);

const selectionWords = (lang: Lang) => lang === "ur"
  ? { selected: (n: number) => `${n} منتخب`, none: "ریکارڈنگز منتخب کریں", selectAll: "سب منتخب کریں", unselectAll: "سب کا انتخاب ختم کریں", exit: "انتخاب منسوخ کریں", more: "مزید کام" }
  : {};

function actions(w: W, many: boolean): SelectionAction[] {
  const main: SelectionAction[] = [
    { id: "move", label: w.move, icon: Icons.MoveIcon, onPick: noop },
    { id: "label", label: w.label, icon: Icons.LabelIcon, onPick: noop },
    { id: "share", label: w.share, icon: Icons.ShareIcon, items: [{ label: w.exportPdf, onPick: noop }, { label: w.exportWord, onPick: noop }, { label: w.exportText, onPick: noop }] },
    { id: "delete", label: w.remove, icon: Icons.TrashIcon, danger: true, shortcut: "Del", onPick: noop },
    { id: "pin", label: w.pin, icon: Icons.PinIcon, onPick: noop },
  ];
  if (!many) return main;
  return [
    ...main,
    { id: "keep", label: w.keep, icon: Icons.KeepIcon, onPick: noop },
    { id: "retranscribe", label: w.retranscribe, icon: Icons.RefreshIcon, onPick: noop },
    { id: "ask", label: w.ask, icon: Icons.AskIcon, onPick: noop },
    ...copyMenuItems({ text: noop, withTimestamps: noop, withoutTimestamps: noop, forAi: noop }).map((item, i) => ({ ...item, id: `copy-${i}`, group: w.copyMenu })),
  ];
}
const MAIN = ["move", "label", "share", "delete"];

/** One state of a component, with its name over it. */
function Case({ name, children, rtl }: { name: string; children: ReactNode; rtl?: boolean }) {
  return (
    <div className="g-kit-case" {...(rtl ? { dir: "rtl", lang: "ur" } : {})}>
      <h3 className="el-caption el-secondary">{name}</h3>
      {children}
    </div>
  );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="g-group" aria-label={title}>
      <h2 className="el-title-s g-group__title">{title}</h2>
      <Card className="g-kit-card">{children}</Card>
    </section>
  );
}

function Selection({ w, lang, count, many, rtl }: { w: W; lang: Lang; count: number; many?: boolean; rtl?: boolean }) {
  const [picked, setPicked] = useState(count);
  return (
    <SelectionBar label={w.toolbar} level={2} count={picked} allSelected={picked === 12} onToggleAll={() => setPicked(picked === 12 ? 0 : 12)}
      onExit={noop} actions={actions(w, !!many)} main={MAIN} words={selectionWords(rtl ? "ur" : lang)} />
  );
}

/** The Library's choices, in Urdu for the right-to-left cases. */
const SORT_UR = {
  words: { label: "ترتیب اور گروپ", sort: "ترتیب", group: "گروپ بندی", density: "کثافت" },
  options: {
    sort: [{ value: "newest", label: "نئی پہلے" }, { value: "oldest", label: "پرانی پہلے" }, { value: "longest", label: "سب سے لمبی" }, { value: "name", label: "نام، الف سے ی" }],
    group: [{ value: "date", label: "تاریخ" }, { value: "source", label: "ذریعہ" }, { value: "project", label: "پروجیکٹ" }, { value: "none", label: "کوئی نہیں" }],
    density: [{ value: "comfortable", label: "کشادہ" }, { value: "compact", label: "گنجان" }],
  } satisfies SortGroupOptions<SortKey, GroupKey>,
};

function Sort({ w, disabled, ur }: { w: W; disabled?: boolean; ur?: boolean }) {
  const [value, setValue] = useState<SortGroupValue<SortKey, GroupKey>>({ sort: "newest", group: "date", density: "comfortable" });
  const [query, setQuery] = useState("");
  return (
    <div className="el-search-row">
      <SearchField label={w.search} value={query} onChange={setQuery} />
      <SortGroupMenu value={value} onChange={setValue} options={ur ? SORT_UR.options : SORT_GROUP_OPTIONS} words={ur ? SORT_UR.words : undefined} disabled={disabled} />
    </div>
  );
}

/** A short recording page in its own scroller: the item bar shows once the header has scrolled out of sight. */
function ScrollingItem({ w }: { w: W }) {
  const head = useRef<HTMLElement>(null);
  return (
    <div className="g-kit-scroller" role="region" tabIndex={0} aria-label={w.scrollCase}>
      <StickyItemBar title={w.recordingTitle} backLabel={w.back} onBack={noop} sentinel={head} menu={{ label: w.recordingMenu, items: [{ label: w.rename, icon: Icons.RenameIcon }, { label: w.remove, icon: Icons.TrashIcon, danger: true }] }} />
      <header ref={head} className="g-kit-scroller__head"><p className="el-title-m" dir="auto">{w.recordingTitle}</p></header>
      {Array.from({ length: 8 }, (_, i) => <p key={i} className="g-kit-scroller__line" dir="auto">{w.line}</p>)}
    </div>
  );
}

/** The shared desktop bars (D6), each in its states: default, disabled or empty, many items, right to left. Dark is the theme parameter. */
export function KitScreen({ lang }: { lang: Lang }) {
  const w = WORDS[lang];
  const ur = WORDS.ur;
  const names = Object.keys(LABEL_COLOURS) as LabelColour[];
  const [removed, setRemoved] = useState<string[]>([]);
  const copy = copyMenuItems({ text: noop, withTimestamps: noop, withoutTimestamps: noop, forAi: noop },
    lang === "ur" ? { text: "متن کاپی کریں", withTimestamps: "ٹائم اسٹیمپ کے ساتھ کاپی کریں", withoutTimestamps: "ٹائم اسٹیمپ کے بغیر کاپی کریں", forAi: "AI کے لیے کاپی کریں" } : {});
  return (
    <div className="g-page g-page--wide">
      <header className="g-header">
        <div className="g-header__titles">
          <h1 className="el-title-l">{w.title}</h1>
          <p className="el-secondary">{w.intro}</p>
        </div>
      </header>

      <Section title={w.selection}>
        <Case name={w.defaultCase}><Selection w={w} lang={lang} count={3} /></Case>
        <Case name={w.noneCase}><Selection w={w} lang={lang} count={0} /></Case>
        <Case name={w.manyCase}><Selection w={w} lang={lang} count={12} many /></Case>
        <Case name={ur.rtlCase} rtl><Selection w={ur} lang="ur" count={3} rtl /></Case>
      </Section>

      <Section title={w.sort}>
        <Case name={w.plain}><Sort w={w} ur={lang === "ur"} /></Case>
        <Case name={w.disabledCase}><Sort w={w} ur={lang === "ur"} disabled /></Case>
        <Case name={ur.rtlCase} rtl><Sort w={ur} ur /></Case>
      </Section>

      <Section title={w.item}>
        <Case name={w.plain}>
          <div className="g-kit-itembar"><StickyItemBar title={WORDS.en.recordingTitle} backLabel={w.back} onBack={noop} menu={{ label: w.recordingMenu, items: [{ label: w.rename }] }} /></div>
        </Case>
        <Case name={ur.rtlCase} rtl>
          <div className="g-kit-itembar"><StickyItemBar title={ur.recordingTitle} backLabel={ur.back} onBack={noop} menu={{ label: ur.recordingMenu, items: [{ label: ur.rename }] }} /></div>
        </Case>
        <Case name={w.scrollCase}><ScrollingItem w={w} /></Case>
      </Section>

      <Section title={w.copy}>
        <Case name={w.plain}>
          <Menu label={w.copyMenu} items={copy} button={{ variant: "secondary", icon: Icons.CopyIcon, text: w.copyMenu }} />
        </Case>
      </Section>

      <Section title={w.bell}>
        <Case name={w.plain}>
          <div className="g-kit-row">
            <BellButton unread={0} label={w.bell} unreadLabel={unreadLabel(lang)} />
            <BellButton unread={3} label={w.bell} unreadLabel={unreadLabel(lang)} />
            <BellButton unread={120} label={w.bell} unreadLabel={unreadLabel(lang)} />
            <BellButton unread={7} label={w.bell} unreadLabel={unreadLabel(lang)} disabled />
          </div>
        </Case>
        <Case name={ur.rtlCase} rtl>
          <div className="g-kit-row"><BellButton unread={12} label={ur.bell} unreadLabel={unreadLabel("ur")} /></div>
        </Case>
      </Section>

      <Section title={w.labels}>
        <Case name={w.plain}>
          <div className="g-kit-row">
            {names.map((n) => <LabelChip key={n} name={w.names[n]} colour={LABEL_COLOURS[n]} />)}
            <LabelChip name={w.unknown} colour={OLDER_COLOUR} />
          </div>
        </Case>
        <Case name={w.removable}>
          <div className="g-kit-row">
            {names.filter((n) => !removed.includes(n)).map((n) => (
              <LabelChip key={n} name={w.names[n]} colour={LABEL_COLOURS[n].toLowerCase()} onRemove={() => setRemoved([...removed, n])} />
            ))}
            {names.map((n) => <LabelDot key={n} name={w.names[n]} colour={LABEL_COLOURS[n]} />)}
          </div>
        </Case>
        <Case name={ur.rtlCase} rtl>
          <div className="g-kit-row">
            {names.map((n) => <LabelChip key={n} name={ur.names[n]} colour={LABEL_COLOURS[n]} />)}
            <LabelChip name={ur.unknown} colour={OLDER_COLOUR} onRemove={noop} removeLabel={`${ur.unknown} ہٹائیں`} />
          </div>
        </Case>
      </Section>
    </div>
  );
}
