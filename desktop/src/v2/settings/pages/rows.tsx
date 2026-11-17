// A Settings page in the new look: its frame (back, title, the setting asked for scrolled to and highlighted) and
// the rows every page is built from. A row's title, description and options come from the settings registry
// (lib/settingsRegistry.ts), the same words search, the phone and the console use.
import { useEffect, useId, useRef, useState, type ReactNode } from "react";
import { api, type Settings, type SettingsPatch } from "../../../lib/ipc";
import { setting } from "../../../lib/settingsRegistry";
import { confirmAction } from "../../../lib/confirm";
import { highlightSetting } from "../../../views/main/SettingsControls";
import { Button, Card, Icons, List, ListRow, Loading, Segmented, Select, SkeletonRow, Toggle } from "../../components";

export type Run = <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined>;
export type Patch = (p: SettingsPatch) => Promise<boolean>;

/** The registry's options for a setting, as a select or segmented control takes them. */
export const optionsOf = <T extends string = string>(id: string) => (setting(id).options ?? []).map((o) => ({ value: o.value as T, label: o.label }));

/** This computer's settings, and a patch that saves through the app's runner (a notice when it fails). */
export function useSettings(run: Run): { settings: Settings | null; patch: Patch } {
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
  return { settings, patch };
}

/**
 * One page: the way back, its title, its body. With `target`, the setting's row is scrolled to and highlighted once it
 * is on the page (the page may still be reading).
 */
export function SettingsPage({ title, back, onBack, target, children }: {
  title: string;
  back: string;
  onBack: () => void;
  target?: { id: string; seq: number } | null;
  children: ReactNode;
}) {
  const root = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!target) return;
    let tries = 0;
    const timer = window.setInterval(() => {
      if (highlightSetting(target.id, root.current ?? document) || ++tries > 40) window.clearInterval(timer);
    }, 50);
    return () => window.clearInterval(timer);
  }, [target]);
  return (
    <div className="el-page el-page--narrow el-settings-v2" data-v2="settings-page" ref={root}>
      <Button variant="quiet" size="sm" icon={Icons.ChevronStartIcon} directional className="el-page__back" onClick={onBack}>{back}</Button>
      <header className="el-page__head"><h1 className="el-title-l">{title}</h1></header>
      {children}
    </div>
  );
}

/** A group of rows in one card, with an optional heading and the lines under it. */
export function Group({ title, footer, children }: { title?: string; footer?: ReactNode; children: ReactNode }) {
  const id = useId();
  return (
    <section className="el-group" aria-labelledby={title ? id : undefined}>
      {title && <h2 className="el-caption el-body-strong el-group__title" id={id}>{title}</h2>}
      <Card><List label={title ?? "Settings"} className="el-group__rows">{children}</List></Card>
      {footer && <div className="el-group__note el-caption el-secondary">{footer}</div>}
    </section>
  );
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

export function SelectSetting<T extends string>({ id, value, options = optionsOf<T>(id), onChange, disabled }: {
  id: string;
  value: T;
  options?: { value: T; label: string }[];
  onChange: (value: T) => void;
  disabled?: boolean;
}) {
  const s = setting(id);
  return (
    <ListRow compact anchor={id} title={s.title} meta={s.description}
      control={<Select label={s.title} hideLabel value={value} options={options} disabled={disabled} onChange={(v) => onChange(v as T)} className="el-setting__select" />} />
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
