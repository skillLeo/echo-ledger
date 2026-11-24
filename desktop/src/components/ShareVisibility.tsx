// Screen-share visibility per window (since v2.0, Rust `privacy::share`): the main window shows in screen shares unless
// the person hides it, for a time they choose; the Live Assist card and the captions are hidden unless the person shows
// that one window, on that window. The badge on those two windows says what the system reads back, never only the saved
// choice. Words: "Hide from screen shares" / "Show in screen shares", nothing more.
import { useEffect, useState } from "react";
import { api, errorMessage, listenTo, type HideFor, type PinnedWindow, type PrivacyStatus, type WindowShareState, type WindowShareView } from "../lib/ipc";

/** "Custom…": 1 minute to 7 days (privacy::share::CUSTOM_*). */
export const CUSTOM_MAX_MINUTES = 7 * 24 * 60;
export const CUSTOM_RANGE = "Choose between 1 minute and 7 days.";
export const CUSTOM_UNITS = [["minutes", 1], ["hours", 60], ["days", 1440]] as const;
export type CustomUnit = (typeof CUSTOM_UNITS)[number][0];

/** The durations the list offers now, in order (pure): "Until this call ends" only while a call is detected. */
export function hideChoices(callDetected: boolean): { choice: HideFor | null; label: string }[] {
  return [
    ...(callDetected ? [{ choice: { kind: "call_ends" } as HideFor, label: "Until this call ends" }] : []),
    { choice: { kind: "minutes15" }, label: "15 min" },
    { choice: { kind: "hour1" }, label: "1 hour" },
    { choice: { kind: "tomorrow" }, label: "Until tomorrow" },
    { choice: { kind: "until_off" }, label: "Until I turn it off" },
    { choice: null, label: "Custom…" },
  ];
}

/** "Custom…" in minutes, or null outside 1 minute to 7 days (pure). */
export function customMinutes(amount: number, unit: CustomUnit): number | null {
  const per = CUSTOM_UNITS.find(([u]) => u === unit)?.[1] ?? 1;
  const minutes = Math.round(amount * per);
  return Number.isFinite(minutes) && minutes >= 1 && minutes <= CUSTOM_MAX_MINUTES ? minutes : null;
}

/** The duration list under "Hide from screen shares"; Custom… opens its own amount and unit. */
export function HideDurations({ callDetected, onChosen }: { callDetected: boolean; onChosen?: () => void }) {
  const [custom, setCustom] = useState(false);
  const [amount, setAmount] = useState("30");
  const [unit, setUnit] = useState<CustomUnit>("minutes");
  const [problem, setProblem] = useState<string | null>(null);
  const hide = (choice: HideFor) => {
    setProblem(null);
    void api.privacyMainHide(choice).then(() => onChosen?.()).catch((e) => setProblem(errorMessage(e)));
  };
  const hideCustom = () => {
    const minutes = customMinutes(Number(amount), unit);
    if (minutes === null) return setProblem(CUSTOM_RANGE);
    hide({ kind: "custom", minutes });
  };
  return (
    <div className="share-durations" role="menu" aria-label="Hide from screen shares" data-hit data-testid="share-durations">
      {hideChoices(callDetected).map(({ choice, label }) => (
        <button key={label} type="button" role="menuitem" className="btn compact ghost" aria-expanded={choice ? undefined : custom}
          onClick={() => (choice ? hide(choice) : setCustom((c) => !c))}>{label}</button>
      ))}
      {custom && (
        <div className="share-custom" data-testid="share-custom">
          <input type="number" min={1} aria-label="How long" value={amount} onChange={(e) => setAmount(e.target.value)} />
          <select aria-label="Unit" value={unit} onChange={(e) => setUnit(e.target.value as CustomUnit)}>
            {CUSTOM_UNITS.map(([u]) => <option dir="auto" key={u} value={u}>{u}</option>)}
          </select>
          <button type="button" className="btn compact" onClick={hideCustom}>Hide</button>
        </div>
      )}
      {problem && <p className="privacy-line warning" role="alert">{problem}</p>}
    </div>
  );
}

/** A button that opens the duration list ("Hide from screen shares", or "Hide ▸" on the notice). */
export function HideFromSharesButton({ callDetected, label = "Hide from screen shares", onChosen }: { callDetected: boolean; label?: string; onChosen?: () => void }) {
  const [open, setOpen] = useState(false);
  return (
    <span className="share-hide">
      <button type="button" className="btn compact" aria-expanded={open} aria-haspopup="menu" onClick={() => setOpen((o) => !o)}>{label}</button>
      {open && <HideDurations callDetected={callDetected} onChosen={() => { setOpen(false); onChosen?.(); }} />}
    </span>
  );
}

/** What the main window's row says (pure): shown, or hidden and until when. */
export function mainShareValue(status: Pick<PrivacyStatus, "mainHide" | "mainHiddenUntil"> | null): string {
  if (!status?.mainHide) return "Shown in screen shares";
  return `Hidden ${status.mainHiddenUntil ?? "from screen shares"}`;
}

/** Settings: the main window shown, with Hide from screen shares, or hidden with Show now. */
export function MainShareControl({ status }: { status: PrivacyStatus | null }) {
  const [problem, setProblem] = useState<string | null>(null);
  if (!status) return null;
  if (status.mainHide) {
    return (
      <>
        <button type="button" className="btn compact" onClick={() => void api.privacyMainShow().catch((e) => setProblem(errorMessage(e)))}>Show now</button>
        {problem && <span className="privacy-line warning" role="alert">{problem}</span>}
      </>
    );
  }
  return <HideFromSharesButton callDetected={!!status.callDetected} />;
}

/** The card's or the captions' real state, read back from the system and again on every privacy change. `which`: null
 * for the window asking about itself. */
export function useWindowShare(which: PinnedWindow | null): [WindowShareView | null, (v: WindowShareView) => void] {
  const [view, setView] = useState<WindowShareView | null>(null);
  useEffect(() => {
    let alive = true;
    const read = () => void api.privacyWindowShare(which).then((v) => alive && setView(v)).catch(() => undefined);
    read();
    const unlisten = listenTo("privacy:changed", read);
    return () => {
      alive = false;
      unlisten.then((f) => f()).catch(() => undefined);
    };
  }, [which]);
  return [view, setView];
}

/** The badge's short word (the full sentence is its label). */
export const SHORT: Record<WindowShareState, string> = { hidden: "Hidden", shown: "Shown", black_box: "Black box", could_not_hide: "Couldn't hide", not_open: "Hidden" };

/** The badge on the Live Assist card and the captions: what the system reads back; a click opens the window's own
 * switch. A hit region (`data-hit`), so it stays clickable in a click-through window. */
export function WindowShareBadge({ compact = true }: { compact?: boolean }) {
  const [view, setView] = useWindowShare(null);
  const [open, setOpen] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  if (!view) return null;
  const flip = () => {
    setProblem(null);
    void api.privacyWindowShareSet(!view.shown).then((v) => { setView(v); setOpen(false); }).catch((e) => setProblem(errorMessage(e)));
  };
  return (
    <span className="window-share" data-hit>
      <button type="button" className={`window-share-badge ${view.state}`} data-testid="window-share-badge" data-hit aria-label={view.badge} title={view.badge}
        aria-expanded={open} onClick={() => setOpen((o) => !o)}>
        <span aria-hidden="true">{view.state === "hidden" || view.state === "not_open" ? "◐" : view.state === "shown" ? "◉" : view.state === "black_box" ? "■" : "!"}</span>
        {!compact || view.state === "could_not_hide" ? <span className="window-share-label">{view.state === "could_not_hide" ? view.badge : SHORT[view.state]}</span> : null}
      </button>
      {open && (
        <span className="window-share-menu" role="menu" data-hit>
          <span className="window-share-now">{view.badge}</span>
          <button type="button" role="menuitem" className="btn compact" onClick={flip}>{view.switchLabel}</button>
          {problem && <span className="privacy-line warning" role="alert">{problem}</span>}
        </span>
      )}
    </span>
  );
}

/** Where showing it is done: on that window's own badge, never from here. */
export const SHOW_FROM_BADGE: Record<PinnedWindow, string> = {
  card: "Show it from the card's own menu",
  captions: "Show them from the captions' own menu",
};

/** The card's or the captions' state in a row's value (FIX-01): hidden unless the person showed it from its window. */
export function pinnedValue(view: Pick<WindowShareView, "state"> | null): string {
  switch (view?.state) {
    case "shown": return "Shown";
    case "black_box": return "Black box";
    case "could_not_hide": return "Couldn't be hidden";
    default: return "Hidden";
  }
}

/** Settings: the card's or the captions' real state, from the main window (it names the window). From here it can only
 * be hidden again; showing it is done on that window's own badge. */
export function PinnedShareControl({ which }: { which: PinnedWindow }) {
  const [view, setView] = useWindowShare(which);
  const [problem, setProblem] = useState<string | null>(null);
  if (!view) return null;
  const hide = () => {
    setProblem(null);
    void api.privacyWindowShareSet(false, which).then(setView).catch((e) => setProblem(errorMessage(e)));
  };
  return (
    <>
      <span className={`window-share-badge ${view.state}`} data-testid={`window-share-${which}`}>{view.badge}</span>
      {view.shown
        ? <button type="button" className="btn compact" onClick={hide}>{view.switchLabel}</button>
        : <span className="set-value">{SHOW_FROM_BADGE[which]}</span>}
      {problem && <span className="privacy-line warning" role="alert">{problem}</span>}
    </>
  );
}
