import type { ReactNode } from "react";
import { Card, Icons, SplitButton, copyMenuItems } from "../../components";
import type { Lang } from "../content";

const noop = () => undefined;

const WORDS = {
  en: {
    title: "Split button", intro: "Copy the text in one press, or pick another way to copy from the chevron.",
    copy: "Copy", copyText: "Copy text", more: "More copy options",
    plain: "Default", hover: "Hover", focus: "Keyboard focus on the chevron", open: "Open", primary: "Primary", disabled: "Can't be used now", rtl: "Right to left",
    items: {},
  },
  ur: {
    title: "اسپلٹ بٹن", intro: "ایک بار دبانے سے متن کاپی کریں، یا شیوران سے کاپی کا کوئی اور طریقہ چنیں۔",
    copy: "کاپی", copyText: "متن کاپی کریں", more: "کاپی کے مزید طریقے",
    plain: "عام حالت", hover: "ماؤس اوپر", focus: "شیوران پر کی بورڈ فوکس", open: "کھلا ہوا", primary: "بنیادی", disabled: "ابھی استعمال نہیں ہو سکتا", rtl: "دائیں سے بائیں",
    items: { text: "متن کاپی کریں", withTimestamps: "ٹائم اسٹیمپ کے ساتھ کاپی کریں", withoutTimestamps: "ٹائم اسٹیمپ کے بغیر کاپی کریں", forAi: "AI کے لیے کاپی کریں" },
  },
};

function Case({ name, state, children, rtl }: { name: string; state: string; children: ReactNode; rtl?: boolean }) {
  return (
    <div className="g-kit-case g-split-case" data-case={state} {...(rtl ? { dir: "rtl", lang: "ur" } : {})}>
      <h3 className="el-caption el-secondary">{name}</h3>
      <div className="g-kit-row">{children}</div>
    </div>
  );
}

/** SplitButton (D12) in its states. Hover, focus and open are set by the picture script (shoot.mjs --review=D12). */
export function SplitScreen({ lang }: { lang: Lang }) {
  const w = WORDS[lang];
  const ur = WORDS.ur;
  const split = (words: typeof w, extra: { variant?: "primary" | "secondary"; disabled?: boolean } = {}) => (
    <SplitButton label={words.copy} icon={Icons.CopyIcon} onClick={noop} mainLabel={words.copyText} menuLabel={words.more}
      items={copyMenuItems({ text: noop, withTimestamps: noop, withoutTimestamps: noop, forAi: noop }, words.items)} {...extra} />
  );
  return (
    <div className="g-page g-page--narrow">
      <header className="g-header">
        <div className="g-header__titles">
          <h1 className="el-title-l">{w.title}</h1>
          <p className="el-secondary">{w.intro}</p>
        </div>
      </header>
      <Card className="g-kit-card g-split-grid">
        <Case name={w.plain} state="default">{split(w)}</Case>
        <Case name={w.hover} state="hover">{split(w)}</Case>
        <Case name={w.focus} state="focus">{split(w)}</Case>
        <Case name={w.primary} state="primary">{split(w, { variant: "primary" })}</Case>
        <Case name={w.disabled} state="disabled">{split(w, { disabled: true })}</Case>
        {lang === "en" && <Case name={ur.rtl} state="rtl" rtl>{split(ur)}</Case>}
        <Case name={w.open} state="open">{split(w)}</Case>
      </Card>
    </div>
  );
}
