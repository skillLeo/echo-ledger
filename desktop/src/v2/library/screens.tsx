// Meeting assets in the v2 look (v1.8 P4.3, as the phone shows them): the screens mark on a Library row, the Screens
// strip under a recording's title, the screens between its transcript lines at their moments, and the viewer with
// what was said around each screen, Play from here and Delete. Pictures come through the core from their signed links
// (today's usePicture); a link that has run out (30 minutes) has the list read again once, for fresh links.
import { useEffect, useRef, type KeyboardEvent } from "react";
import type { ScreenMoment } from "../../lib/ipc";
import { saidLines, screenAlt, screenLabel } from "../../lib/screens";
import { usePicture } from "../../views/main/ScreenMoments";
import { Button, Dialog, Icon, Icons } from "../components";

/** The Library row's mark: a screen icon and the count, read as "3 screens". */
export function ScreensMark({ count }: { count: number }) {
  if (count <= 0) return null;
  return (
    <span className="el-screens-mark">
      <Icon icon={Icons.ScreenIcon} />
      <span className="el-num" aria-hidden="true">{count}</span>
      <span className="el-visually-hidden">{count === 1 ? "1 screen" : `${count} screens`}</span>
    </span>
  );
}

/** A screen's thumbnail with its label ("Screen 7 · 14:32"); a click opens the viewer. */
export function ScreenThumb({ screen, onOpen }: { screen: ScreenMoment; onOpen: () => void }) {
  const { src, failed } = usePicture(screen.thumb_url);
  const label = screenLabel(screen);
  return (
    <button type="button" className="el-screen-thumb" onClick={onOpen} aria-label={screen.description?.trim() ? `${label}: ${screen.description.trim()}` : label}>
      <span className="el-screen-thumb__picture" aria-hidden="true">
        {src ? <img src={src} alt="" /> : failed ? <Icon icon={Icons.ScreenIcon} size="md" /> : <span className="el-skeleton el-screen-thumb__wait" />}
      </span>
      <span className="el-screen-thumb__label el-caption">{label}</span>
    </button>
  );
}

/** The Screens strip under a recording's title: every screen in time order, scrolled sideways. */
export function ScreensStrip({ screens, onOpen }: { screens: ScreenMoment[]; onOpen: (index: number) => void }) {
  if (screens.length === 0) return null;
  return (
    <section className="el-screens-strip" aria-labelledby="el-screens-strip-title">
      <h2 className="el-body-strong el-screens-strip__title" id="el-screens-strip-title" dir="auto">{screens.length === 1 ? "1 screen" : `${screens.length} screens`}</h2>
      <ul className="el-screens-strip__list">
        {screens.map((s, i) => <li key={s.id}><ScreenThumb screen={s} onOpen={() => onOpen(i)} /></li>)}
      </ul>
    </section>
  );
}

/** The screens a transcript line is followed by. */
export function LineScreens({ screens, onOpen }: { screens: ScreenMoment[]; onOpen: (screen: ScreenMoment) => void }) {
  return (
    <ul className="el-line-screens" aria-label="Screens at this moment">
      {screens.map((s) => <li key={s.id}><ScreenThumb screen={s} onOpen={() => onOpen(s)} /></li>)}
    </ul>
  );
}

/**
 * The screen enlarged: previous and next (also the arrow keys), what it shows, what was said around it, the text on
 * it and its file name (the server's, once it sends one); Play from here and Delete screen.
 */
export function ScreenViewerV2({ screens, index, said, onIndex, onClose, onPlay, onDelete, onExpired }: {
  screens: ScreenMoment[];
  index: number;
  /** What was said around a screen ("Speaker: words" lines), the server's or worked out from the transcript. */
  said: (screen: ScreenMoment) => string | null;
  onIndex: (index: number) => void;
  onClose: () => void;
  onPlay?: (screen: ScreenMoment) => void;
  onDelete?: (screen: ScreenMoment) => void;
  onExpired?: () => void;
}) {
  const at = Math.max(0, Math.min(index, screens.length - 1));
  const screen = screens[at] as ScreenMoment | undefined;
  const { src, failed } = usePicture(screen?.image_url ?? null);
  const renewed = useRef(false);
  useEffect(() => {
    if (!failed || renewed.current || !onExpired) return;
    renewed.current = true;
    onExpired();
  }, [failed, onExpired]);
  if (!screen) return null;
  const step = (delta: 1 | -1) => {
    const next = at + delta;
    if (next >= 0 && next < screens.length) onIndex(next);
  };
  const onKey = (e: KeyboardEvent<HTMLDivElement>) => {
    // Arrows step through the screens unless a control that uses them has focus.
    if ((e.target as HTMLElement).closest("summary, input, textarea, select")) return;
    if (e.key !== "ArrowLeft" && e.key !== "ArrowRight") return;
    e.preventDefault();
    const rtl = getComputedStyle(e.currentTarget).direction === "rtl";
    step((e.key === "ArrowRight") !== rtl ? 1 : -1);
  };
  const lines = saidLines(said(screen));
  return (
    <Dialog open wide title={screenLabel(screen)} description={screen.description?.trim() || undefined} onClose={onClose}
      actions={(onPlay || onDelete) && (
        <>
          {onDelete && <Button variant="danger" icon={Icons.TrashIcon} onClick={() => onDelete(screen)}>Delete screen</Button>}
          {onPlay && <Button variant="primary" icon={Icons.PlayIcon} onClick={() => onPlay(screen)}>Play from here</Button>}
        </>
      )}>
      <div className="el-screen-viewer" onKeyDown={onKey}>
        <div className="el-screen-viewer__stage">
          <Button variant="quiet" icon={Icons.ChevronStartIcon} directional aria-label="Previous screen" disabled={at === 0} onClick={() => step(-1)} />
          <div className="el-screen-viewer__picture" data-testid="screen-picture">
            {src ? <img src={src} alt={screenAlt(screen)} width={screen.width || undefined} height={screen.height || undefined} />
              : failed ? <p className="el-secondary">The picture couldn't be loaded.</p>
                : <span className="el-skeleton el-screen-viewer__wait" role="img" aria-label="Loading the picture" />}
          </div>
          <Button variant="quiet" icon={Icons.ChevronEndIcon} directional aria-label="Next screen" disabled={at === screens.length - 1} onClick={() => step(1)} />
        </div>
        <p className="el-caption el-secondary" aria-live="polite">{at + 1} of {screens.length}</p>
        <section className="el-screen-viewer__said" aria-labelledby="el-screen-said">
          <h3 className="el-body-strong" id="el-screen-said">What was said</h3>
          {lines.length > 0 ? (
            <ul className="el-said">
              {lines.map((l, i) => (
                <li key={i} dir="auto">{l.speaker && <span className="el-body-strong">{l.speaker}: </span>}{l.text}</li>
              ))}
            </ul>
          ) : (
            <p className="el-secondary">Nothing was said within 15 seconds of this screen.</p>
          )}
        </section>
        {screen.ocr_text?.trim() && (
          <details className="el-more el-screen-viewer__text">
            <summary><Icon icon={Icons.ChevronEndIcon} directional />Text on the screen</summary>
            <p dir="auto">{screen.ocr_text}</p>
          </details>
        )}
        {screen.file_name && <p className="el-caption el-secondary">File name <span className="el-num" dir="ltr">{screen.file_name}</span></p>}
      </div>
    </Dialog>
  );
}
