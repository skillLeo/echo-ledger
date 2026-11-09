import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from "react";
import { listen } from "@tauri-apps/api/event";
import { announceReady, api, errorMessage, listenForFileDrops, listenTo, type AppStatus, type ClientFolder, type LibraryItem, type LibraryViews, type LockStatus, type SmartFolder } from "../../lib/ipc";
import { loadMainView, rememberMainView, restoreScroll, trackScroll, type MainTab, type MainView } from "../../lib/mainView";
import { activityReporter, LockScreen } from "./LockScreen";
import { ActivityView, useActivityItems } from "./ActivityCentre";
import { actionCount } from "../../lib/badge";
import { COLUMN_CONTENT, loadCollapsed, loadSidebarWidth, NARROW_CONTENT, saveCollapsed, saveSidebarWidth, SIDEBAR_RAIL } from "../../lib/shell";
import { Sidebar, SHARED_TABS, type SharedTab, type ShellPage } from "./Sidebar";
import { TopBar } from "./TopBar";
import { HelpDialog } from "./HelpDialog";
import { LibraryColumn, ProjectsColumn } from "./SecondaryColumn";
import { HomeView } from "./HomeView";
import { SharedView } from "./SharedView";
import { RequestLinksSheet } from "./RequestLinks";
import { ACCOUNT_TABS, SIGN_OUT_CONFIRM } from "./SettingsView";
import { OnboardingView } from "./OnboardingView";
import { PermissionLossNotice } from "./Permissions";
import { AlertIcon } from "../../components/Icons";
import { applyTheme, getThemeChoice, saveThemeChoice, type ThemeChoice } from "../../lib/theme";
import { SignInView } from "./SignInView";
import { UpdateBanner } from "./UpdateSettings";
import { LibraryView } from "./LibraryView";
import type { PendingFocus } from "./LibraryQueue";
import { SettingsView } from "./SettingsView";
import { ProjectsView, useExpandedProjects } from "./ProjectsView";
import { folderLabel, LIBRARY_VIEWS, libraryPlaces, shownFolders, type LibraryPlace, type LibraryView as LibraryViewKey } from "../../lib/folders";
import { CommandPalette } from "./CommandPalette";
import { ImportSheet } from "./ImportSheet";
import { useWindowShown } from "../../lib/visibility";
import { fileDropClaim } from "../../lib/dropTarget";
import { isViewer, viewerSentence } from "../../lib/plan";
import { enterView, isConfirmation, MotionGroup, viewDirection } from "../../lib/motion";
import { SuccessCheck } from "../../components/SuccessCheck";
import type { Account, OneSided } from "../../lib/ipc";
import { OPEN_SECTION_EVENT, forgetEntitlements, refreshEntitlements } from "../../lib/entitlements";
import { UpgradeSheet } from "./Upgrade";
import { isHoldMessage, ServerBanners } from "./ServerBanners";
import { Notice } from "../../components/Notice";
import { StateBanner } from "../../components/StateBanner";
import { ERROR_MS, MESSAGE_MS, NO_TARGET, noticeOf, type NoticePayload, type NoticeTarget, type Notify } from "../../lib/notice";

type Tab = MainTab;
/** The pages in the sidebar's order (Home is "record", Account "settings"): a switch fades in from that side. */
const TABS: readonly Tab[] = ["record", "library", "projects", "activity", "shared", "settings"];
/** How long after the library changed the sidebar's project counts are read again (changes come in bursts). */
const RELOAD_AFTER_CHANGE_MS = 800;

const BUDGET_REACHED = "Monthly transcription limit reached. Recordings are kept but not transcribed.";
const BUDGET_WARN = "You have used 80% of this month's transcription limit.";

/**
 * Where a recording an app link stands for opens (the server answers `session` for this workspace's recordings and for
 * one a teammate sent): the Library when it is saved here (or after a sync, when it is new), Shared with me read-only
 * when it was sent, else nowhere in the app (a recording of a project a teammate sent): its web page opens instead.
 */
async function placeOfRecording(id: string): Promise<{ in: "library" } | { in: "shared"; itemId: string } | null> {
  const saved = (items: LibraryItem[]) => items.some((i) => i.id === id && !i.deleted);
  if (saved(await api.library().catch(() => []))) return { in: "library" };
  const sent = (await api.sharedWithMe().catch(() => null))?.data.find((i) => i.kind === "session" && i.session?.id === id);
  if (sent) return { in: "shared", itemId: sent.id };
  return saved(await api.refreshLibrary().catch(() => [])) ? { in: "library" } : null;
}

/** A key typed into a field, a select or editable text is the field's, never a shortcut. */
export function typingIn(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false;
  return target.isContentEditable || ["INPUT", "TEXTAREA", "SELECT"].includes(target.tagName);
}

export default function MainApp() {
  const [status, setStatus] = useState<AppStatus | null>(null);
  const [tab, setTab] = useState<Tab>("library");
  // The sidebar (v1.6, P4.1): 200–320 px wide or a 64 px icon rail, kept on this computer like the theme.
  const [collapsed, setCollapsed] = useState(loadCollapsed);
  const [sidebarWidth, setSidebarWidth] = useState(loadSidebarWidth);
  const toggleSidebar = useCallback(() => {
    setCollapsed((c) => {
      saveCollapsed(!c);
      return !c;
    });
  }, []);
  // The page's width (the window's less the sidebar's) decides whether the Library's columns fit (styles.css).
  const [windowWidth, setWindowWidth] = useState(() => window.innerWidth);
  useEffect(() => {
    const resized = () => setWindowWidth(window.innerWidth);
    window.addEventListener("resize", resized);
    return () => window.removeEventListener("resize", resized);
  }, []);
  const resizeSidebar = useCallback((width: number, done: boolean) => {
    setSidebarWidth(width);
    if (done) saveSidebarWidth(width);
  }, []);
  const [help, setHelp] = useState(false);
  const [requestSheet, setRequestSheet] = useState(false);
  // "+ New project" from the top bar or the Projects column: the Projects page opens its dialog.
  const [projectRequest, setProjectRequest] = useState<{ kind: "new"; seq: number } | null>(null);
  const [account, setAccount] = useState<Account | null | undefined>(undefined);
  // The Activity badge (v1.6, P6): only what needs the person (lib/badge.ts), never every unread notification.
  const [activityItems, setActivityItems] = useActivityItems();
  // Projects (since v1.5): the list the sidebar tree, the Projects page and the pickers share, and the open project.
  const [projects, setProjects] = useState<ClientFolder[] | null>(null);
  const [projectsError, setProjectsError] = useState<string | null>(null);
  const [projectId, setProjectId] = useState<string | null>(null);
  const [expanded, toggleExpanded] = useExpandedProjects();
  // Smart folders (since v1.5): their counts for the sidebar and the one the Library shows (null: every recording).
  const [views, setViews] = useState<LibraryViews | null>(null);
  const [libraryFolder, setLibraryFolder] = useState<SmartFolder | null>(null);
  // Waiting, Pinned and Failed uploads (v1.6, P4.2): views of the whole Library, not server folders.
  const [libraryView, setLibraryView] = useState<LibraryViewKey | null>(null);
  const [sharedTab, setSharedTab] = useState<SharedTab>("with-me");
  const [sharedFocus, setSharedFocus] = useState<string | null>(null);
  // An echoledger://open link waiting to be opened (since v1.5), and a request link it pointed at.
  const [linkRequest, setLinkRequest] = useState<{ url: string; seq: number } | null>(null);
  const [requestFocus, setRequestFocus] = useState<{ id: string; seq: number } | null>(null);
  // The toast (v1.6.1, DESIGN_SYSTEM.md "Notices"): one at a time, the newest; a click goes to its target.
  const [toast, setToast] = useState<{ id: number; text: string; error: boolean; target?: NoticeTarget } | null>(null);
  const toastSeq = useRef(0);
  // The server holding the work (maintenance, a refused version) is news, never a failure (v1.6, P2.7).
  const showToast = useCallback((text: string, error = false, target?: NoticeTarget) => {
    setToast({ id: ++toastSeq.current, text, error: error && !isHoldMessage(text), target });
  }, []);
  // After a call where one side was never heard (Phase 1C): why and how to fix it, until dismissed.
  const [oneSided, setOneSided] = useState<OneSided | null>(null);
  const [theme, setTheme] = useState<ThemeChoice>(getThemeChoice);
  const [focus, setFocus] = useState<PendingFocus | null>(null);
  const navigations = useRef(0);
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
  // Drops count only on the app itself, never on the lock screen, the sign-in form or the first-run wizard.
  const acceptDrops = useRef(false);
  acceptDrops.current = !!status?.paired && restored && !lock?.locked && setupDone !== false;
  // A viewer (v1.5) imports nothing: a drop or the Import button says why instead of opening the sheet.
  const viewer = useRef<string | null>(null);
  viewer.current = isViewer(status) ? viewerSentence(status?.plan.workspace) : null;

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
        else if (viewer.current) showToast(viewer.current, true, NO_TARGET);
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
      const open = !!document.querySelector(".palette-backdrop, .sheet-backdrop");
      // "[" folds the sidebar to its rail and back; "?" lists every key (v1.6, P4.1).
      if (e.key === "[" && statusRef.current?.paired && !open) {
        e.preventDefault();
        toggleSidebar();
        return;
      }
      if (e.key === "?" && statusRef.current?.paired && !open) {
        e.preventDefault();
        setHelp(true);
        return;
      }
      if (e.key.toLowerCase() === "n" && statusRef.current?.paired && !open) {
        const recording = statusRef.current.recording;
        e.preventDefault();
        // Like the Record page: a call or a captured voice note has to stop first.
        if (recording && recording.mode !== "mic_note") {
          showToast("A recording is running. Stop it before starting a voice note.", true, { kind: "page", page: "record" });
          return;
        }
        setTab("record");
        void run(api.toggleMicNote, recording ? "Voice note saved" : "Recording a voice note. Press N again to stop.",
          recording ? { kind: "library", view: null } : { kind: "page", page: "record" });
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

  const clearFocus = useCallback(() => setFocus(null), []);
  const clearRequestFocus = useCallback(() => setRequestFocus(null), []);

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
      // The core's notices: words, or words with where they go (v1.6.1).
      listen<NoticePayload>("app:error", (e) => { const n = noticeOf(e.payload); showToast(n.text, true, n.target); }),
      listen<NoticePayload>("app:toast", (e) => { const n = noticeOf(e.payload); showToast(n.text, false, n.target); }),
      listenTo("capture:one_sided", (said) => said && setOneSided(said)),
      // Tray "Review recordings (N)" and the after-call chip's title, which opens its title field (contract 4.5).
      listenTo("main:navigate", (nav) => {
        // A share, project or request link (echoledger://open): opened once the app is paired and unlocked.
        if (nav?.link) {
          setLinkRequest({ url: nav.link, seq: ++navigations.current });
          return;
        }
        if (!nav || !TABS.includes(nav.view)) return;
        setTab(nav.view);
        // The waiting recordings and a clicked notification's recording are in the whole Library, not a smart folder.
        if (nav.view === "library") {
          setLibraryFolder(null);
          setLibraryView(null);
          setSharedFocus(null);
        }
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
  // A page switch (since v1.5, the phone's tab rule): the new page fades in from the side its page sits on in the
  // sidebar. The whole page moves (its parts appear as their data comes); a click during the switch ends the last one
  // at once, so a page is never left half-faded.
  const contentRef = useRef<HTMLElement>(null);
  const shownTab = useRef(tab);
  const viewMotion = useMemo(() => new MotionGroup(), []);
  useLayoutEffect(() => {
    const from = shownTab.current;
    shownTab.current = tab;
    const content = contentRef.current;
    if (from === tab || !content) return;
    enterView(viewMotion, [content], viewDirection(TABS, from, tab));
  }, [tab, viewMotion]);
  useEffect(() => () => viewMotion.stop(), [viewMotion]);
  useEffect(() => {
    if (restored) rememberMainView({ tab });
  }, [restored, tab]);
  const rememberRecording = useCallback((id: string | null) => rememberMainView({ recordingId: id }), []);
  const paired = status?.paired === true;
  const unlocked = !lock?.locked;

  // The projects are read once the app is open, and again shortly after the library changes (a move changes counts).
  const loadProjects = useCallback(async () => {
    try {
      const r = await api.clients();
      setProjects(r.data ?? []);
      setProjectsError(null);
    } catch (e) {
      setProjectsError(errorMessage(e));
    }
  }, []);
  // How many recordings the Library holds (All in the column), from what is saved on this computer.
  const [libraryTotal, setLibraryTotal] = useState(0);
  // The smart folders' counts; a server older than v1.5 sends none and the sidebar lists no smart folders.
  const loadViews = useCallback(async () => {
    api.library().then((items) => setLibraryTotal(Array.isArray(items) ? items.filter((i) => !i.deleted).length : 0)).catch(() => undefined);
    let tz: string | null = null;
    try {
      tz = Intl.DateTimeFormat().resolvedOptions().timeZone ?? null;
    } catch {
      // No time zone: the server counts in its own.
    }
    try {
      setViews(await api.libraryViews(tz));
    } catch {
      // Offline: the last counts stay; the folders still open from what is saved here.
    }
  }, []);
  const ready = paired && restored && unlocked;
  useEffect(() => {
    if (!ready) return;
    void loadProjects();
    void loadViews();
    let timer = 0;
    const un = listen("library:updated", () => {
      window.clearTimeout(timer);
      timer = window.setTimeout(() => {
        void loadProjects();
        void loadViews();
      }, RELOAD_AFTER_CHANGE_MS);
    });
    return () => {
      window.clearTimeout(timer);
      un.then((f) => f()).catch(() => undefined);
    };
  }, [ready, loadProjects, loadViews]);
  const changeProjects = useCallback((change: (list: ClientFolder[]) => ClientFolder[]) => setProjects((list) => change(list ?? [])), []);

  // What this person may use (v1.6): read once signed in and whenever the window comes to the front; forgotten on
  // signing out. An older server answers none, and nothing is locked.
  useEffect(() => {
    if (!paired) {
      forgetEntitlements();
      return;
    }
    if (!ready) return;
    void refreshEntitlements();
    const again = () => {
      if (document.visibilityState !== "hidden") void refreshEntitlements();
    };
    window.addEventListener("focus", again);
    document.addEventListener("visibilitychange", again);
    return () => {
      window.removeEventListener("focus", again);
      document.removeEventListener("visibilitychange", again);
    };
  }, [paired, ready]);
  // The account (v1.5) for the top bar: the workspace, the avatar, staff; read once signed in, none from an older server.
  useEffect(() => {
    if (!paired) {
      setAccount(undefined);
      return;
    }
    if (!ready) return;
    let alive = true;
    api.account().then((a) => alive && setAccount(a ?? null), () => alive && setAccount(null));
    return () => {
      alive = false;
    };
  }, [paired, ready]);
  // "Add my Groq key" on a recording (and other places): Settings → Account → that section.
  const [settingsSection, setSettingsSection] = useState<{ id: string; seq: number } | null>(null);
  useEffect(() => {
    const open = (e: Event) => {
      const id = (e as CustomEvent<string>).detail;
      if (typeof id !== "string") return;
      setTab("settings");
      setSettingsSection({ id, seq: ++navigations.current });
    };
    window.addEventListener(OPEN_SECTION_EVENT, open);
    return () => window.removeEventListener(OPEN_SECTION_EVENT, open);
  }, []);

  // An app link (docs/API.md "Links in the apps"): the server says what it is in this workspace, and the window opens
  // the recording, the project, the item of Shared with me or the request link. A link the app cannot open in place
  // (someone else's, revoked, expired, or another server's) opens its web page in the browser instead.
  useEffect(() => {
    if (!linkRequest || !ready) return;
    const { url } = linkRequest;
    setLinkRequest(null);
    const inBrowser = async (why: string) => {
      await api.openLink(url);
      showToast(`Opened in your browser: ${why}`, false, NO_TARGET);
    };
    api.resolveLink(url).then(async (target) => {
      if (!target) {
        await inBrowser("this link is not one of your workspace's, or it no longer works.");
        return;
      }
      switch (target.kind) {
        case "session": {
          const place = await placeOfRecording(target.session_id);
          if (!place) {
            await inBrowser("this recording is not in your Library or in Shared with me.");
          } else if (place.in === "shared") {
            setTab("library");
            setLibraryFolder("shared");
            setLibraryView(null);
            setSharedFocus(place.itemId);
          } else {
            setTab("library");
            setLibraryFolder(null);
            setLibraryView(null);
            setSharedFocus(null);
            setOpenRequest({ id: target.session_id, ms: 0, seq: ++navigations.current });
          }
          break;
        }
        case "project":
          setTab("projects");
          setProjectId(target.client_id);
          break;
        case "shared":
          setTab("library");
          setLibraryFolder("shared");
          setLibraryView(null);
          setSharedFocus(target.shared_id);
          break;
        case "request":
          setTab("projects");
          setProjectId(null);
          setRequestFocus({ id: target.request_link_id, seq: ++navigations.current });
          break;
      }
    }).catch((e) => showToast(errorMessage(e), true, NO_TARGET));
  }, [linkRequest, ready]);
  useEffect(() => {
    const scroll = restoredScroll.current;
    if (!restored || !paired || !scroll) return;
    restoredScroll.current = null;
    restoreScroll(scroll);
  }, [restored, paired]);

  // Esc closes the newest toast before anything else hears it (an open dialog or menu keeps its own Esc).
  useEffect(() => {
    if (!toast) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== "Escape" || e.defaultPrevented || document.querySelector(".palette-backdrop, .sheet-backdrop, .menu-pop, .row-menu-pop")) return;
      e.preventDefault();
      e.stopPropagation();
      setToast(null);
    };
    window.addEventListener("keydown", onKey, true);
    return () => window.removeEventListener("keydown", onKey, true);
  }, [toast]);

  const notify: Notify = showToast;
  /** The Import button: the core's multi-select picker, then the sheet for the chosen files. */
  const pickImport = async () => {
    if (viewer.current) {
      notify(viewer.current, true);
      return;
    }
    try {
      const paths = await api.pickImportFiles();
      if (paths.length > 0) setImportPaths(paths);
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };
  const closeImport = useCallback(() => setImportPaths(null), []);
  const run = async <T,>(fn: () => Promise<T>, ok?: string, target?: NoticeTarget) => {
    try {
      const result = await fn();
      if (ok) notify(ok, false, target);
      refreshStatus();
      return result;
    } catch (e) {
      notify(errorMessage(e), true);
      return undefined;
    }
  };

  // Rendered in every branch: a pairing error, or "signed out on the server" arriving with the status that sends the
  // window back to the sign-in form, must stay visible (A6.DU1).
  // A message that confirms something carries the check that pops in (as on the phone).
  // Its target opens only in the main window itself (never on the lock screen, the sign-in or the first-run wizard).
  const goRef = useRef<((target: NoticeTarget) => void) | null>(null);
  const inApp = !!status?.paired && restored && !lock?.locked && setupDone !== false;
  const toastView = toast && (
    <div className="toast-region">
      <Notice key={toast.id} kind="toast" className={`toast ${toast.error ? "error" : ""}`} tone={toast.error ? "error" : "neutral"}
        role={toast.error ? "alert" : "status"} timeoutMs={toast.error ? ERROR_MS : MESSAGE_MS}
        icon={!toast.error && isConfirmation(toast.text) ? <SuccessCheck size={16} /> : undefined}
        onOpen={toast.target && inApp ? () => goRef.current?.(toast.target!) : undefined}
        onDismiss={() => setToast((t) => (t?.id === toast.id ? null : t))}>
        {toast.text}
      </Notice>
    </div>
  );

  /** After signing in, the Ready check (F1): the Record page opens with it. */
  const signedIn = (next: AppStatus) => {
    setStatus(next);
    setTab("record");
    void api.readyCheck().catch(() => undefined);
  };

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
        {status && !status.paired ? <SignInView onSignedIn={signedIn} notify={notify} serverUrl={status.serverUrl} /> : <div className="empty">Starting EchoLedger…</div>}
        {toastView}
      </main>
    );
  }

  const needsYou = actionCount(activityItems, status.pendingDecisions);
  const canRecord = !isViewer(status);
  /** The Library on a smart folder (null: every recording). */
  const openFolder = (folder: SmartFolder | null) => {
    setTab("library");
    setLibraryFolder(folder);
    setLibraryView(null);
    setSharedFocus(null);
  };
  /** The Library on Waiting, Pinned or Failed uploads (v1.6, P4.2). */
  const openLibraryView = (view: LibraryViewKey | null) => {
    setTab("library");
    setLibraryFolder(null);
    setLibraryView(view);
    setSharedFocus(null);
  };
  const openPlace = (place: LibraryPlace) => (place.view ? openLibraryView(place.view) : openFolder(place.folder));
  const openRecording = (id: string, ms = 0) => {
    openFolder(null);
    setOpenRequest({ id, ms, seq: ++navigations.current });
  };
  /** Opens a project's page (null: the list of projects). */
  const openProject = (id: string | null) => {
    setTab("projects");
    setProjectId(id);
  };
  const openShared = (next: SharedTab) => {
    setTab("shared");
    setSharedTab(next);
  };
  /** A page from the sidebar or the command palette: the whole Library, the list of projects, or another page. */
  const goTo = (id: Tab) => (id === "projects" ? openProject(null) : id === "library" ? openFolder(null) : setTab(id));
  /** Where a notice goes when it is clicked (v1.6.1): the recording, the page or setting, the fix. */
  goRef.current = (target: NoticeTarget) => {
    switch (target.kind) {
      case "recording": openRecording(target.id, target.ms ?? 0); break;
      case "page": goTo(target.page); break;
      case "account": openAccount(target.section); break;
      case "project": openProject(target.id); break;
      case "library": if (target.view) openLibraryView(target.view); else openFolder(target.folder ?? null); break;
      case "shared": openShared(target.tab); break;
      case "server": void api.openServerPage(target.page).catch((e) => notify(errorMessage(e), true, NO_TARGET)); break;
      case "action": target.run(); break;
    }
  };
  /** Account → a tab or a section of it (Devices, Plan & usage, Preferences…). */
  const openAccount = (section: string) => {
    setTab("settings");
    setSettingsSection({ id: section, seq: ++navigations.current });
  };
  const newProject = () => {
    setTab("projects");
    setProjectId(null);
    setProjectRequest({ kind: "new", seq: ++navigations.current });
  };
  const signOut = async () => {
    if (!window.confirm(SIGN_OUT_CONFIRM)) return;
    const next = await run(api.unpair);
    if (next) setStatus(next);
  };
  const record = () => void run(status.recording ? api.stopRecording : api.recordNow);
  const voiceNote = () => void run(api.toggleMicNote);

  // The Library's places with their counts: the server's, and what only this computer knows (v1.6, P4.2).
  const places = libraryPlaces(views, {
    total: libraryTotal,
    waiting: status.pendingDecisions,
    failed: status.failedUploads,
    pinned: 0,
  }, tab === "library" ? libraryFolder : null);
  // "Find a page" (v1.6): every page, place, project and Account tab by name.
  const pages: ShellPage[] = [
    { id: "home", label: "Home", keywords: "record start ready check calls voice note captions", open: () => goTo("record") },
    { id: "library", label: "Library", keywords: "recordings transcripts all", open: () => openFolder(null) },
    ...places.filter((p) => p.key !== "all").map((p) => ({ id: `place-${p.key}`, label: p.label, group: "Library", keywords: "smart folder recordings", open: () => openPlace(p) })),
    { id: "projects", label: "Projects", keywords: "clients folders", open: () => openProject(null) },
    ...(projects ?? []).map((p) => ({ id: `project-${p.id}`, label: p.name, group: "Projects", keywords: "project client", open: () => openProject(p.id) })),
    { id: "activity", label: "Activity", keywords: "notifications bell", open: () => goTo("activity") },
    ...SHARED_TABS.map(([id, label]) => ({ id: `shared-${id}`, label, group: "Shared", keywords: "share links shared", open: () => openShared(id) })),
    ...(canRecord ? [{ id: "upload", label: "Upload", keywords: "import files audio video", open: () => void pickImport() }] : []),
    ...ACCOUNT_TABS.map(([id, label]) => ({ id: `account-${id}`, label, group: "Account", keywords: "settings account preferences", open: () => openAccount(id) })),
    { id: "help", label: "Help & shortcuts", keywords: "keys keyboard", open: () => setHelp(true) },
  ];
  const pageWidth = windowWidth - (collapsed ? SIDEBAR_RAIL : sidebarWidth);
  // While the server holds the queue (v1.6, P2.7) the recordings wait; they are not on their way.
  const waitingFor = status.server?.waiting === "maintenance" ? "the server is in maintenance" : status.server?.waiting === "update_required" ? "update EchoLedger" : null;
  const uploading = status.pendingUploads === 0 ? null
    : waitingFor ? `${status.pendingUploads === 1 ? "1 recording waits" : `${status.pendingUploads} recordings wait`}: ${waitingFor}`
      : status.pendingUploads === 1 ? "Uploading 1 recording…" : `Uploading ${status.pendingUploads} recordings…`;
  const libraryCrumb = libraryView ? LIBRARY_VIEWS.find(([v]) => v === libraryView)?.[1] ?? null : libraryFolder ? folderLabel(libraryFolder) : null;

  return (
    <div className={`app ${collapsed ? "collapsed" : ""} ${pageWidth < NARROW_CONTENT ? "narrow" : ""} ${pageWidth < COLUMN_CONTENT ? "no-column" : ""}`}
      style={{ ["--sidebar-w" as string]: `${collapsed ? SIDEBAR_RAIL : sidebarWidth}px` }}>
      {/* macOS: the title bar is inset (traffic lights over the sidebar); this strip keeps the window draggable. */}
      <div className="titlebar-drag" data-tauri-drag-region aria-hidden="true" />
      <Sidebar tab={tab} collapsed={collapsed} onCollapse={toggleSidebar} width={sidebarWidth} onWidth={resizeSidebar} needsYou={needsYou}
        recording={!!status.recording} places={places} folder={libraryFolder} view={libraryView} onPlace={openPlace}
        projects={projects} projectId={projectId} onProject={openProject} sharedTab={tab === "shared" ? sharedTab : null} onShared={openShared}
        onGo={goTo} onUpload={() => void pickImport()} onHelp={() => setHelp(true)} pages={pages} uploading={uploading} uploadsWait={!!waitingFor} canUpload={canRecord} />
      <div className="main-col">
        <TopBar status={status} account={account} needsYou={needsYou} theme={theme} onTheme={chooseTheme} onPalette={() => setPalette(true)}
          onActivity={() => goTo("activity")} onAccount={() => openAccount("profile")} onPlan={() => openAccount("plan")}
          onAdmin={() => void api.openServerPage("admin").catch((e) => notify(errorMessage(e), true))}
          onWorkspaces={() => void api.openServerPage("app/workspace").catch((e) => notify(errorMessage(e), true))}
          onReady={() => goTo("record")} onRecord={record} onVoiceNote={voiceNote} onUpload={() => void pickImport()} onNewProject={newProject}
          onRequestLink={() => setRequestSheet(true)} onHelp={() => setHelp(true)} onSignOut={() => void signOut()} canRecord={canRecord} />
        <main ref={contentRef} className={`content page-${tab}`}>
          <UpdateBanner appStatus={status} notify={notify} onOpen={() => openAccount("about")} />
          <ServerBanners server={status.server} notify={notify} />
          {status.platform === "macos" && <PermissionLossNotice />}
          {/* The last call's side that was never heard (Phase 1C): a click opens the Ready check with its fix. */}
          {oneSided && (
            <Notice kind="banner" tone="warn" className="banner warn one-sided-banner" role="alert" testId="one-sided" icon={<AlertIcon size={16} />} timeoutMs={null}
              onOpen={() => goTo("record")} onDismiss={() => setOneSided(null)}
              actions={<button type="button" className="btn compact" onClick={() => { setOneSided(null); goTo("record"); }}>Ready check</button>}>
              <span className="one-sided-text"><b>{oneSided.title}</b><span className="small">{oneSided.reason} {oneSided.fix}</span></span>
            </Notice>
          )}
          {/* The month's limit (a state): goes to Plan & usage; closed until it changes (warning, then reached). */}
          {status.budget !== "ok" && (status.budget === "reached" || status.budget === "warn") && (
            <StateBanner id="budget" state={status.budget} tone={status.budget === "reached" ? "danger" : "warn"} testId="budget-banner" icon={<AlertIcon size={16} />}
              onOpen={() => openAccount("plan")} openLabel={`${status.budget === "reached" ? BUDGET_REACHED : BUDGET_WARN} Open Plan & usage`}>
              <span>{status.budget === "reached" ? BUDGET_REACHED : BUDGET_WARN}</span>
            </StateBanner>
          )}
          {tab === "library" && (
            <LibraryView notify={notify} focus={focus} onFocusHandled={clearFocus} openRequest={openRequest}
              initialSelected={restoredRecording} onSelectedChange={rememberRecording} onImport={canRecord ? () => void pickImport() : undefined} onOpenClient={(id) => openProject(id)}
              smartFolder={libraryFolder} onSmartFolder={openFolder} smartView={libraryView} crumb={libraryCrumb} sharedFocus={sharedFocus} projects={projects}
              aside={<LibraryColumn places={places} folder={libraryFolder} view={libraryView} onOpen={openPlace} projects={projects} expanded={expanded}
                onToggle={toggleExpanded} onProject={(id) => openProject(id)} />} />
          )}
          {tab === "projects" && (
            <ProjectsView notify={notify} projects={projects} error={projectsError} onReload={loadProjects} onChanged={changeProjects}
              projectId={projectId} onOpenProject={openProject} onOpenRecording={openRecording} expanded={expanded} onToggle={toggleExpanded}
              requestFocus={requestFocus} onRequestFocusHandled={clearRequestFocus} aiNotesInPlan={status.plan?.aiNotes !== false} request={projectRequest}
              aside={<ProjectsColumn projects={projects} selectedId={projectId} expanded={expanded} onToggle={toggleExpanded} onOpen={openProject} onNew={newProject} />} />
          )}
          {tab === "record" && (
            <HomeView status={status} run={run} onImport={canRecord ? () => void pickImport() : undefined} needsYou={activityItems}
              onReview={() => openLibraryView("waiting")} onFailed={() => openLibraryView("failed")} onActivity={() => goTo("activity")}
              onOpenRecording={(id) => openRecording(id)} onLibrary={() => openFolder(null)} />
          )}
          {tab === "activity" && (
            <ActivityView status={status} notify={notify} onItems={setActivityItems} onOpenRecording={(id) => openRecording(id)}
              onReview={(id) => { openFolder(null); if (id) setFocus({ id, rename: false, seq: ++navigations.current }); }} />
          )}
          {tab === "shared" && (
            <SharedView tab={sharedTab} onTab={setSharedTab} notify={notify} projects={projects} onOpenShared={(id) => { openFolder("shared"); setSharedFocus(id); }}
              onOpenRecording={(id) => openRecording(id)} onOpenProject={(id) => openProject(id)} />
          )}
          {tab === "settings" && <SettingsView status={status} run={run} onUnpaired={setStatus} theme={theme} onTheme={chooseTheme} section={settingsSection} />}
        </main>
      </div>
      {palette && (
        <CommandPalette status={status} onClose={() => setPalette(false)} onGo={goTo}
          folders={shownFolders(views?.folders, tab === "library" ? libraryFolder : null)} onFolder={openFolder}
          run={(fn, ok, target) => void run(fn, ok, target)} pages={pages}
          extra={{ upload: canRecord ? () => void pickImport() : undefined, newProject, requestLink: () => setRequestSheet(true),
            invite: () => void api.openServerPage("app/workspace").catch((e) => notify(errorMessage(e), true)), account: openAccount, help: () => setHelp(true) }}
          onOpenRecording={(id, ms) => openRecording(id, ms)} />
      )}
      {dragging && !importPaths && (
        <div className="drop-hint" aria-hidden="true"><div className="drop-hint-card">Drop voice notes, audio or video files to import them</div></div>
      )}
      {importPaths && <ImportSheet paths={importPaths} notify={notify} onClose={closeImport} />}
      {help && <HelpDialog platform={status.platform} onClose={() => setHelp(false)} onShortcuts={() => openAccount("preferences")} />}
      {requestSheet && <RequestLinksSheet projects={projects ?? []} notify={notify} onClose={() => setRequestSheet(false)} />}
      <UpgradeSheet />
      {toastView}
    </div>
  );
}
