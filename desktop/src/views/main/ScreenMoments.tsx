// Screen moments in the main window (docs/API.md "Screen moments", since v1.5): the thumbnails the recording page puts
// between its transcript lines, the recording's gallery, the viewer (the picture enlarged, previous / next, Play from
// here) and a project's Screens tab, read page by page. Pictures come through the core from their signed links.
import { useCallback, useEffect, useRef, useState, type KeyboardEvent } from "react";
import { createPortal } from "react-dom";
import { api, errorMessage, type ClientFolder, type ProjectScreen, type ProjectScreensPage, type ScreenMoment } from "../../lib/ipc";
import { formatWhen } from "../../lib/format";
import { screenAlt, screenLabel, screenPicture, screensBySession } from "../../lib/screens";
import { ChevronRightIcon, CloseIcon, MonitorIcon, TrashIcon } from "../../components/Icons";
import { ChevronLeftIcon, PlayIcon } from "../../components/ControlIcons";

type Notify = (text: string, error?: boolean) => void;

/** A screen's picture once it is fetched; `failed` when it could not be. */
export function usePicture(url: string | null): { src: string | null; failed: boolean } {
  const [state, setState] = useState<{ url: string | null; src: string | null; failed: boolean }>({ url: null, src: null, failed: false });
  useEffect(() => {
    if (!url) return;
    let live = true;
    screenPicture(url)
      .then((src) => { if (live) setState({ url, src, failed: false }); })
      .catch(() => { if (live) setState({ url, src: null, failed: true }); });
    return () => { live = false; };
  }, [url]);
  return state.url === url ? state : { src: null, failed: false };
}

/** A recording's screens (in time order), read when it has any; null while unknown or on a server without them. */
export function useSessionScreens(sessionId: string | null, count: number | undefined): { screens: ScreenMoment[] | null; reload: () => void } {
  const [screens, setScreens] = useState<{ id: string; list: ScreenMoment[] } | null>(null);
  const [attempt, setAttempt] = useState(0);
  const reload = useCallback(() => setAttempt((n) => n + 1), []);
  useEffect(() => {
    if (!sessionId || !count) return;
    let live = true;
    api.sessionScreens(sessionId)
      .then((page) => { if (live) setScreens({ id: sessionId, list: page?.data ?? [] }); })
      // The transcript works without them: no retry loop, the next open of the recording reads them again.
      .catch(() => { if (live) setScreens({ id: sessionId, list: [] }); });
    return () => { live = false; };
  }, [sessionId, count, attempt]);
  return { screens: sessionId && count && screens?.id === sessionId ? screens.list : null, reload };
}

/** A thumbnail with its label ("Screen 7 · 14:32"); a click opens the viewer. */
export function ScreenThumb({ screen, onOpen, caption }: { screen: ScreenMoment; onOpen: () => void; caption?: string }) {
  const { src, failed } = usePicture(screen.thumb_url);
  const label = screenLabel(screen);
  return (
    <button type="button" className="screen-thumb" onClick={onOpen} title={screen.description ?? label}
      aria-label={`${caption ?? label}${screen.description ? `: ${screen.description}` : ""}`}>
      <span className="screen-thumb-img" aria-hidden="true">
        {src ? <img src={src} alt="" /> : failed ? <MonitorIcon size={20} /> : <span className="skeleton" />}
      </span>
      <span className="screen-thumb-label">{caption ?? label}</span>
    </button>
  );
}

/** The thumbnails a transcript line is followed by. */
export function LineScreens({ screens, onOpen }: { screens: ScreenMoment[]; onOpen: (screen: ScreenMoment) => void }) {
  return (
    <div className="line-screens" role="list" aria-label="Screens at this moment">
      {screens.map((s) => <span role="listitem" key={s.id}><ScreenThumb screen={s} onOpen={() => onOpen(s)} /></span>)}
    </div>
  );
}

/** Every screen of the recording as a grid (the transcript's Screens button opens it). */
export function ScreenGallery({ screens, onOpen, onClose }: { screens: ScreenMoment[]; onOpen: (screen: ScreenMoment) => void; onClose: () => void }) {
  return (
    <section className="card screen-gallery" aria-label="Screens of this recording">
      <div className="row">
        <b>{screens.length === 1 ? "1 screen" : `${screens.length} screens`}</b>
        <span className="small muted">What the meeting showed, at its moments</span>
        <span className="spacer" />
        <button type="button" className="btn ghost compact" onClick={onClose}>Close</button>
      </div>
      <div className="screen-grid" role="list">
        {screens.map((s) => <span role="listitem" key={s.id}><ScreenThumb screen={s} onOpen={() => onOpen(s)} /></span>)}
      </div>
    </section>
  );
}

/**
 * The enlarged screen: previous / next (also the arrow keys), its description and the text on it, Play from here and,
 * on the recording page, Delete. Escape or a click beside it closes it.
 */
export function ScreenViewer({ screens, index, onIndex, onClose, onPlay, onDelete, heading }: {
  screens: ScreenMoment[];
  index: number;
  onIndex: (index: number) => void;
  onClose: () => void;
  /** Plays the recording from the screen's moment (the recording page seeks, a project opens the recording there). */
  onPlay?: (screen: ScreenMoment) => void;
  onDelete?: (screen: ScreenMoment) => void;
  /** A line under the label (a project's viewer names the recording). */
  heading?: (screen: ScreenMoment) => string | null;
}) {
  const at = Math.max(0, Math.min(index, screens.length - 1));
  const screen = screens[at] as ScreenMoment | undefined;
  const { src, failed } = usePicture(screen?.image_url ?? null);
  const box = useRef<HTMLDivElement>(null);
  useEffect(() => box.current?.focus(), []);
  if (!screen) return null;
  const step = (delta: 1 | -1) => {
    const next = at + delta;
    if (next >= 0 && next < screens.length) onIndex(next);
  };
  const onKey = (e: KeyboardEvent) => {
    if (e.key === "Escape") onClose();
    else if (e.key === "ArrowLeft") step(-1);
    else if (e.key === "ArrowRight") step(1);
    else return;
    e.preventDefault();
    e.stopPropagation();
  };
  const sub = heading?.(screen) ?? null;
  return createPortal(
    <div className="sheet-backdrop screen-viewer-backdrop" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}>
      <div ref={box} className="sheet card screen-viewer" role="dialog" aria-modal="true" aria-label={screenLabel(screen)} tabIndex={-1} onKeyDown={onKey}>
        <div className="row screen-viewer-head">
          <div className="screen-viewer-title">
            <h2 className="sheet-title">{screenLabel(screen)}</h2>
            {sub && <div className="small muted">{sub}</div>}
          </div>
          <span className="spacer" />
          <span className="small muted" aria-live="polite">{at + 1} of {screens.length}</span>
          <button type="button" className="btn ghost icon-only" aria-label="Close" title="Close" onClick={onClose}><CloseIcon size={16} /></button>
        </div>
        <div className="screen-viewer-body">
          <button type="button" className="btn ghost icon-only screen-step" aria-label="Previous screen" title="Previous screen" disabled={at === 0} onClick={() => step(-1)}>
            <ChevronLeftIcon size={20} />
          </button>
          <div className="screen-viewer-picture" data-testid="screen-picture">
            {src ? <img src={src} alt={screenAlt(screen)} width={screen.width || undefined} height={screen.height || undefined} />
              : failed ? <span className="small muted">The picture could not be loaded. Close and open it again to retry.</span>
                : <span className="spinner" aria-label="Loading the picture" />}
          </div>
          <button type="button" className="btn ghost icon-only screen-step" aria-label="Next screen" title="Next screen" disabled={at === screens.length - 1} onClick={() => step(1)}>
            <ChevronRightIcon size={20} />
          </button>
        </div>
        {screen.description && <p className="screen-viewer-about" dir="auto">{screen.description}</p>}
        {screen.ocr_text && (
          <details className="screen-viewer-text">
            <summary>Text on the screen</summary>
            <p dir="auto">{screen.ocr_text}</p>
          </details>
        )}
        {(onPlay || onDelete) && (
          <div className="row sheet-actions">
            {onPlay && <button type="button" className="btn primary" onClick={() => onPlay(screen)}><PlayIcon size={14} />Play from here</button>}
            <span className="spacer" />
            {onDelete && <button type="button" className="btn ghost danger" onClick={() => onDelete(screen)}><TrashIcon size={15} />Delete screen</button>}
          </div>
        )}
      </div>
    </div>,
    document.body,
  );
}

/** "Kick-off · 4 Nov" under a project screen's label. */
function sessionLine(s: ProjectScreen): string {
  const when = s.session_started_at ? formatWhen(s.session_started_at) : "";
  return [s.session_title || "Recording", when].filter(Boolean).join(" · ");
}

/**
 * A project's Screens tab (`GET /api/clients/{id}/screens`, its subfolders too): the screens of each recording in a
 * grid, newest recording first, 60 at a time with Show more; a click opens the viewer, whose Play from here opens the
 * recording at that moment. With the server's `screens` switch, the project can keep screens of its meetings or not.
 */
export function ProjectScreens({ client, first, notify, onOpenRecording, onClientChanged }: {
  client: ClientFolder;
  /** The first page, read when the project opened (it also told whether the server has screen moments). */
  first: ProjectScreensPage;
  notify: Notify;
  onOpenRecording: (id: string, ms?: number) => void;
  onClientChanged: (client: Partial<ClientFolder> & { id: string }) => void;
}) {
  const [screens, setScreens] = useState<ProjectScreen[]>(first.data);
  const [cursor, setCursor] = useState<string | null>(first.next_cursor);
  const [loading, setLoading] = useState(false);
  const [open, setOpen] = useState<number | null>(null);
  const [keeps, setKeeps] = useState<boolean | undefined>(client.screens);
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    setScreens(first.data);
    setCursor(first.next_cursor);
  }, [first]);
  useEffect(() => setKeeps(client.screens), [client.screens]);

  const more = async () => {
    if (!cursor || loading) return;
    setLoading(true);
    try {
      const page = await api.clientScreens(client.id, true, cursor);
      if (page) {
        setScreens((list) => [...list, ...page.data.filter((s) => !list.some((x) => x.id === s.id))]);
        setCursor(page.next_cursor);
      } else {
        setCursor(null);
      }
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setLoading(false);
    }
  };

  const toggle = async () => {
    const on = !keeps;
    setSaving(true);
    try {
      await api.updateClient(client.id, { screens: on });
      setKeeps(on);
      onClientChanged({ id: client.id, screens: on });
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setSaving(false);
    }
  };

  const switchId = `screens-keep-${client.id}`;
  return (
    <>
      {keeps !== undefined && (
        <section className="ws-section" aria-label="Screen moments of this project">
          <div className="card">
            <div className="toggle">
              <div>
                <label htmlFor={switchId}>Keep screen moments</label>
                <div className="small muted" id={`${switchId}-hint`}>
                  {keeps ? `The desktop keeps what was on screen during ${client.name}'s meetings.` : `Nothing on screen is kept for meetings filed under ${client.name}.`}
                </div>
              </div>
              <button id={switchId} type="button" role="switch" aria-checked={keeps} aria-describedby={`${switchId}-hint`}
                className={`switch ${keeps ? "on" : ""}`} disabled={saving} onClick={() => void toggle()} />
            </div>
          </div>
        </section>
      )}
      {screens.length === 0 ? (
        <div className="empty-state" data-testid="screens-empty">
          <span className="empty-icon"><MonitorIcon size={26} /></span>
          <span className="empty-title">No screen moments yet</span>
          <p>When a meeting of {client.name} is recorded on the desktop, what was on the shared screen shows here, next to its moment in the transcript.</p>
        </div>
      ) : (
        <div className="project-screens" data-testid="project-screens">
          {screensBySession(screens).map((group) => (
            <section key={group.sessionId} className="ws-section" aria-label={group.title || "Recording"}>
              <h3>
                <span className="project-screens-title">{group.title || "Recording"}</span>
                {group.startedAt && <span className="small muted">{formatWhen(group.startedAt)}</span>}
              </h3>
              <div className="screen-grid" role="list">
                {group.screens.map((s) => (
                  <span role="listitem" key={s.id}><ScreenThumb screen={s} onOpen={() => setOpen(screens.indexOf(s))} /></span>
                ))}
              </div>
            </section>
          ))}
          {cursor && (
            <div className="row" style={{ justifyContent: "center" }}>
              <button type="button" className="btn outline" disabled={loading} onClick={() => void more()}>{loading ? "Loading…" : "Show more"}</button>
            </div>
          )}
        </div>
      )}
      {open !== null && screens[open] && (
        <ScreenViewer screens={screens} index={open} onIndex={setOpen} onClose={() => setOpen(null)}
          heading={(s) => sessionLine(s as ProjectScreen)}
          onPlay={(s) => { setOpen(null); onOpenRecording((s as ProjectScreen).session_id, s.at_ms); }} />
      )}
    </>
  );
}
