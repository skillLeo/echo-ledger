// A Settings page in the new look: its frame (back, title, the setting asked for scrolled to and highlighted) and
// the rows every page is built from. A row's title, description and options come from the settings registry
// (lib/settingsRegistry.ts), the same words search, the phone and the console use.
import { PanelToggle } from "../../shell/NavRail";
import { createContext, useContext, useEffect, useId, useRef, useState, type ReactNode } from "react";
import { api, type Settings, type SettingsPatch } from "../../../lib/ipc";
import { setting } from "../../../lib/settingsRegistry";
import { confirmAction } from "../../../lib/confirm";
import { highlightSetting } from "../../../views/main/SettingsControls";
import { Button, Card, Icons, List, ListRow, Loading, Radio, Segmented, Select, SkeletonRow, Toggle } from "../../components";

export type Run = <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined>;
export type Patch = (p: SettingsPatch) => Promise<boolean>;

/** The registry's options for a setting, as a select or segmented control takes them. */
export const optionsOf = <T extends string = string>(id: string) => (setting(id).options ?? []).map((o) => ({ value: o.value as T, label: o.label }));

/** This computer's settings, and a patch that saves through the app's runner (a notice when it fails). */
export function useSettings(run: Run): { settings: Settings | null; patch: Patch; setSettings: (s: Settings) => void } {
  const [settings, setSettings] = useState<Settings | null>(null);
  useEffect(() => {
    let live = true;
    api.settings().then((s) => live && setSettings(s), () => undefined);
    return () => {
      live = false;
    };
  }, []);
  const patch: Patch = async (p) => {
    const next = await run(() => api.updateSettings(p));
    if (next) setSettings(next);
    return !!next;
  };
  return { settings, patch, setSettings };
}

/**
 * Where a page goes once it is drawn: a setting scrolled to and highlighted (a search result), a row scrolled to and
 * focused without a highlight (back from the sub-page it opens), a section of a group's page, or the page's top.
 */
export type PageTarget = { id: string; seq: number; how?: "highlight" | "row" | "part" | "top" };

/** The id a group's page gives a page's section, to scroll to it. */
export const partId = (page: string) => `settings-part-${page}`;

/** The box the page scrolls in (the nearest one that scrolls), to start it at its top. */
function scroller(from: HTMLElement): HTMLElement | null {
  for (let el = from.parentElement; el; el = el.parentElement) {
    const { overflowY } = getComputedStyle(el);
    if ((overflowY === "auto" || overflowY === "scroll") && el.scrollHeight > el.clientHeight) return el;
  }
  return null;
}

function reveal(target: PageTarget, root: HTMLElement | null): boolean {
  const scope: ParentNode = root ?? document;
  const find = (id: string) => scope.querySelector<HTMLElement>(`#${CSS.escape(id)}`);
  switch (target.how ?? "highlight") {
    case "highlight": return highlightSetting(target.id, scope);
    case "top": {
      const box = root ? scroller(root) : null;
      if (box) box.scrollTop = 0;
      return true;
    }
    case "part": {
      const part = find(partId(target.id));
      part?.scrollIntoView?.({ block: "start" });
      return !!part;
    }
    case "row": {
      const row = find(`setting-${target.id}`);
      row?.scrollIntoView?.({ block: "center" });
      row?.focus?.({ preventScroll: true });
      return !!row;
    }
  }
}

/**
 * One page: its way back (a sub-page's, to its group's page; none on a group's page, as the panel has the groups), its
 * title, its body. With `target`, the page goes there once it is on the page (the page may still be reading).
 */
export function SettingsPage({ title, back, onBack, target, kind = "page", children }: {
  title: string;
  back?: string;
  onBack?: () => void;
  target?: PageTarget | null;
  /** A group's page (UX-11): its pages as sections under the group's title. */
  kind?: "page" | "group";
  children: ReactNode;
}) {
  const root = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!target) return;
    let tries = 0;
    const timer = window.setInterval(() => {
      if (reveal(target, root.current) || ++tries > 40) window.clearInterval(timer);
    }, 50);
    return () => window.clearInterval(timer);
  }, [target]);
  return (
    <div className="el-page el-page--narrow el-settings-v2" data-v2={kind === "group" ? "settings-group" : "settings-page"} ref={root}>
      {back && onBack && <Button variant="quiet" size="sm" icon={Icons.ChevronStartIcon} directional className="el-page__back" onClick={onBack}>{back}</Button>}
      <header className="el-page__head"><PanelToggle /><h1 className="el-title-l">{title}</h1></header>
      {children}
    </div>
  );
}

/**
 * The level of a page's section titles: 2 on a page of its own, 3 inside a group's page, where the page's title is the
 * level-2 heading above them.
 */
const SectionLevelContext = createContext<2 | 3>(2);
export const SectionLevel = SectionLevelContext.Provider;

/** A section's title (DES-05): text only, a heading at the page's level, never clickable. */
export function SectionTitle({ id, className = "el-caption el-body-strong el-group__title", children }: { id?: string; className?: string; children: ReactNode }) {
  const Heading = useContext(SectionLevelContext) === 3 ? "h3" : "h2";
  return <Heading className={className} id={id}>{children}</Heading>;
}

/** A group of rows in one card, with an optional heading and the lines under it. */
export function Group({ title, footer, children }: { title?: string; footer?: ReactNode; children: ReactNode }) {
  const id = useId();
  return (
    <section className="el-group" aria-labelledby={title ? id : undefined}>
      {title && <SectionTitle id={id}>{title}</SectionTitle>}
      <Card><List label={title ?? "Settings"} className="el-group__rows">{children}</List></Card>
      {footer && <div className="el-group__note el-caption el-secondary" dir="auto">{footer}</div>}
    </section>
  );
}

/** A page about plans on a server that has none (entitlements answered null): said once, instead of waiting forever. */
export function NoPlans() {
  return <Card><p className="el-no-plans el-secondary" role="status">Your server doesn't have plans yet.</p></Card>;
}

/** While the settings are read. */
export function PageLoading() {
  return <Loading label="Loading settings"><Card><div className="el-group__rows"><SkeletonRow end={false} /><SkeletonRow end={false} /><SkeletonRow end={false} /></div></Card></Loading>;
}

export function SwitchSetting({ id, on, onChange, disabled, hint }: { id: string; on: boolean; onChange: (on: boolean) => void; disabled?: boolean; hint?: ReactNode }) {
  const s = setting(id);
  return (
    <ListRow compact anchor={id} title={s.title} meta={hint === undefined ? s.description : hint ?? undefined}
      control={<Toggle label={s.title} hideLabel checked={on} disabled={disabled} onChange={onChange} />} />
  );
}

export function SelectSetting<T extends string>({ id, value, options = optionsOf<T>(id), onChange, disabled, meta }: {
  id: string;
  value: T;
  options?: { value: T; label: string }[];
  onChange: (value: T) => void;
  disabled?: boolean;
  /** The row's subtitle when it depends on the choice (else the setting's description). */
  meta?: string;
}) {
  const s = setting(id);
  return (
    <ListRow compact anchor={id} title={s.title} meta={meta ?? s.description}
      control={<Select label={s.title} hideLabel value={value} options={options} disabled={disabled} onChange={(v) => onChange(v as T)} className="el-setting__select" />} />
  );
}

/** One choice of a few, each on its own row with its help line under its name (After each call): radios, so the arrow
 * keys move between them; the group's heading names it, and the group carries the name for screen readers. */
export function ChoiceSetting<T extends string>({ id, value, options, onChange }: {
  id: string;
  value: T;
  options: readonly { value: T; label: string; hint?: string }[];
  onChange: (value: T) => void;
}) {
  const s = setting(id);
  const name = useId();
  return (
    <li className="el-choice-setting" id={`setting-${id}`} data-setting={id} tabIndex={-1}>
      <fieldset className="el-radio-group">
        <legend className="el-visually-hidden">{s.title}</legend>
        {options.map((o) => (
          <Radio key={o.value} name={name} value={o.value} label={o.label} hint={o.hint} checked={o.value === value} onChange={() => onChange(o.value)} />
        ))}
      </fieldset>
    </li>
  );
}

export function SegmentedSetting<T extends string>({ id, value, options = optionsOf<T>(id), onChange }: {
  id: string;
  value: T;
  options?: { value: T; label: string }[];
  onChange: (value: T) => void;
}) {
  const s = setting(id);
  return <ListRow compact anchor={id} title={s.title} control={<Segmented label={s.title} value={value} options={options} onChange={onChange} />} />;
}

export function SliderSetting({ id, value, min, max, step, format, onChange }: {
  id: string;
  value: number;
  min: number;
  max: number;
  step: number;
  format: (v: number) => string;
  onChange: (v: number) => void;
}) {
  const s = setting(id);
  return (
    <ListRow compact anchor={id} title={s.title} value={format(value)}
      control={<input type="range" className="el-range" aria-label={s.title} aria-valuetext={format(value)} min={min} max={max} step={step} value={value}
        onChange={(e) => onChange(Number(e.target.value))} />} />
  );
}

export function LinkSetting({ id, value, onOpen, meta }: { id: string; value?: string; onOpen: () => void; meta?: ReactNode }) {
  const s = setting(id);
  return <ListRow compact anchor={id} title={s.title} meta={meta ?? s.description} value={value} chevron onClick={onOpen} />;
}

/** "Reset to defaults", after asking. */
export function ResetDefaults({ page, onReset }: { page: string; onReset: () => void }) {
  return (
    <div className="el-settings-v2__reset">
      <Button variant="quiet" onClick={() => void confirmAction({ title: `Reset ${page} to defaults?`, confirm: "Reset" }).then((yes) => { if (yes) onReset(); })}>
        Reset to defaults
      </Button>
    </div>
  );
}
