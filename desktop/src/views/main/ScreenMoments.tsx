// Screen moments in the main window (docs/API.md "Screen moments", since v1.5): the thumbnails the recording page puts
// between its transcript lines, the recording's gallery, the viewer (the picture enlarged, previous / next, Play from
// here) and a project's Screens tab, read page by page. Pictures come through the core from their signed links. What the
// new design shows of it (a project's screens, on its Files tab: ProjectScreens, its thumbnails and its viewer) says its
// words in `lang`, the window's by default; classic's screens pass "en".
import { useCallback, useEffect, useRef, useState, type KeyboardEvent } from "react";
import { createPortal } from "react-dom";
import { api, errorMessage, type ClientFolder, type ProjectScreen, type ProjectScreensPage, type ScreenMoment } from "../../lib/ipc";
import { formatWhen, timestamp } from "../../lib/format";
import { recordingName } from "../../lib/library";
import { screenPicture, screensBySession } from "../../lib/screens";
import { ChevronRightIcon, CloseIcon, MonitorIcon, TrashIcon } from "../../components/Icons";
import { ChevronLeftIcon, PlayIcon } from "../../components/ControlIcons";
import type { Notify } from "../../lib/notice";
import { isolate, isRtl, lineDir, useLanguage, wordsIn, type Lang } from "../../i18n";

/** "Screen 7 · 14:32" in `lang` (lib/screens' screenLabel in English). */
const labelIn = (screen: Pick<ScreenMoment, "index" | "at_ms">, lang?: Lang): string =>
  wordsIn(lang).t("Screen :index · :time", { index: screen.index, time: timestamp(screen.at_ms) });
/** A recording's title as it is, or "Recording" in `lang` (lib/library's recordingName in English). */
const titleIn = (title: string | null | undefined, lang?: Lang): string => (title?.trim() ? recordingName(title) : wordsIn(lang).t("Recording"));
/** Whether words in `lang` are right to left (the window's language unless "en"). */
const rtlIn = (lang?: Lang): boolean => lang !== "en" && isRtl();

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

/** A thumbnail with its label ("Screen 7 · 14:32"); a click opens the viewer. Its words in `lang` (classic's: "en"). */
export function ScreenThumb({ screen, onOpen, lang }: { screen: ScreenMoment; onOpen: () => void; lang?: Lang }) {
  useLanguage();
  const { t } = wordsIn(lang);
  const { src, failed } = usePicture(screen.thumb_url);
  const label = labelIn(screen, lang);
  return (
    <button type="button" className="screen-thumb" onClick={onOpen} title={screen.description ?? label}
      aria-label={screen.description ? t("Screen :index · :time: :about", { index: screen.index, time: timestamp(screen.at_ms), about: screen.description }) : label}>
      <span className="screen-thumb-img" aria-hidden="true">
        {src ? <img src={src} alt="" /> : failed ? <MonitorIcon size={20} /> : <span className="skeleton" />}
      </span>
      <span className="screen-thumb-label">{label}</span>
    </button>
  );
}

/** The thumbnails a transcript line is followed by (classic's recording page: English). */
export function LineScreens({ screens, onOpen }: { screens: ScreenMoment[]; onOpen: (screen: ScreenMoment) => void }) {
  return (
    <div className="line-screens" role="list" aria-label="Screens at this moment">
      {screens.map((s) => <span role="listitem" key={s.id}><ScreenThumb screen={s} onOpen={() => onOpen(s)} lang="en" /></span>)}
    </div>
  );
}

/** Every screen of the recording as a grid (the transcript's Screens button opens it; classic's recording page: English). */
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
        {screens.map((s) => <span role="listitem" key={s.id}><ScreenThumb screen={s} onOpen={() => onOpen(s)} lang="en" /></span>)}
      </div>
    </section>
  );
}

/**
 * The enlarged screen: previous / next (also the arrow keys), its description and the text on it, Play from here and,
 * on the recording page, Delete. Escape or a click beside it closes it, and the focus goes back where it was. A picture
 * that cannot be fetched (its signed link is valid for 30 minutes) has the list read again once, for fresh links. Its
 * words in `lang` (classic's recording page: "en").
 */
export function ScreenViewer({ screens, index, onIndex, onClose, onPlay, onDelete, heading, onExpired, lang }: {
  screens: ScreenMoment[];
  index: number;
  onIndex: (index: number) => void;
  onClose: () => void;
  /** Plays the recording from the screen's moment (the recording page seeks, a project opens the recording there). */
  onPlay?: (screen: ScreenMoment) => void;
  onDelete?: (screen: ScreenMoment) => void;
  /** A line under the label (a project's viewer names the recording). */
  heading?: (screen: ScreenMoment) => string | null;
  /** Reads the screens again for fresh links (asked once per viewer). */
  onExpired?: () => void;
  lang?: Lang;
}) {
  useLanguage();
  const { t, tc } = wordsIn(lang);
  const at = Math.max(0, Math.min(index, screens.length - 1));
  const screen = screens[at] as ScreenMoment | undefined;
  const { src, failed } = usePicture(screen?.image_url ?? null);
  const box = useRef<HTMLDivElement>(null);
  const renewed = useRef(false);
  useEffect(() => {
    const before = document.activeElement as HTMLElement | null;
    box.current?.focus();
    return () => before?.focus?.();
  }, []);
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
  const onKey = (e: KeyboardEvent) => {
    if (e.key === "Escape") onClose();
    else if (e.key === "ArrowLeft") step(-1);
    else if (e.key === "ArrowRight") step(1);
    else return;
    e.preventDefault();
    e.stopPropagation();
  };
  const sub = heading?.(screen) ?? null;
  const label = labelIn(screen, lang);
  const rtl = rtlIn(lang);
  return createPortal(
    <div className="sheet-backdrop screen-viewer-backdrop" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}>
      <div ref={box} className="sheet card screen-viewer" role="dialog" aria-modal="true" aria-label={label} tabIndex={-1} onKeyDown={onKey}>
        <div className="row screen-viewer-head">
          <div className="screen-viewer-title">
            <h2 className="sheet-title" dir={rtl ? lineDir(label) : undefined}>{label}</h2>
            {/* The recording's title (in its isolate) and when, in that order: an Urdu line, right to left. */}
            {sub && <div className="small muted" dir={rtl ? "rtl" : undefined}>{sub}</div>}
          </div>
          <span className="spacer" />
          <span className="small muted" aria-live="polite">{tc(":n of :count", screens.length, { n: at + 1 })}</span>
          <button type="button" className="btn ghost icon-only" aria-label={t("Close")} title={t("Close")} onClick={onClose}><CloseIcon size={16} /></button>
        </div>
        {/* The screens run in time order, left to right in every language (D4 §7), as ← and → step through them. */}
        <div className="screen-viewer-body" dir="ltr">
          <button type="button" className="btn ghost icon-only screen-step" aria-label={t("Previous screen")} title={t("Previous screen")} disabled={at === 0} onClick={() => step(-1)}>
            <ChevronLeftIcon size={20} />
          </button>
          <div className="screen-viewer-picture" data-testid="screen-picture">
            {src ? <img src={src} alt={screen.description?.trim() || label} width={screen.width || undefined} height={screen.height || undefined} />
              : failed ? <span className="small muted">{t("The picture could not be loaded.")}</span>
                : <span className="spinner" aria-label={t("Loading the picture")} />}
          </div>
          <button type="button" className="btn ghost icon-only screen-step" aria-label={t("Next screen")} title={t("Next screen")} disabled={at === screens.length - 1} onClick={() => step(1)}>
            <ChevronRightIcon size={20} />
          </button>
        </div>
        {screen.description && <p className="screen-viewer-about" dir="auto">{screen.description}</p>}
        {screen.ocr_text && (
          <details className="screen-viewer-text">
            <summary>{t("Text on the screen")}</summary>
            <p dir="auto">{screen.ocr_text}</p>
          </details>
        )}
        {(onPlay || onDelete) && (
          <div className="row sheet-actions">
            {onPlay && <button type="button" className="btn primary" onClick={() => onPlay(screen)}><PlayIcon size={14} />{t("Play from here")}</button>}
            <span className="spacer" />
            {onDelete && <button type="button" className="btn ghost danger" onClick={() => onDelete(screen)}><TrashIcon size={15} />{t("Delete screen")}</button>}
          </div>
        )}
      </div>
    </div>,
    document.body,
  );
}

/** "Kick-off · 4 Nov" under a project screen's label; in a right-to-left language the title sits in its isolate. */
function sessionLine(s: ProjectScreen, lang?: Lang): string {
  const when = s.session_started_at ? formatWhen(s.session_started_at) : "";
  const title = titleIn(s.session_title, lang);
  return [rtlIn(lang) && s.session_title?.trim() ? isolate(title) : title, when].filter(Boolean).join(" · ");
}

/**
 * A project's Screens tab (`GET /api/clients/{id}/screens`, its subfolders too): the screens of each recording in a
 * grid, newest recording first, 60 at a time with Show more; a click opens the viewer, whose Play from here opens the
 * recording at that moment. With the server's `screens` switch, the project can keep screens of its meetings or not.
 * Its words are in `lang`, the window's by default (the new design's project page, on its Files tab); classic's
 * project page passes "en". The project's and the recordings' names are as they are.
 */
export function ProjectScreens({ client, first, notify, onOpenRecording, onClientChanged, lang }: {
  client: ClientFolder;
  /** The first page, read when the project opened (it also told whether the server has screen moments). */
  first: ProjectScreensPage;
  notify: Notify;
  onOpenRecording: (id: string, ms?: number) => void;
  onClientChanged: (client: Partial<ClientFolder> & { id: string }) => void;
  lang?: Lang;
}) {
  useLanguage();
  const { t } = wordsIn(lang);
  const rtl = rtlIn(lang);
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

  // The links of the first page, read again when a picture's link has expired.
  const renew = useCallback(() => {
    api.clientScreens(client.id, true, null).then((page) => {
      if (!page) return;
      const fresh = new Map(page.data.map((s) => [s.id, s]));
      setScreens((list) => list.map((s) => (fresh.has(s.id) ? { ...s, image_url: fresh.get(s.id)!.image_url, thumb_url: fresh.get(s.id)!.thumb_url } : s)));
    }).catch(() => undefined);
  }, [client.id]);

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
  const hint = keeps ? t("The desktop keeps what was on screen during :name's meetings.", { name: client.name }) : t("Nothing on screen is kept for meetings filed under :name.", { name: client.name });
  const empty = t("When a meeting of :name is recorded on the desktop, what was on the shared screen shows here, next to its moment in the transcript.", { name: client.name });
  return (
    <>
      {keeps !== undefined && (
        <section className="ws-section" aria-label={t("Screen moments of this project")}>
          <div className="card">
            <div className="toggle">
              <div>
                <label htmlFor={switchId}>{t("Keep screen moments")}</label>
                <div className="small muted" id={`${switchId}-hint`} dir={rtl ? lineDir(hint) : undefined}>{hint}</div>
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
          <span className="empty-title">{t("No screen moments yet")}</span>
          <p dir={rtl ? lineDir(empty) : undefined}>{empty}</p>
        </div>
      ) : (
        <div className="project-screens" data-testid="project-screens">
          {screensBySession(screens).map((group) => (
            <section key={group.sessionId} className="ws-section" aria-label={titleIn(group.title, lang)}>
              <h3>
                <span className="project-screens-title" dir={rtl ? "auto" : undefined}>{titleIn(group.title, lang)}</span>
                {group.startedAt && <span className="small muted">{formatWhen(group.startedAt)}</span>}
              </h3>
              <div className="screen-grid" role="list">
                {group.screens.map((s) => (
                  <span role="listitem" key={s.id}><ScreenThumb screen={s} onOpen={() => setOpen(screens.indexOf(s))} lang={lang} /></span>
                ))}
              </div>
            </section>
          ))}
          {cursor && (
            <div className="row" style={{ justifyContent: "center" }}>
              <button type="button" className="btn outline" disabled={loading} onClick={() => void more()}>{loading ? t("Loading…") : t("Show more")}</button>
            </div>
          )}
        </div>
      )}
      {open !== null && screens[open] && (
        <ScreenViewer screens={screens} index={open} onIndex={setOpen} onClose={() => setOpen(null)} onExpired={renew} lang={lang}
          heading={(s) => sessionLine(s as ProjectScreen, lang)}
          onPlay={(s) => { setOpen(null); onOpenRecording((s as ProjectScreen).session_id, s.at_ms); }} />
      )}
    </>
  );
}
