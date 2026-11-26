// DES-02 + UX-04: Record at the top of the rail, a real split button. A joined pill, 56×40: the 40 px circle with its
// red dot records now (or, red while recording with ■, stops); the 16 px chevron behind a hairline opens "More ways to
// record", with its own hover and tooltip. "Record" under it (the time while recording). Right-click on either half opens
// the menu too. The menu is made from actions.json (UX-21); on a Mac the system draws it (native_menu.rs), elsewhere it
// is the page's own. Every word is the window's language (D-253).
import { useRef, useState } from "react";
import { Icon, Icons } from "../components";
import { ContextMenu, menuPointFor, type MenuItem } from "../components/Menu";
import { useTip } from "../components/Tip";
import { api } from "../../lib/ipc";
import { actionLabel, ariaKeys, nativeItems, shortcutOf, type ActionContext } from "../actions/registry";
import { isolate, isRtl, t } from "../../i18n";

/** Whether menus are the system's own here: the Mac app (the core marks <html data-platform="macos"> before the page). */
const nativeMenus = () => typeof document !== "undefined" && document.documentElement.dataset.platform === "macos";

export function RecordSplit({ stops, time, busy, onPress, menu, ctx }: {
  /** A recording runs: the press stops it. */
  stops: boolean;
  time?: string;
  /** The core is still answering the last press: more presses do nothing. */
  busy: boolean;
  onPress: () => void;
  /** The menu's items (registry menuItems: each sends its action id). */
  menu: (MenuItem & { action: string })[];
  ctx: ActionContext;
}) {
  const more = useRef<HTMLButtonElement>(null);
  const [at, setAt] = useState<{ x: number; y: number } | null>(null);
  const open = (point: { x: number; y: number }) => {
    if (nativeMenus()) {
      void api.nativeMenuPopup(nativeItems(menu), point.x, point.y).catch(() => setAt(point));
      return;
    }
    setAt(point);
  };
  const nowId = stops ? "live.stopRecording" : "file.newRecording";
  const keys = shortcutOf(nowId, ctx.platform);
  // The command in the window's language; its keys read left to right in every language (⌘N, never N⌘ after Urdu).
  const [nowTip, nowTipShown] = useTip(`${actionLabel(nowId, ctx)}${keys ? ` ${isRtl() ? isolate(keys) : keys}` : ""}`);
  const [moreTip, moreTipShown] = useTip(t("More ways to record"));
  const context = (e: React.MouseEvent) => {
    e.preventDefault();
    open({ x: e.clientX, y: e.clientY });
  };
  return (
    <div className={`el-rail__record${stops ? " el-rail__record--on" : ""}`} role="group" aria-label={t("Record")}>
      <div className="el-record__pill">
        <button type="button" className="el-record__now" aria-label={stops ? (time ? t("Stop recording, :time", { time }) : t("Stop recording")) : t("Record")}
          aria-keyshortcuts={keys ? ariaKeys(keys) : undefined} aria-disabled={busy || undefined}
          onKeyDown={(e) => { if (e.repeat) e.preventDefault(); }} onClick={onPress} onContextMenu={context} {...nowTip}>
          <span className={stops ? "el-record__stop" : "el-record__dot"} aria-hidden="true" />
        </button>
        <button type="button" ref={more} className="el-record__more" aria-label={t("More ways to record")} aria-haspopup="menu" aria-expanded={!!at}
          onClick={() => (at ? setAt(null) : more.current && open(menuPointFor(more.current)))} onContextMenu={context} {...moreTip}>
          <Icon icon={Icons.ChevronDownIcon} />
        </button>
      </div>
      <span className="el-rail__label" aria-hidden="true">{stops && time ? time : t("Record")}</span>
      {nowTipShown}
      {moreTipShown}
      {at && <ContextMenu label={t("More ways to record")} items={menu} at={at} onClose={() => setAt(null)} />}
    </div>
  );
}
