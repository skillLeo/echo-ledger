import { useCallback, useEffect, useRef, useState } from "react";
import { listen } from "@tauri-apps/api/event";
import { announceReady, api, errorMessage, listenForFileDrops, listenTo, type AppStatus, type LockStatus } from "../../lib/ipc";
import { loadMainView, rememberMainView, restoreScroll, trackScroll, type MainTab, type MainView } from "../../lib/mainView";
import { activityReporter, LockScreen } from "./LockScreen";
import { ActivityView, useActivityUnread } from "./ActivityCentre";
import { OnboardingView } from "./OnboardingView";
import { AlertIcon, BellIcon, CaptionsIcon, CloseIcon, LibraryIcon, MicIcon, MonitorIcon, MoonIcon, SettingsIcon, SidebarIcon, SunIcon, UsersIcon } from "../../components/Icons";
import { applyTheme, getThemeChoice, nextThemeChoice, saveThemeChoice, type ThemeChoice } from "../../lib/theme";
import { PairingView } from "./PairingView";
import { LibraryView } from "./LibraryView";
import type { PendingFocus } from "./LibraryQueue";
import { RecordView } from "./RecordView";
import { SettingsView } from "./SettingsView";
import { ClientsView } from "./ClientsView";
import { CommandPalette } from "./CommandPalette";
import { ImportSheet } from "./ImportSheet";
import { useWindowShown } from "../../lib/visibility";
import { fileDropClaim } from "../../lib/dropTarget";

type Tab = MainTab;
const TABS: readonly Tab[] = ["library", "clients", "record", "activity", "settings"];
const NAV: readonly [Tab, string, typeof LibraryIcon][] = [
  ["library", "Library", LibraryIcon],
  ["clients", "Clients", UsersIcon],
  ["record", "Record", MicIcon],
  ["activity", "Activity", BellIcon],
  ["settings", "Settings", SettingsIcon],
];

const BUDGET_REACHED = "Monthly transcription limit reached. Recordings are kept but not transcribed.";
const BUDGET_WARN = "You have used 80% of this month's transcription limit.";
/** Whether the sidebar shows icons only; kept on this computer like the theme. */
const SIDEBAR_KEY = "echoledger-sidebar";

/** A key typed into a field, a select or editable text is the field's, never a shortcut. */
export function typingIn(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false;
  return target.isContentEditable || ["INPUT", "TEXTAREA", "SELECT"].includes(target.tagName);
}

export default function MainApp() {
  const [status, setStatus] = useState<AppStatus | null>(null);
  const [tab, setTab] = useState<Tab>("library");
  const [collapsed, setCollapsed] = useState(() => {
    try {
      return window.localStorage.getItem(SIDEBAR_KEY) === "collapsed";
    } catch {
      return false;
    }
  });
  const toggleSidebar = () => {
    setCollapsed((c) => {
      try {
        window.localStorage.setItem(SIDEBAR_KEY, c ? "expanded" : "collapsed");
      } catch {
        // Storage can be unavailable; the choice then lasts until the app restarts.
      }
      return !c;
    });
  };
  const [unread, setUnread] = useActivityUnread();
  // A client page opened from elsewhere (a recording's client chip, the palette).
  const [clientRequest, setClientRequest] = useState<{ id: string; seq: number } | null>(null);
  const [toast, setToast] = useState<{ text: string; error?: boolean } | null>(null);
  const [theme, setTheme] = useState<ThemeChoice>(getThemeChoice);
  const [focus, setFocus] = useState<PendingFocus | null>(null);
  const navigations = useRef(0);
  // The 80% notice can be dismissed until the app restarts; the limit-reached banner stays.
  const [budgetWarnDismissed, setBudgetWarnDismissed] = useState(false);
  const [palette, setPalette] = useState(false);
  const [openRequest, setOpenRequest] = useState<{ id: string; ms: number; seq: number } | null>(null);
  const [lock, setLock] = useState<LockStatus | null>(null);
  const [setupDone, setSetupDone] = useState<boolean | null>(null);
  // C9: a new main window opens where the last one was closed (page, open recording, scroll).
  const [restored, setRestored] = useState(false);
  const [restoredRecording, setRestoredRecording] = useState<string | null>(null);
  const restoredScroll = useRef<MainView["scroll"] | null>(null);
  // Import: the files of the open import sheet, and whether files are being dragged over the window.
  const [importPaths, setImportPaths] = useState<string[] | null>(null);
  const [dragging, setDragging] = useState(false);
  // Drops count only on the app itself, never on the lock screen, the pairing form or the first-run wizard.
  const acceptDrops = useRef(false);
  acceptDrops.current = !!status?.paired && restored && !lock?.locked && setupDone !== false;

  // Files dropped anywhere on the window open the import sheet (the core no longer imports a drop by itself).
  useEffect(() => {
    const un = listenForFileDrops({
      // A page that takes drops itself (a client's Files tab) shows its own drop target.
      onEnter: () => setDragging(acceptDrops.current && !fileDropClaim()),
      onLeave: () => setDragging(false),
      onDrop: (paths) => {
        setDragging(false);
        if (paths.length === 0 || !acceptDrops.current) return;
        const claim = fileDropClaim();
        if (claim) claim(paths);
        else setImportPaths(paths);
      },
    });
    return () => {
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);

  // First-run wizard (B19): shown until it is finished or skipped.
  useEffect(() => {
    api.settings().then((s) => setSetupDone(s.onboardingDone !== false)).catch(() => undefined);
    const un = listenTo("settings:changed", (s) => s && setSetupDone(s.onboardingDone !== false));
    return () => {
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);

  // App lock (B16): the core decides; the window follows "lock:changed" and reports use to reset the timer.
  useEffect(() => {
    api.lockStatus().then(setLock).catch(() => undefined);
    const report = activityReporter(() => void api.lockTouch().catch(() => undefined));
    window.addEventListener("pointerdown", report);
    window.addEventListener("keydown", report);
    const un = listenTo("lock:changed", (s) => s && setLock(s));
    return () => {
      window.removeEventListener("pointerdown", report);
      window.removeEventListener("keydown", report);
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);

  // Ctrl / Cmd + K opens search (the command palette) from anywhere in the window; N starts or stops a voice note.
  // Space and J / L belong to the player of the open recording (LibraryView).
  const statusRef = useRef(status);
  statusRef.current = status;
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && !e.altKey && e.key.toLowerCase() === "k") {
        e.preventDefault();
        setPalette((open) => !open);
        return;
      }
      if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey || e.repeat || typingIn(e.target)) return;
      if (e.key.toLowerCase() === "n" && statusRef.current?.paired && !document.querySelector(".palette-backdrop, .sheet-backdrop")) {
        const recording = statusRef.current.recording;
        e.preventDefault();
        // Like the Record page: a call or a captured voice note has to stop first.
        if (recording && recording.mode !== "mic_note") {
          setToast({ text: "A recording is running. Stop it before starting a voice note.", error: true });
          return;
        }
        setTab("record");
        void run(api.toggleMicNote, recording ? "Voice note saved" : "Recording a voice note. Press N again to stop.");
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const chooseTheme = (next: ThemeChoice) => {
    saveThemeChoice(next);
    applyTheme(next);
    setTheme(next);
    api.setWindowTheme(next).catch(() => undefined);
  };
  const cycleTheme = () => chooseTheme(nextThemeChoice(theme));

  const clearFocus = useCallback(() => setFocus(null), []);

  const refreshStatus = useCallback(() => {
    api.status().then(setStatus).catch(() => undefined);
  }, []);

  // C9: the status poll runs only while the window is on screen; the tray and events keep it current otherwise.
  const shown = useWindowShown();
  useEffect(() => {
    if (!shown) return;
    refreshStatus();
    const timer = window.setInterval(refreshStatus, 1500);
    return () => window.clearInterval(timer);
  }, [shown, refreshStatus]);

  useEffect(() => {
    let alive = true;
    const restore = loadMainView().then((view) => {
      if (!alive) return;
      if (view) {
        setTab(view.tab);
        setRestoredRecording(view.recordingId);
        restoredScroll.current = view.scroll;
      }
      setRestored(true);
    });
    const unlisten = [
      listen<AppStatus>("app:status", (e) => setStatus(e.payload)),
      listen<string>("app:error", (e) => setToast({ text: e.payload, error: true })),
      listen<string>("app:toast", (e) => setToast({ text: e.payload })),
      // Tray "Review recordings (N)" and the review card's Open / Rename (contract 4.5).
      listenTo("main:navigate", (nav) => {
        if (!nav || !TABS.includes(nav.view)) return;
        setTab(nav.view);
        const id = nav.pendingId;
        if (id) setFocus({ id, rename: !!nav.rename, seq: ++navigations.current });
        if (nav.recordingId) setOpenRequest({ id: nav.recordingId, ms: 0, seq: ++navigations.current });
      }),
    ];
    // After the kept view is applied, so a navigation sent while the window was built wins over it.
    announceReady([restore, ...unlisten], () => alive);
    return () => {
      alive = false;
      unlisten.forEach((p) => p.then((f) => f()).catch(() => undefined));
    };
  }, []);

  useEffect(() => trackScroll(), []);
  useEffect(() => {
    if (restored) rememberMainView({ tab });
  }, [restored, tab]);
  const rememberRecording = useCallback((id: string | null) => rememberMainView({ recordingId: id }), []);
  const paired = status?.paired === true;
  useEffect(() => {
    const scroll = restoredScroll.current;
    if (!restored || !paired || !scroll) return;
    restoredScroll.current = null;
    restoreScroll(scroll);
  }, [restored, paired]);

  useEffect(() => {
    if (!toast) return;
    const t = window.setTimeout(() => setToast(null), 4500);
    return () => window.clearTimeout(t);
  }, [toast]);

  const notify = (text: string, error = false) => setToast({ text, error });
  /** The Import button: the core's multi-select picker, then the sheet for the chosen files. */
  const pickImport = async () => {
    try {
      const paths = await api.pickImportFiles();
      if (paths.length > 0) setImportPaths(paths);
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };
  const closeImport = useCallback(() => setImportPaths(null), []);
  const run = async <T,>(fn: () => Promise<T>, ok?: string) => {
    try {
      const result = await fn();
      if (ok) notify(ok);
      refreshStatus();
      return result;
    } catch (e) {
      notify(errorMessage(e), true);
      return undefined;
    }
  };

  // Rendered in every branch: a pairing error, or "unpaired on the server" arriving with the status that sends the
  // window back to the pairing form, must stay visible (A6.DU1).
  const toastView = toast && <div className={`toast ${toast.error ? "error" : ""}`} role="status">{toast.text}</div>;

  if (lock?.locked) {
    return (
      <>
        <LockScreen status={lock} onUnlocked={setLock} />
        {toastView}
      </>
    );
  }

  if (status && setupDone === false) {
    return (
      <>
        <OnboardingView status={status} onStatus={setStatus} onFinished={() => setSetupDone(true)} notify={notify} />
        {toastView}
      </>
    );
  }

  if (!status || !status.paired || !restored) {
    return (
      <main className="gate">
        {status && !status.paired ? <PairingView onPaired={setStatus} notify={notify} /> : <div className="empty">Starting EchoLedger…</div>}
        {toastView}
      </main>
    );
  }

  const openRecording = (id: string, ms = 0) => {
    setTab("library");
    setOpenRequest({ id, ms, seq: ++navigations.current });
  };
  const openClient = (id: string) => {
    setTab("clients");
    setClientRequest({ id, seq: ++navigations.current });
  };

  return (
    <div className={`app ${collapsed ? "collapsed" : ""}`}>
      {/* macOS: the title bar is inset (traffic lights over the sidebar); this strip keeps the window draggable. */}
      <div className="titlebar-drag" data-tauri-drag-region aria-hidden="true" />
      <aside className="sidebar">
        <div className="brand">
          <span className="brand-mark" aria-hidden="true"><MicIcon size={14} /></span>
          <span className="brand-name">EchoLedger</span>
          <button type="button" className="sidebar-toggle" aria-label={collapsed ? "Expand the sidebar" : "Collapse the sidebar"} aria-expanded={!collapsed}
            title={collapsed ? "Expand" : "Collapse"} onClick={toggleSidebar}><SidebarIcon size={18} /></button>
        </div>
        <nav className="nav" aria-label="Main">
          {NAV.map(([id, label, Icon]) => (
            <button key={id} type="button" className={`nav-btn ${tab === id ? "active" : ""}`} aria-current={tab === id ? "page" : undefined}
              title={collapsed ? label : undefined} onClick={() => setTab(id)}>
              <Icon size={20} /><span className="nav-label">{label}</span>
              {id === "record" && status.recording && <span className="status-dot live" aria-hidden="true" />}
              {id === "activity" && unread > 0 && <><span className="nav-count" aria-hidden="true">{unread > 99 ? "99+" : unread}</span><span className="sr-only">, {unread} unread</span></>}
            </button>
          ))}
        </nav>
        <div className="sidebar-footer">
          <button className="theme-toggle" onClick={cycleTheme} aria-label={`Theme: ${theme}. Click to change.`}>
            {theme === "light" ? <SunIcon size={15} /> : theme === "dark" ? <MoonIcon size={15} /> : <MonitorIcon size={15} />}
            {theme === "system" ? "Theme: System" : theme === "light" ? "Theme: Light" : "Theme: Dark"}
          </button>
          {/* Mirrors the tray icon (A3.4): paused, decisions waiting, uploads stopped, uploading. */}
          {status.recording && status.paused && <div className="row footer-state"><span className="status-dot paused" />Paused</div>}
          {status.pendingDecisions > 0 && (
            <button type="button" className="footer-link" onClick={() => setTab("library")}>
              <span className="status-dot attention" />{status.pendingDecisions} waiting for your decision
            </button>
          )}
          {status.failedUploads > 0 && (
            <button type="button" className="footer-link" onClick={() => setTab("library")}>
              <span className="status-dot failed" />Uploads that need you
            </button>
          )}
          <div className="row"><CaptionsIcon size={14} />{status.captionsLive ? "Captions live" : "Captions off"}</div>
          <div>{status.deviceName}</div>
          <div className="small">{status.pendingUploads > 0 ? "Uploading…" : "All uploads done"}</div>
        </div>
      </aside>
      <main className={`content page-${tab} ${status.budget === "reached" || (status.budget === "warn" && !budgetWarnDismissed) ? "has-banner" : ""}`}>
        {status.budget === "reached" && (
          <div className="banner danger" data-testid="budget-banner"><AlertIcon size={16} /><span>{BUDGET_REACHED}</span></div>
        )}
        {status.budget === "warn" && !budgetWarnDismissed && (
          <div className="banner warn" data-testid="budget-banner">
            <AlertIcon size={16} /><span>{BUDGET_WARN}</span>
            <button type="button" className="banner-close" aria-label="Dismiss" onClick={() => setBudgetWarnDismissed(true)}><CloseIcon size={14} /></button>
          </div>
        )}
        {tab === "library" && (
          <LibraryView notify={notify} focus={focus} onFocusHandled={clearFocus} openRequest={openRequest}
            initialSelected={restoredRecording} onSelectedChange={rememberRecording} onImport={() => void pickImport()} onOpenClient={openClient} />
        )}
        {tab === "clients" && <ClientsView notify={notify} openRequest={clientRequest} onOpenRecording={openRecording} />}
        {tab === "record" && <RecordView status={status} run={run} onImport={() => void pickImport()} />}
        {tab === "activity" && (
          <ActivityView status={status} onUnread={setUnread} onOpenRecording={(id) => openRecording(id)}
            onReview={(id) => { setTab("library"); if (id) setFocus({ id, rename: false, seq: ++navigations.current }); }} />
        )}
        {tab === "settings" && <SettingsView status={status} run={run} onUnpaired={setStatus} theme={theme} onTheme={chooseTheme} />}
      </main>
      {palette && (
        <CommandPalette status={status} onClose={() => setPalette(false)} onGo={setTab}
          run={(fn, ok) => void run(fn, ok)}
          onOpenRecording={(id, ms) => openRecording(id, ms)} />
      )}
      {dragging && !importPaths && (
        <div className="drop-hint" aria-hidden="true"><div className="drop-hint-card">Drop voice notes, audio or video files to import them</div></div>
      )}
      {importPaths && <ImportSheet paths={importPaths} notify={notify} onClose={closeImport} />}
      {toastView}
    </div>
  );
}
