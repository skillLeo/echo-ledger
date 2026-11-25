import { lazy, Suspense, useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from "react";
import { listen } from "@tauri-apps/api/event";
import { announceReady, api, errorMessage, listenForFileDrops, listenTo, type AppStatus, type ClientFolder, type LibraryItem, type LibraryViews, type LockStatus, type SmartFolder } from "../../lib/ipc";
import { loadMainView, rememberMainView, restoreScroll, trackScroll, type MainTab, type MainView } from "../../lib/mainView";
import { activityReporter, LockScreen } from "./LockScreen";
import { ActivityView, useActivityItems } from "./ActivityCentre";
import { needsAction as needsActionItem, railBadge } from "../../lib/badge";
import { AUTO_RAIL_BELOW, COLUMN_CONTENT, loadCollapsed, loadSidebarWidth, NARROW_CONTENT, paletteKeys, saveCollapsed, saveSidebarWidth, SIDEBAR_RAIL, sidebarFolded } from "../../lib/shell";
import { Sidebar, SHARED_TABS, type SharedTab, type ShellPage } from "./Sidebar";
import { TopBar, workspacesOf } from "./TopBar";
import { HelpDialog } from "./HelpDialog";
import { LibraryColumn, ProjectsColumn } from "./SecondaryColumn";
import { HomeView } from "./HomeView";
import { SharedView } from "./SharedView";
import { RequestLinksSheet } from "./RequestLinks";
import { settingsAvailability, settingsPages, signOutBody } from "./SettingsView";
import { ConfirmHost } from "./ConfirmHost";
import { roleOf } from "./AccountSettings";
import { searchSettings } from "../../lib/settingsIndex";
import { OnboardingView } from "./OnboardingView";
import { OtherSideBanner, PermissionLossNotice } from "./Permissions";
import { AfterCallNotice } from "./AfterCallNotice";
import { ShareDefaultNotice } from "./ShareDefaultNotice";
import { AlertIcon } from "../../components/Icons";
import { applyTheme, getThemeChoice, saveThemeChoice, type ThemeChoice } from "../../lib/theme";
import { SignInView } from "./SignInView";
import { RestartToUpdate, UpdateBanner } from "./UpdateSettings";
import { MenuBar } from "../../v2/actions/useMenuBar";
import { listenToMenuPicks, menuPicksReady, setPickedHandler, useMenuPart } from "../../v2/actions/menuState";
import { runPicked } from "../../v2/actions/popup";
import { registerAction } from "../../v2/actions/registry";
import { ZOOM_DEFAULT, loadZoom, setZoom, useZoom, watchZoomWheel, zoomBy, zoomKey, zoomNow } from "../../lib/zoom";
import { ZoomBubble, ZoomChip, zoomStepper } from "../../v2/shell/Zoom";
import { useAvatar } from "./AccountFlows";
import { minutesLine } from "../../v2/settings/AccountCard";
import { SYNC_NOW_EVENT } from "../../lib/nativeFeel";
import { LibraryView } from "./LibraryView";
import type { PendingFocus } from "./LibraryQueue";
import { SettingsView } from "./SettingsView";
import { ProjectsView, useExpandedProjects } from "./ProjectsView";
import { folderLabel, LIBRARY_VIEWS, libraryPlaces, shownFolders, type LibraryPlace, type LibraryView as LibraryViewKey } from "../../lib/folders";
import { CommandPalette } from "./CommandPalette";
import { ImportSheet } from "./ImportSheet";
import { useWindowShown } from "../../lib/visibility";
import { fileDropClaim } from "../../lib/dropTarget";
import { isViewer, ROLE_LABEL, viewerSentence } from "../../lib/plan";
import { enterView, isConfirmation, MotionGroup, viewDirection } from "../../lib/motion";
import { SuccessCheck } from "../../components/SuccessCheck";
import type { Account, OneSided } from "../../lib/ipc";
import { call as accountCall, failureText, replyText } from "../../lib/account";
import { t, useLanguage, type Lang } from "../../i18n";
import { accountLanguage, useFollowLanguage } from "../../i18n/language";
import { isLocked, lowestPlan, planName, OPEN_SECTION_EVENT, forgetEntitlements, openRequest as openUpgradeRequest, openUpgradeSheet, plansAbove, refreshEntitlements, useEntitlements } from "../../lib/entitlements";
import { UpgradeSheet } from "./Upgrade";
import { isHoldMessage, ServerBanners } from "./ServerBanners";
import { ShareNotice } from "./PrivacySettings";
import { Notice, NoticeLook } from "../../components/Notice";
import { StateBanner } from "../../components/StateBanner";
import { BannerSlot, useBannerTurn } from "../../components/BannerSlot";
import { LearnMore } from "../../components/LearnMore";
import { ERROR_MS, MESSAGE_MS, NO_TARGET, noticeOf, type NoticePayload, type NoticeTarget, type Notify } from "../../lib/notice";
import { confirmAction, isConfirmOpen } from "../../lib/confirm";
import { useUiFlags } from "../../v2/flags";
import { shellChip } from "../../v2/shell/status";
import { useActivityBadge } from "../../v2/activity/useBadge";
import { libraryViewCounts, needsYouCount, prepare } from "../../v2/library/facets";
import { ALL_SCOPE, scopeKey, type LibraryScope } from "../../v2/library/scope";
import type { LibraryPlaceState } from "../../v2/library/LibraryV2";
import type { Place as NavPlace } from "../../v2/shell/NavRail";
import { noteRecentProject } from "../../v2/shell/recentProjects";
import { readKept, writeKept } from "../../v2/shell/kept";
import type { ActivityShow } from "../../v2/shell/PlacePanels";
import * as Icons from "../../v2/components/icons";
import { syncLibrary } from "../../v2/library/syncClock";
import { lastViewsAccount, rememberViewsAccount, settleViewsAccount, useViewsAccount, viewsAccount, viewsStore } from "../../v2/library/myViews";
import { sourceAppLabel } from "../../lib/format";
import type { HelpTab } from "../../v2/help/HelpV2";
import { helpV2Reachable } from "../../v2/help/route";
import { hasV2Page } from "../../v2/settings/pages/route";
import { entryOf, groupOf } from "../../v2/settings/groups";

// The redesigned screens (docs/API.md "Interface flags"): loaded only when the server turns their flag on.
const v2 = () => import("../../v2/app");
const ShellV2 = lazy(() => v2().then((m) => ({ default: m.Shell })));
const LibraryV2 = lazy(() => v2().then((m) => ({ default: m.LibraryV2 })));
const LibraryNavV2 = lazy(() => v2().then((m) => ({ default: m.LibraryNav })));
const LivePanelV2 = lazy(() => v2().then((m) => ({ default: m.LivePanel })));
const AskPanelV2 = lazy(() => v2().then((m) => ({ default: m.AskPanel })));
const ActivityPanelV2 = lazy(() => v2().then((m) => ({ default: m.ActivityPanel })));
const SettingsPanelV2 = lazy(() => v2().then((m) => ({ default: m.SettingsPanel })));
const LibraryFootV2 = lazy(() => v2().then((m) => ({ default: m.LibraryFoot })));
const ClassicSwitchV2 = lazy(() => v2().then((m) => ({ default: m.ClassicSwitch })));
const SettingsV2 = lazy(() => v2().then((m) => ({ default: m.SettingsV2 })));
const SetupHealthV2 = lazy(() => v2().then((m) => ({ default: m.SetupHealth })));
const HelpV2 = lazy(() => v2().then((m) => ({ default: m.HelpV2 })));
const SettingsPagesV2 = lazy(() => v2().then((m) => ({ default: m.SettingsPagesV2 })));
const HomeV2 = lazy(() => v2().then((m) => ({ default: m.HomeV2 })));
const LiveV2 = lazy(() => v2().then((m) => ({ default: m.LiveV2 })));
const AskV2 = lazy(() => v2().then((m) => ({ default: m.AskV2 })));
const ActivityV2 = lazy(() => v2().then((m) => ({ default: m.ActivityV2 })));
const SharedV2 = lazy(() => v2().then((m) => ({ default: m.SharedV2 })));
const RequestLinkSheetV2 = lazy(() => v2().then((m) => ({ default: m.RequestLinkSheet })));
const ImportSheetV2 = lazy(() => v2().then((m) => ({ default: m.ImportSheetV2 })));
const DropHintV2 = lazy(() => v2().then((m) => ({ default: m.DropHintV2 })));
const ProjectsListV2 = lazy(() => v2().then((m) => ({ default: m.ProjectsListV2 })));
const NameDialogV2 = lazy(() => v2().then((m) => ({ default: m.NameDialogV2 })));
const AppToastV2 = lazy(() => v2().then((m) => ({ default: m.AppToastV2 })));
const OnboardingV2 = lazy(() => v2().then((m) => ({ default: m.OnboardingV2 })));
const WorkspaceV2 = lazy(() => v2().then((m) => ({ default: m.WorkspaceV2 })));
const AfterCallNoticeV2 = lazy(() => v2().then((m) => ({ default: m.AfterCallNoticeV2 })));

type Tab = MainTab;
/** Whether the account had no project when last read (kept.ts), so the Projects panel needn't show while they load. */
const NO_PROJECTS_KEY = "echoledger-no-projects";
/** The pages in the sidebar's order (Home is "record", Account "settings"): a switch fades in from that side. */
const TABS: readonly Tab[] = ["record", "library", "projects", "activity", "shared", "settings"];
/** How long the first-run wizard waits for the interface flags before it shows classic's (no blank window). */
export const WIZARD_FLAGS_WAIT_MS = 1500;
/** Help's group in two levels (UX-11): its way back goes to that group's page. */
const HELP_GROUP = (() => {
  const group = groupOf("help")!;
  return { title: group.title, entry: entryOf(group) };
})();
/** The pages in words, for a Back link that returns to one ("Library"). */
const TAB_NAMES: Record<Tab, string> = { record: "Record page", library: "Library", projects: "Projects", activity: "Activity", shared: "Shared", settings: "Settings" };
/** How long after the library changed the sidebar's project counts are read again (changes come in bursts). */
const RELOAD_AFTER_CHANGE_MS = 800;

const BUDGET_REACHED = "Monthly transcription limit reached. New recordings are kept as audio only.";
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

/** The menu bar's commands the main window answers itself (FIX-05); the shell (NavShell) and the pages add theirs. */
const MENU_COMMANDS = [
  "app.accountPlan", "app.checkUpdates", "file.syncNow", "file.newProject", "view.themeLight", "view.themeDark", "view.themeSystem",
  "go.allRecordings", "go.needsYou", "go.unread", "go.audioOnly", "go.trash", "go.shared", "live.pauseRecording", "live.captions", "window.pill",
  "help.help", "help.shortcuts", "help.setupHealth",
] as const;

export default function MainApp() {
  const [status, setStatus] = useState<AppStatus | null>(null);
  const [tab, setTab] = useState<Tab>("library");
  // Interface flags: read at start, on each page change and when a recording ends, never while recording; the core's
  // first config answer (ui:flags) applies at once when the window read before it (v2/flags.ts).
  const [ui, setUi, flagsReadFor] = useUiFlags(!!status?.paired, tab, !!status?.recording);
  // UX-07 (v21_zoom): zoom like Chrome, in this window only. Off, the window is at 100% (main.tsx may have put a kept
  // zoom back before the flags were known). On: the kept zoom, the keys (⌘+ ⌘= ⌘− ⌘0, Ctrl elsewhere, the number pad's
  // too; the menu bar's own on a Mac), Ctrl + the wheel, and the menu bar's View items.
  const zoomOn = ui.flags.v21_zoom;
  const zoomLevel = useZoom();
  const flagsKnown = flagsReadFor !== null;
  useEffect(() => {
    if (!flagsKnown) return;
    if (!zoomOn) {
      if (zoomNow() !== ZOOM_DEFAULT) setZoom(ZOOM_DEFAULT, false);
      return;
    }
    setZoom(loadZoom(), false);
    const key = (e: KeyboardEvent) => {
      const dir = zoomKey(e, menuPlatform.current === "macos");
      if (dir === null || e.defaultPrevented) return;
      e.preventDefault();
      zoomBy(dir);
    };
    window.addEventListener("keydown", key);
    // Ctrl + the wheel off a Mac (a Mac's pinch is the system's own).
    const stopWheel = menuPlatform.current === "macos" ? () => undefined : watchZoomWheel();
    const offs = [registerAction("view.zoomIn", () => zoomBy(1)), registerAction("view.zoomOut", () => zoomBy(-1)), registerAction("view.actualSize", () => zoomBy(0))];
    return () => {
      window.removeEventListener("keydown", key);
      stopWheel();
      offs.forEach((off) => off());
    };
  }, [zoomOn, flagsKnown]);
  const threeLevels = useRef(false);
  threeLevels.current = ui.flags["ui_v2.library"];
  // The sidebar (v1.6, P4.1): 200–320 px wide or a 64 px icon rail, kept on this computer like the theme.
  const [chosenCollapsed, setCollapsed] = useState(loadCollapsed);
  const [sidebarWidth, setSidebarWidth] = useState(loadSidebarWidth);
  // The page's width (the window's less the sidebar's) decides whether the Library's columns fit (styles.css).
  const [windowWidth, setWindowWidth] = useState(() => window.innerWidth);
  useEffect(() => {
    const resized = () => setWindowWidth(window.innerWidth);
    window.addEventListener("resize", resized);
    return () => window.removeEventListener("resize", resized);
  }, []);
  // A window too narrow for the sidebar (200 % text in the smallest window) shows its rail; opening it there lasts
  // until the window is wide again and never changes what was chosen.
  const autoRail = windowWidth < AUTO_RAIL_BELOW;
  const [openedAnyway, setOpenedAnyway] = useState(false);
  useEffect(() => {
    if (!autoRail) setOpenedAnyway(false);
  }, [autoRail]);
  const collapsed = sidebarFolded(chosenCollapsed, windowWidth, openedAnyway);
  const autoRailRef = useRef(autoRail);
  autoRailRef.current = autoRail;
  const toggleSidebar = useCallback(() => {
    if (autoRailRef.current) {
      setOpenedAnyway((o) => !o);
      return;
    }
    setCollapsed((c) => {
      saveCollapsed(!c);
      return !c;
    });
  }, []);
  const resizeSidebar = useCallback((width: number, done: boolean) => {
    setSidebarWidth(width);
    if (done) saveSidebarWidth(width);
  }, []);
  const [help, setHelp] = useState(false);
  // Help & shortcuts in the new design: Settings › Help on its Shortcuts tab ("?", the sidebar, the palette).
  // `from`: the page "?" was pressed on; Help's Back goes there (Settings when it was opened from Settings).
  const [helpStart, setHelpStart] = useState<{ tab: HelpTab; seq: number; from: Tab | null } | null>(null);
  const openHelpRef = useRef<() => void>(() => setHelp(true));
  // Request files: the sheet, and the project new links send into (null: the Inbox).
  const [requestSheet, setRequestSheet] = useState<false | { projectId: string | null }>(false);
  // "+ New project" from the top bar or the Projects column: the Projects page opens its dialog.
  const [projectRequest, setProjectRequest] = useState<{ kind: "new"; seq: number } | null>(null);
  const [account, setAccount] = useState<Account | null | undefined>(undefined);
  // Desktop Urdu (v21_urdu), in the new design only: the window speaks the account's language (the last one known on
  // this computer while it loads or can't be read; the system's only when signed out); Settings › Appearance › Language
  // changes it on the server, for the web and the phone too.
  const urdu = ui.flags.v21_urdu && !ui.classic && ui.flags["ui_v2.shell"];
  useFollowLanguage(urdu, flagsKnown, status ? status.paired : null, account);
  const lang = useLanguage();
  const [languageError, setLanguageError] = useState<string | null>(null);
  // One change at a time (PATCH /api/account allows 10 an hour, with name changes): a click while one is on its way,
  // or on the language the account has already, sends nothing.
  const languagePending = useRef(false);
  const changeLanguage = async (next: Lang) => {
    if (languagePending.current || next === (accountLanguage(account) ?? lang)) return;
    languagePending.current = true;
    setLanguageError(null);
    try {
      const reply = await accountCall<Account>("language", { language: next });
      if (!reply.ok) return setLanguageError(replyText(reply));
      // The server's account as it answers (the same as GET), so the window says what the server has.
      const data = reply.data && typeof reply.data === "object" ? reply.data : null;
      setAccount((now) => (now ? { ...now, ...data, language: accountLanguage(data) ?? next } : now));
    } catch (e) {
      setLanguageError(failureText(e));
    } finally {
      languagePending.current = false;
    }
  };
  // The person's photo for the rail's avatar (DES-08), kept on this computer by its version; the initials without one.
  const avatarPhoto = useAvatar(account);
  // The Activity badge (v1.6, P6): only what needs the person (lib/badge.ts), never every unread notification.
  const [activityItems, setActivityItems] = useActivityItems();
  // UX-08: the server's one number (GET /api/badge), the same on the phone and the web console; counted here from the list
  // while it can't be read (offline, an older server).
  const serverBadge = useActivityBadge(!!status?.paired, status?.signIn ?? null);
  // FIX-11: the Dock icon's number is the rail's; the core adds the calls waiting that only this computer knows, so the
  // rest is sent (the server's number, or, counted here, the list and the calls the server has sessions for).
  const waitingAll = status?.pendingDecisions ?? 0;
  const waitingHere = status?.pendingDecisionsHere ?? waitingAll;
  const activityPart = Math.max(0, railBadge(serverBadge, activityItems, waitingAll, waitingHere).count - waitingHere);
  useEffect(() => {
    void api.dockActivitySet(activityPart).catch(() => undefined);
  }, [activityPart]);
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
  // The new design's Library (PLAN_LIBRARY_FILTERS): what the sidebar chose (a library view, one of My views, a label,
  // Trash), the request that tells the Library, and the recordings the sidebar counts from (read here, and fresher from
  // the Library while it is open, with what was opened there).
  const [libraryScope, setLibraryScope] = useState<LibraryScope>(ALL_SCOPE);
  const [scopeRequest, setScopeRequest] = useState<{ scope: LibraryScope; seq: number; restore?: LibraryPlaceState } | null>(null);
  // The three levels' memory (PLAN_NAV_3_LEVELS §6): each place as it was left, to come back to from the rail.
  const libraryPlace = useRef<LibraryPlaceState | null>(null);
  const lastProject = useRef<string | null>(null);
  const lastSettings = useRef<string | null>(null);
  const [activityShow, setActivityShow] = useState<ActivityShow>("all");
  const [askProject, setAskProject] = useState<{ id: string | null; seq: number; question?: string }>({ id: null, seq: 0 });
  // The title row's search asks for a place (Ask about "…", UX-20).
  const [placeRequest, setPlaceRequest] = useState<{ place: NavPlace; seq: number } | null>(null);
  const [filtersRequest, setFiltersRequest] = useState<{ seq: number } | null>(null);
  const [libraryItems, setLibraryItems] = useState<{ items: readonly LibraryItem[]; read: ReadonlySet<string> } | null>(null);
  const libraryFacts = useMemo(() => (libraryItems ? prepare(libraryItems.items, { read: libraryItems.read }) : null), [libraryItems]);
  // My views are each account's own in each workspace (the server, the email, the workspace), synced through
  // /api/views. Whose they were last is kept on this computer for this sign-in: an offline start shows (and saves) that
  // account's views until the account can be read; signing out forgets it, and another account's sign-in replaces it.
  // What is kept for this is tied to the core's id for this sign-in: another sign-in, or none, finds nothing of it (and
  // the copies of a sign-in that is over go, but for what still waits to be sent). The remembered account's views show
  // and change here only; they are sent once account_get confirms the same server, email and workspace.
  const liveViews = viewsAccount(status?.serverUrl, account?.email, account?.workspace?.id);
  const signIn = status ? (status.paired ? status.signIn ?? null : null) : undefined;
  // The places' memory is one sign-in's: another sign-in (or none) starts every place afresh.
  useEffect(() => {
    libraryPlace.current = null;
    lastProject.current = null;
    lastSettings.current = null;
    setAskProject((a) => ({ id: null, seq: a.seq + 1 }));
    setActivityShow("all");
    setLibraryScope(ALL_SCOPE);
    // And what was asked of the Library for the last sign-in: a Library opened later (a notice, a shared item) never
    // applies it.
    setScopeRequest(null);
    setLibraryViewV2(null);
    setOpenRequest(null);
    setReviewV2(null);
    setFiltersRequest(null);
  }, [signIn]);
  useEffect(() => {
    if (signIn !== undefined) settleViewsAccount(signIn);
  }, [signIn]);
  useEffect(() => {
    if (liveViews && signIn) rememberViewsAccount(status?.serverUrl, liveViews, signIn);
  }, [liveViews, signIn, status?.serverUrl]);
  const lastViews = useMemo(() => (signIn ? lastViewsAccount(status?.serverUrl, signIn) : null), [signIn, status?.serverUrl, liveViews]);
  useViewsAccount(liveViews ?? lastViews, liveViews !== null);
  // UX-09: whether this account had no project when last read, so the Projects panel doesn't show and then go away
  // (260 px) on a cold start, or stay empty while offline.
  const projectsAccount = liveViews ?? lastViews;
  const hadNoProjects = readKept(NO_PROJECTS_KEY, projectsAccount) === "1";
  useEffect(() => {
    if (projects) writeKept(NO_PROJECTS_KEY, projectsAccount, projects.length === 0 ? "1" : "0");
  }, [projects, projectsAccount]);
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
  // FIX-05: the window's one listener for every pick from a menu the system drew (menuState.ts), in the classic shell
  // too; the platform is read at each pick. The Mac menu bar itself is the <MenuBar /> leaf (with the new design's
  // shell), and the window's own commands (MENU_COMMANDS) are registered below while the app is in use.
  const menuPlatform = useRef<"macos" | "windows">("windows");
  menuPlatform.current = status?.platform === "macos" ? "macos" : "windows";
  useEffect(() => listenToMenuPicks(() => menuPlatform.current), []);
  // A right-click menu's (numbered) picks are its own (actions/popup.ts): run by the item they came from.
  useEffect(() => setPickedHandler(runPicked), []);
  const menuCommands = useRef<Partial<Record<(typeof MENU_COMMANDS)[number], () => void>>>({});
  // The panel tree's project menu (3b): what the window can do for a project from there; the rest are the Projects page's.
  const projectCommands = useRef<{ open: (id: string) => void; requestFiles: (id: string) => void; tab: (id: string, tab: "memory" | "members") => void }>({
    open: () => undefined, requestFiles: () => undefined, tab: () => undefined,
  });
  /** A project opened on one of its tabs (the project menu's Instructions & memory… and Members…, UX-09). */
  const [projectTab, setProjectTab] = useState<{ id: string; tab: "memory" | "members"; seq: number } | null>(null);
  // What Live's hiding items follow, as the core works it out (privacy_status): the main window hidden from screen
  // shares now (an expired 15 minutes is not), the person's Hide EchoLedger windows, and its shortcut.
  const [privacyNow, setPrivacyNow] = useState({ hiding: false, windowsHidden: false, hideAllShortcut: "" });
  useMenuPart({ state: {
    themeLight: theme === "light", themeDark: theme === "dark", themeSystem: theme === "system",
    paused: !!status?.paused, captionsShown: !!status?.captionsLive, pillShown: !!status?.recording && !status.pillHidden,
    hiding: privacyNow.hiding, windowsHidden: privacyNow.windowsHidden, hideAllShortcut: privacyNow.hideAllShortcut,
  } });
  useEffect(() => {
    let alive = true;
    let expiry = 0;
    const read = () => {
      void Promise.all([api.privacyStatus(), api.settings()]).then(([p, s]) => {
        if (!alive) return;
        setPrivacyNow({ hiding: !!p.mainHide, windowsHidden: !!p.hiddenByShortcut, hideAllShortcut: s.shortcuts?.hide_all ?? "" });
        // A hide for a time is read again when its time comes.
        window.clearTimeout(expiry);
        const until = p.mainHide?.until;
        if (until?.kind === "at") expiry = window.setTimeout(read, Math.max(0, until.at_ms - Date.now()) + 1000);
      }).catch(() => undefined);
    };
    read();
    const offs = [listenTo("privacy:changed", read), listenTo("settings:changed", read)];
    return () => {
      alive = false;
      window.clearTimeout(expiry);
      offs.forEach((off) => void off.then((f) => f()).catch(() => undefined));
    };
  }, []);
  const [focus, setFocus] = useState<PendingFocus | null>(null);
  const navigations = useRef(0);
  const [palette, setPalette] = useState(false);
  // What was typed in the title row before searching everything (UX-20: the scope chip's ✕).
  const [paletteFrom, setPaletteFrom] = useState("");
  const [openRequest, setOpenRequest] = useState<{ id: string; ms: number; seq: number } | null>(null);
  const [lock, setLock] = useState<LockStatus | null>(null);
  const [setupDone, setSetupDone] = useState<boolean | null>(null);
  // The first-run wizard's design is chosen once, when it first shows (signed in: once the flags are read), so a
  // late answer never swaps it under the person halfway through.
  const [wizardDesign, setWizardDesign] = useState<"v2" | "classic" | null>(null);
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

  useEffect(() => {
    if (setupDone !== false || wizardDesign !== null || !status) return;
    // Signed out, the flags can't be read: classic's wizard (it signs in on its own step).
    if (status.paired && flagsReadFor === null) return;
    setWizardDesign(status.paired && ui.flags["ui_v2.shell"] ? "v2" : "classic");
  }, [setupDone, wizardDesign, status, flagsReadFor, ui.flags]);
  // Flags that never come (a core without the command hanging, a slow server) never leave the window blank: after a
  // moment the wizard is classic's.
  const waitingForWizard = setupDone === false && wizardDesign === null;
  useEffect(() => {
    if (!waitingForWizard) return;
    const timer = window.setTimeout(() => setWizardDesign((d) => d ?? "classic"), WIZARD_FLAGS_WAIT_MS);
    return () => window.clearTimeout(timer);
  }, [waitingForWizard]);
  // A new start of the wizard (Settings can show it again) chooses again.
  useEffect(() => {
    if (setupDone === true) setWizardDesign(null);
  }, [setupDone]);

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
        // A question on screen is answered first: search never opens (or closes) behind it, whether it was asked through
        // confirmAction or is a page's own confirm dialog (Sign out, Cancel this request).
        if (isConfirmOpen() || document.querySelector('[role="alertdialog"]')) return;
        setPalette((open) => !open);
        return;
      }
      if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey || e.repeat || typingIn(e.target)) return;
      const open = !!document.querySelector(".palette-backdrop, .sheet-backdrop");
      // "[" folds the sidebar to its rail and back; "?" lists every key (v1.6, P4.1).
      // Not with the three levels: the panel's ⊟ and ⌘\ hide it there (a single key never folds it).
      if (e.key === "[" && statusRef.current?.paired && !open && !threeLevels.current) {
        e.preventDefault();
        toggleSidebar();
        return;
      }
      if (e.key === "?" && statusRef.current?.paired && !open) {
        e.preventDefault();
        openHelpRef.current();
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
        if (nav.view === "settings" && nav.section) setSettingsSection({ id: nav.section, seq: ++navigations.current });
      }),
    ];
    // After the kept view is applied, so a navigation sent while the window was built wins over it.
    // And once the menus' listener hears picks: a pick the core kept while the window was built reaches it.
    announceReady([restore, ...unlisten, menuPicksReady()], () => alive);
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
    api.library().then((items) => {
      const list = Array.isArray(items) ? items : [];
      setLibraryTotal(list.filter((i) => !i.deleted).length);
      setLibraryItems((now) => ({ items: list, read: now?.read ?? new Set() }));
    }).catch(() => undefined);
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
    let read = false;
    const ask = () => api.account().then((a) => {
      if (!alive) return;
      read = true;
      setAccount(a ?? null);
    }, () => alive && setAccount((now) => now ?? null));
    void ask();
    // Not read (offline at the start): asked again after the next library sync that worked.
    const un = listen("library:updated", () => {
      if (!read) void ask();
    });
    return () => {
      alive = false;
      void un.then((f) => f()).catch(() => undefined);
    };
  }, [paired, ready]);
  const entitlements = useEntitlements();
  // "Add my Groq key" on a recording (and other places): Settings → Account → that section.
  const [settingsSection, setSettingsSection] = useState<{ id: string; seq: number } | null>(null);
  /** Counts the times Settings was pressed again while open: the v2 Settings starts again from its list. */
  const [settingsVisit, setSettingsVisit] = useState(0);
  const [libraryViewV2, setLibraryViewV2] = useState<{ view: "untranscribed" | LibraryViewKey | "all"; seq: number } | null>(null);
  /** Activity's Review: the new Library with its waiting recordings open. */
  const [reviewV2, setReviewV2] = useState<{ seq: number } | null>(null);
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
          } else if (place.in === "shared" && ui.flags["ui_v2.shell"]) {
            openShared("with-me");
            setSharedFocus(place.itemId);
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
          if (ui.flags["ui_v2.shell"]) {
            openShared("with-me");
            setSharedFocus(target.shared_id);
            break;
          }
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
  // A view saved offline that the server then refused (30 already): said once, where the views are.
  useEffect(() => viewsStore.onProblem((text) => showToast(text, true, { kind: "library", view: null })), [showToast]);
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

  // F5 and ⌘R (Ctrl+R) in a release build (FIX-10, lib/nativeFeel.ts): Sync now, as the search's "Sync the library".
  // Only in the app itself (unlocked, set up), never behind a dialog; syncLibrary keeps the sidebar's "Synced … ago".
  const syncNow = useRef(() => undefined as unknown);
  syncNow.current = () => {
    if (!status?.paired || !restored || lock?.locked || setupDone === false || document.querySelector('[aria-modal="true"]')) return;
    return syncLibrary().catch((e) => notify(errorMessage(e), true));
  };
  useEffect(() => {
    const on = () => void syncNow.current();
    window.addEventListener(SYNC_NOW_EVENT, on);
    return () => window.removeEventListener(SYNC_NOW_EVENT, on);
  }, []);

  // Rendered in every branch: a pairing error, or "signed out on the server" arriving with the status that sends the
  // window back to the sign-in form, must stay visible (A6.DU1).
  // A message that confirms something carries the check that pops in (as on the phone).
  // Its target opens only in the main window itself (never on the lock screen, the sign-in or the first-run wizard).
  const goRef = useRef<((target: NoticeTarget) => void) | null>(null);
  const inApp = !!status?.paired && restored && !lock?.locked && setupDone !== false;
  // The window's own menu commands, only while the app is in use (signed in, unlocked, set up): on the lock screen, at
  // sign-in and in setup nothing here can run them, so the menu bar greys them. Their functions are the latest render's.
  useEffect(() => {
    if (!inApp) return;
    const target = (ctx: { targets?: readonly string[] }) => ctx.targets?.[0];
    const offs = [
      ...MENU_COMMANDS.map((id) => registerAction(id, () => menuCommands.current[id]?.())),
      registerAction("project.open", (ctx) => { const id = target(ctx); if (id) projectCommands.current.open(id); }),
      registerAction("project.requestFiles", (ctx) => { const id = target(ctx); if (id) projectCommands.current.requestFiles(id); }),
      // UX-09: the project's page has a tab for each (Memory & instructions, Members).
      registerAction("project.instructions", (ctx) => { const id = target(ctx); if (id) projectCommands.current.tab(id, "memory"); }),
      registerAction("project.members", (ctx) => { const id = target(ctx); if (id) projectCommands.current.tab(id, "members"); }),
    ];
    return () => offs.forEach((off) => off());
  }, [inApp]);
  // The Mac menu bar's leaf (FIX-05), in every screen of the new design's window; classic keeps the core's own bar.
  const menuBarView = status?.platform === "macos" && ui.flags["ui_v2.shell"] ? <MenuBar platform="macos" /> : null;
  // In the new design's window the message is the new design's toast: Open goes where it is about, Esc or × closes it.
  // The new design's window keeps its message regions mounted; only the message in them changes.
  const toastV2 = inApp && ui.flags["ui_v2.shell"];
  const toastView = toastV2 ? (
    <Suspense fallback={null}>
      <div className="el-root el-embedded">
        <AppToastV2 duration={(m) => (m.error ? ERROR_MS : MESSAGE_MS)}
          message={toast ? { id: toast.id, text: toast.text, error: toast.error, onOpen: toast.target ? () => goRef.current?.(toast.target!) : undefined } : null}
          onClose={() => setToast((t) => (t?.id === toast?.id ? null : t))} />
        {zoomOn && <ZoomBubble />}
      </div>
    </Suspense>
  ) : toast && (
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
        {menuBarView}
        {toastView}
      </>
    );
  }

  if (status && setupDone === false) {
    return (
      <>
        {/* The new design's wizard when its flag is on (known once signed in); classic's otherwise. */}
        {wizardDesign === null ? null : wizardDesign === "v2" ? (
          <Suspense fallback={null}>
            <div className="el-root el-embedded"><OnboardingV2 status={status} onStatus={setStatus} onFinished={() => setSetupDone(true)} notify={notify} /></div>
          </Suspense>
        ) : <OnboardingView status={status} onStatus={setStatus} onFinished={() => setSetupDone(true)} notify={notify} />}
        {menuBarView}
        {toastView}
      </>
    );
  }

  if (!status || !status.paired || !restored) {
    return (
      <main className="gate">
        {status && !status.paired ? <SignInView onSignedIn={signedIn} notify={notify} serverUrl={status.serverUrl} /> : <div className="empty">Starting EchoLedger…</div>}
        {menuBarView}
        {toastView}
      </main>
    );
  }

  // The rail's number: the server's, plus the calls waiting on this computer (only it holds them); red when something failed.
  const activityBadge = railBadge(serverBadge, activityItems, waitingAll, waitingHere);
  const needsYou = activityBadge.count;
  const canRecord = !isViewer(status);
  /** Auto-record calls on or off: Record's menu and the Live page's switch (UX-15). */
  const toggleAutoRecord = () => void run(() => api.updateSettings({ autoMode: !status.autoMode }), status.autoMode ? "Call recording is off" : "Call recording is on");
  /** The Library on a smart folder (null: every recording). */
  const openFolder = (folder: SmartFolder | null) => {
    setTab("library");
    setLibraryFolder(folder);
    setLibraryView(null);
    setSharedFocus(null);
    // The new design's sidebar: every recording is All (a folder is a Source or Project filter there now).
    if (folder === null && ui.flags["ui_v2.library"]) openScope(ALL_SCOPE);
  };
  /** The new design's Library on what the sidebar chose: a library view, one of My views, a label or Trash. */
  const openScope = (scope: LibraryScope) => {
    setTab("library");
    setLibraryFolder(null);
    setLibraryView(null);
    setSharedFocus(null);
    setLibraryScope(scope);
    setScopeRequest({ scope, seq: ++navigations.current });
  };
  /** The Library on Waiting, Pinned or Failed uploads (v1.6, P4.2). */
  const openLibraryView = (view: LibraryViewKey | null) => {
    setTab("library");
    setLibraryFolder(null);
    setLibraryView(view);
    setSharedFocus(null);
    // The new design's Library has these views itself (Views ▾): the sidebar's place opens it there.
    setLibraryViewV2({ view: view ?? "all", seq: ++navigations.current });
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
    lastProject.current = id;
    if (id) noteRecentProject(id);
  };
  const openShared = (next: SharedTab) => {
    setTab("shared");
    setSharedTab(next);
  };
  /** A page from the sidebar or the command palette: the whole Library, the list of projects, or another page. */
  const goTo = (id: Tab) => {
    // Settings again from inside one of its pages (the sidebar's Settings or the profile button): its list, not nothing.
    if (id === "settings" && tab === "settings") {
      setSettingsSection(null);
      setSettingsVisit((n) => n + 1);
      return;
    }
    if (id === "projects") openProject(null);
    else if (id === "library") openFolder(null);
    else setTab(id);
  };
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
    lastSettings.current = section;
  };
  /**
   * A place chosen in the rail: back as it was left (the Library's view, filters, search and tab; the project; the
   * Settings page), or, pressed again while shown, started over (All recordings, All projects, Settings' list).
   */
  /** Live's panel: the part of the Live page to show. */
  const showLivePart = (part: "assist" | "translate" | "dictation" | "captions") => {
    const words = { assist: /live assist/i, translate: /translate/i, dictation: /dictation/i, captions: /caption/i }[part];
    window.requestAnimationFrame(() => {
      const target = [...document.querySelectorAll<HTMLElement>(".page-live h2, .page-live h3, .page-live [aria-label]")].find((el) => words.test(el.getAttribute("aria-label") ?? el.textContent ?? ""));
      target?.scrollIntoView({ block: "start" });
    });
  };
  const openNavPlace = (place: NavPlace, again: boolean) => {
    switch (place) {
      case "library": {
        const kept = !again ? libraryPlace.current : null;
        setTab("library");
        setLibraryFolder(null);
        setLibraryView(null);
        setSharedFocus(null);
        const scope = kept?.scope ?? ALL_SCOPE;
        setLibraryScope(scope);
        setScopeRequest({ scope, seq: ++navigations.current, restore: kept ?? undefined });
        return;
      }
      case "projects": return openProject(again ? null : lastProject.current);
      case "settings":
        if (again || !lastSettings.current) {
          lastSettings.current = null;
          return goTo("settings");
        }
        return openAccount(lastSettings.current);
      case "activity":
        if (again) setActivityShow("all");
        return setTab("activity");
      case "ask":
        if (again) setAskProject((a) => ({ id: null, seq: a.seq + 1 }));
        return;
      case "live": return;
    }
  };
  const helpInV2 = ui.flags["ui_v2.shell"] && ui.flags["ui_v2.settings"] && helpV2Reachable(ui.flags);
  const openHelp = () => {
    if (!helpInV2) return setHelp(true);
    const seq = ++navigations.current;
    setHelpStart({ tab: "shortcuts", seq, from: tab === "settings" ? null : tab });
    setTab("settings");
    setSettingsSection({ id: "help", seq });
  };
  openHelpRef.current = openHelp;
  const newProject = () => {
    setTab("projects");
    setProjectId(null);
    setProjectRequest({ kind: "new", seq: ++navigations.current });
  };
  const setupPage = ui.flags["ui_v2.settings"] && ui.flags["ui_v2.setup_health"] ? "setup_health" : "mic";
  projectCommands.current = {
    open: (id) => openProject(id),
    requestFiles: (id) => setRequestSheet({ projectId: id }),
    tab: (id, which) => {
      openProject(id);
      setProjectTab({ id, tab: which, seq: ++navigations.current });
    },
  };
  menuCommands.current = {
    "app.accountPlan": () => openAccount("plan"),
    "app.checkUpdates": () => {
      openAccount("about");
      void api.checkForUpdates().catch(() => undefined);
    },
    // The same as ⌘R (FIX-10): only in the app itself and never behind a dialog; it keeps the sidebar's "Synced … ago".
    "file.syncNow": () => void syncNow.current(),
    "file.newProject": newProject,
    "view.themeLight": () => chooseTheme("light"),
    "view.themeDark": () => chooseTheme("dark"),
    "view.themeSystem": () => chooseTheme("system"),
    "go.allRecordings": () => openScope(ALL_SCOPE),
    "go.needsYou": () => openScope({ kind: "view", view: "needs" }),
    "go.unread": () => openScope({ kind: "view", view: "unread" }),
    "go.audioOnly": () => openScope({ kind: "view", view: "audio_only" }),
    "go.trash": () => openScope({ kind: "trash" }),
    "go.shared": () => openShared(SHARED_TABS[0][0] as SharedTab),
    "live.pauseRecording": () => void run(status.paused ? api.resumeRecording : api.pauseRecording),
    "live.captions": () => void run(api.toggleCaptions),
    "window.pill": () => void run(() => api.setPillHidden(!status.pillHidden)),
    "help.help": openHelp,
    "help.shortcuts": openHelp,
    "help.setupHealth": () => openAccount(setupPage),
  };
  const signOut = async () => {
    if (!(await confirmAction({ title: "Sign out of this computer?", body: signOutBody(status.pendingUploads) ?? undefined, confirm: "Sign out", tone: "danger" }))) return;
    const next = await run(api.unpair);
    if (next) setStatus(next);
  };
  const record = () => void run(status.recording ? api.stopRecording : api.recordNow);
  const voiceNote = () => void run(api.toggleMicNote);

  // The Library's places with their counts: the server's, and what only this computer knows (v1.6, P4.2).
  // The new sidebar's counts (PLAN_LIBRARY_FILTERS): each library view's, from the synced list; Needs you with the calls
  // waiting and the uploads that failed on this computer; each label's.
  const libraryCounts = libraryFacts ? {
    ...libraryViewCounts(libraryFacts.facts),
    // From every item: the server's waiting recordings (session `recording`) are in no other view, so not in the facts.
    needs: needsYouCount(libraryItems?.items ?? [], { waiting: status.pendingDecisions, failedUploads: status.failedUploads }, Date.now()),
  } : null;
  const labelCounts: Record<string, number> = {};
  for (const f of libraryFacts?.facts ?? []) for (const key of f.values.labels) labelCounts[key] = (labelCounts[key] ?? 0) + 1;
  /** A place's words in the new design: the Inbox is "No project" (no "Inbox" anywhere). */
  const placeWords = (p: { key: string; label: string }) => (p.key === "inbox" ? "No project" : p.label);
  const places = libraryPlaces(views, {
    total: libraryTotal,
    waiting: status.pendingDecisions,
    failed: status.failedUploads,
    pinned: 0,
  }, tab === "library" ? libraryFolder : null);
  // What Settings offers here (v1.7): the same availability for the window's search (the palette) and Settings' own.
  const settingsAvailable = settingsAvailability({ platform: status.platform, account, entitlements, role: roleOf(account, status), rail: ui.flags["ui_v2.library"], zoom: ui.flags.v21_zoom, urdu });
  const findSettings = (query: string) => searchSettings(query, settingsAvailable).settings.slice(0, 6)
    .map((r) => ({ id: r.entry.id, title: r.entry.title, breadcrumb: r.breadcrumb, open: () => openAccount(r.entry.kind === "page" ? r.entry.id : `${r.entry.page ?? r.entry.id}#${r.entry.id}`) }));
  const upgradeOpen = !!openUpgradeRequest(entitlements);
  // Every page, place, project and Settings page by name, for the window's search (the palette).
  const pages: ShellPage[] = [
    { id: "home", label: "Home", keywords: "record start ready check calls voice note captions", open: () => goTo("record") },
    { id: "library", label: "Library", keywords: "recordings transcripts all", open: () => openFolder(null) },
    // The new design says no "Inbox": recordings in no project are Project › No project.
    ...places.filter((p) => p.key !== "all").map((p) => ({ id: `place-${p.key}`, label: placeWords(p), group: "Library", keywords: `smart folder recordings${p.key === "inbox" ? " inbox" : ""}`, open: () => openPlace(p) })),
    { id: "projects", label: "Projects", keywords: "clients folders", open: () => openProject(null) },
    ...(projects ?? []).map((p) => ({ id: `project-${p.id}`, label: p.name, group: "Projects", keywords: "project client", open: () => openProject(p.id) })),
    { id: "activity", label: "Activity", keywords: "notifications bell", open: () => goTo("activity") },
    ...SHARED_TABS.map(([id, label]) => ({ id: `shared-${id}`, label, group: "Shared", keywords: "share links shared", open: () => openShared(id) })),
    ...(canRecord ? [{ id: "upload", label: "Upload", keywords: "import files audio video", open: () => void pickImport() }] : []),
    ...settingsPages(settingsAvailable).map((p) => ({ id: p.id, label: p.label, group: p.group, keywords: p.keywords, open: () => openAccount(p.page) })),
    { id: "help", label: "Help & shortcuts", keywords: "keys keyboard", open: () => openHelp() },
  ];
  const pageWidth = windowWidth - (collapsed ? SIDEBAR_RAIL : sidebarWidth);
  // While the server holds the queue (v1.6, P2.7) the recordings wait; they are not on their way.
  const waitingFor = status.server?.waiting === "maintenance" ? "the server is in maintenance" : status.server?.waiting === "update_required" ? "update EchoLedger" : null;
  const uploading = status.pendingUploads === 0 ? null
    : waitingFor ? `${status.pendingUploads === 1 ? "1 recording waits" : `${status.pendingUploads} recordings wait`}: ${waitingFor}`
      : status.pendingUploads === 1 ? "Uploading 1 recording…" : `Uploading ${status.pendingUploads} recordings…`;
  // The new sidebar's workspace menu (classic's top bar switcher): Invite people for an owner or admin, or with the
  // plan's name when the plan leaves teams out; the rest is in the browser.
  const workspaceList = workspacesOf(account, status);
  const workspaceHere = workspaceList.find((w) => w.current) ?? workspaceList[0];
  const teamLocked = isLocked(entitlements, "team");
  const openWorkspaces = () => void api.openServerPage("app/workspace").catch((e) => notify(errorMessage(e), true));
  const roleWord = (role: string | null) => (role && role in ROLE_LABEL ? ROLE_LABEL[role as keyof typeof ROLE_LABEL] : null);
  const shellWorkspace = account === undefined ? null : {
    name: workspaceHere.name,
    role: roleWord(workspaceHere.role),
    others: workspaceList.filter((w) => w !== workspaceHere).map((w) => ({ key: w.key, name: w.name, role: roleWord(w.role) })),
    invite: teamLocked ? { locked: `in ${planName(lowestPlan(entitlements, "team")) || "a paid plan"}`, onPick: () => openUpgradeSheet("team") }
      : workspaceHere.role === "owner" || workspaceHere.role === "admin" ? { locked: null, onPick: openWorkspaces } : null,
    onManage: openWorkspaces,
  };
  const libraryCrumb = libraryView ? LIBRARY_VIEWS.find(([v]) => v === libraryView)?.[1] ?? null : libraryFolder ? folderLabel(libraryFolder) : null;

  const banners = (
    <>
      {/* One banner at a time, the most important first (BannerSlot); the next one shows once it goes. */}
      <BannerSlot>
      {status.platform === "macos" && <OtherSideBanner />}
      <UpdateBanner appStatus={status} notify={notify} onOpen={() => openAccount("about")} />
      <ServerBanners server={status.server} notify={notify} />
      {/* The new shell shows its own (ui_v2.shell). */}
      {!ui.flags["ui_v2.shell"] && <AfterCallNotice text={status.afterCallNotice} notify={notify} />}
      {!ui.flags["ui_v2.shell"] && <ShareDefaultNotice text={status.shareNotice} />}
      <ShareNotice />
      {status.platform === "macos" && <PermissionLossNotice />}
      {/* The last call's side that was never heard (Phase 1C): a click opens the Ready check with its fix. */}
      <OneSidedBanner said={oneSided} onDismiss={() => setOneSided(null)} onReady={() => { setOneSided(null); goTo("record"); }} onOpen={() => goTo("record")} />
      {/* The month's limit (a state): goes to Plan & usage; closed until it changes (warning, then reached). */}
      {status.budget !== "ok" && (status.budget === "reached" || status.budget === "warn") && (
        <StateBanner id="budget" slot="budget" state={budgetState(status.budget, entitlements?.limits.monthly_minutes?.resets_at)} remember={!!entitlements?.limits.monthly_minutes?.resets_at} tone={status.budget === "reached" ? "danger" : "warn"} testId="budget-banner" icon={<AlertIcon size={16} />}
          onOpen={() => openAccount("plan")} openLabel={`${status.budget === "reached" ? BUDGET_REACHED : BUDGET_WARN} Open Plan & usage`}>
          <span>{status.budget === "reached" ? BUDGET_REACHED : BUDGET_WARN}</span>
        </StateBanner>
      )}
      </BannerSlot>
    </>
  );
  const page = (
    <>
      {tab === "library" && ui.flags["ui_v2.library"] && (
        <Suspense fallback={null}>
          <LibraryV2 onImport={canRecord ? () => void pickImport() : undefined} onRecord={canRecord ? record : undefined} openRequest={openRequest} viewRequest={libraryViewV2} reviewRequest={reviewV2}
            folder={libraryFolder === "shared" ? null : libraryFolder} onFolder={() => setLibraryFolder(null)}
            scopeRequest={scopeRequest} onScope={setLibraryScope} onPlace={(p) => { libraryPlace.current = p; }} filtersRequest={filtersRequest}
            onItems={(items, read) => setLibraryItems({ items, read })}
            onOpenProject={(id) => openProject(id)} initialOpen={restoredRecording} onOpenChange={rememberRecording}
            top={ui.flags["ui_v2.shell"] ? {
              status, onRecord: canRecord && !status.recording ? record : undefined, onActivity: () => goTo("activity"),
              onSetup: () => openAccount(ui.flags["ui_v2.settings"] && ui.flags["ui_v2.setup_health"] ? "setup_health" : "mic"),
              onReadyCheck: () => openAccount(ui.flags["ui_v2.settings"] && ui.flags["ui_v2.setup_health"] ? "setup_health" : "mic"),
            } : undefined} />
        </Suspense>
      )}
      {tab === "library" && !ui.flags["ui_v2.library"] && (
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
          onRequestLink={ui.flags["ui_v2.shell"] ? (id) => setRequestSheet({ projectId: id }) : undefined}
          renderList={ui.flags["ui_v2.ask"] ? (ctx) => (
            <Suspense fallback={null}>
              <div className="el-root el-embedded">
                <ProjectsListV2 projects={ctx.projects} error={ctx.error} onRetry={ctx.onReload} items={ctx.items} expanded={ctx.expanded} onToggle={ctx.onToggle}
                  onOpen={ctx.onOpenProject} onNew={ctx.onNew} onRequest={ctx.onRequest} overview={ui.flags["ui_v2.library"]} />
              </div>
            </Suspense>
          ) : null}
          renderNameDialog={ui.flags["ui_v2.ask"] ? (props) => (
            <Suspense fallback={null}><div className="el-root el-embedded"><NameDialogV2 {...props} /></div></Suspense>
          ) : null}
          renderPage={ui.flags["ui_v2.ask"] ? (ctx) => (
            <Suspense fallback={null}>
              <div className="el-root el-embedded">
                <WorkspaceV2 key={ctx.project.id} project={{ id: ctx.project.id, name: ctx.project.name, ai_notes: ctx.project.ai_notes }} parent={ctx.parent} folders={ctx.folders}
                  menu={ctx.menu} onShare={ctx.onShare ?? undefined} onSend={ctx.onSend ?? undefined} onRequest={ctx.onRequest ?? undefined}
                  startTab={projectTab?.id === ctx.project.id ? projectTab : null}
                  rules={ctx.rules} projects={ctx.projects} aiNotesInPlan={ctx.aiNotesInPlan}
                  onMoveRecordings={ctx.onMoveRecordings} onProjectChanged={ctx.onProjectChanged} notify={notify}
                  onBack={() => openProject(ctx.parent?.id ?? null)} onProjects={() => openProject(null)} onOpenProject={(id) => openProject(id)}
                  onOpenRecording={(id, ms) => openRecording(id, ms ?? 0)} onLibrary={() => openFolder(null)} />
              </div>
            </Suspense>
          ) : null}
          aside={<ProjectsColumn projects={projects} selectedId={projectId} expanded={expanded} onToggle={toggleExpanded} onOpen={openProject} onNew={newProject} />} />
      )}
      {tab === "record" && ui.flags["ui_v2.home"] && (
        <Suspense fallback={null}>
          <div className="el-root el-embedded">
            <HomeV2 status={status} onRecord={canRecord && !status.recording ? record : undefined} onStop={status.recording ? record : undefined}
              onSetup={() => openAccount(ui.flags["ui_v2.settings"] && ui.flags["ui_v2.setup_health"] ? "setup_health" : "mic")}
              onReadyCheck={() => openAccount(ui.flags["ui_v2.settings"] && ui.flags["ui_v2.setup_health"] ? "setup_health" : "mic")}
              onOpenRecording={(id) => openRecording(id)} onLibrary={() => openFolder(null)} onPlan={() => openAccount("plan")}
              run={run} onImport={canRecord ? () => void pickImport() : undefined} notifications={activityItems.filter(needsActionItem).length}
              onAction={(a) => {
                if (a === "notifications") goTo("activity");
                else if (a === "failed") openLibraryView("failed");
                else if (a === "waiting") openLibraryView("waiting");
                else if (a === "missed") goTo("activity");
                else {
                  openFolder(null);
                  setLibraryViewV2({ view: "untranscribed", seq: ++navigations.current });
                }
              }} />
          </div>
        </Suspense>
      )}
      {tab === "record" && !ui.flags["ui_v2.home"] && (
        <HomeView status={status} run={run} onImport={canRecord ? () => void pickImport() : undefined} needsYou={activityItems}
          onReview={() => openLibraryView("waiting")} onFailed={() => openLibraryView("failed")} onActivity={() => goTo("activity")}
          onOpenRecording={(id) => openRecording(id)} onLibrary={() => openFolder(null)}
          assistOn={ui.flags.live_assist} projects={(projects ?? []).map((p) => ({ id: p.id, name: p.name }))} />
      )}
      {tab === "activity" && !ui.flags["ui_v2.shell"] && (
        <ActivityView status={status} notify={notify} onItems={setActivityItems} onOpenRecording={(id) => openRecording(id)}
          onReview={(id) => { openFolder(null); if (id) setFocus({ id, rename: false, seq: ++navigations.current }); }} />
      )}
      {tab === "activity" && ui.flags["ui_v2.shell"] && (
        <Suspense fallback={null}>
          <div className="el-root el-embedded">
            <ActivityV2 status={status} show={activityShow} needsYou={needsYou} onItems={setActivityItems} onOpenRecording={(id) => openRecording(id)} say={(text, error) => notify(text, error, error ? NO_TARGET : { kind: "library", view: null })}
              onReview={(id) => {
                openFolder(null);
                if (ui.flags["ui_v2.library"]) setReviewV2({ seq: ++navigations.current });
                else if (id) setFocus({ id, rename: false, seq: ++navigations.current });
              }}
              onFailed={() => (ui.flags["ui_v2.library"] ? openFolder(null) : openLibraryView("failed"))} />
          </div>
        </Suspense>
      )}
      {tab === "shared" && !ui.flags["ui_v2.shell"] && (
        <SharedView tab={sharedTab} onTab={setSharedTab} notify={notify} projects={projects} onOpenShared={(id) => { openFolder("shared"); setSharedFocus(id); }}
          onOpenRecording={(id) => openRecording(id)} onOpenProject={(id) => openProject(id)} />
      )}
      {tab === "shared" && ui.flags["ui_v2.shell"] && (
        <Suspense fallback={null}>
          <div className="el-root el-embedded">
            <SharedV2 tab={sharedTab} onTab={(t) => { setSharedTab(t); setSharedFocus(null); }} notify={notify} projects={projects} focusId={sharedFocus} onFocusHandled={() => setSharedFocus(null)}
              onOpenRecording={(id) => openRecording(id)} onOpenProject={(id) => openProject(id)}
              onSaved={(saved) => { if (saved.client_id) openProject(saved.client_id); else if (saved.session_id) openRecording(saved.session_id); }} />
          </div>
        </Suspense>
      )}
      {tab === "settings" && ui.flags["ui_v2.settings"] && (
        <Suspense fallback={null}>
          <div className="el-root el-embedded">
            <SettingsV2 key={settingsVisit} available={settingsAvailable} section={settingsSection} onSignOut={() => void signOut()} setupHealth={ui.flags["ui_v2.setup_health"]}
              inPanel={ui.flags["ui_v2.library"]}
              footer={<ClassicSwitchV2 state={ui} onState={setUi} />}
              renderPage={(section, onHome) => section.id === "setup_health" && ui.flags["ui_v2.setup_health"]
                // Two levels (UX-11): Setup & health is the rail's own place, with no "‹ Settings".
                ? <SetupHealthV2 platform={status.platform} onBack={ui.flags["ui_v2.library"] ? undefined : onHome} />
                : section.id === "help" && helpV2Reachable(ui.flags)
                  ? <HelpV2 platform={status.platform} threeLevels={ui.flags["ui_v2.library"]} zoom={ui.flags.v21_zoom} onMacCheck={() => setSettingsSection({ id: "help.mac_check", seq: ++navigations.current })}
                    start={helpStart} onSettings={openAccount}
                    {...(helpStart?.from && settingsSection?.seq === helpStart.seq
                      ? { onBack: () => setTab(helpStart.from!), backLabel: TAB_NAMES[helpStart.from] }
                      // Two levels: back to its group's page (About & help), never to "Settings".
                      : ui.flags["ui_v2.library"] ? { onBack: () => openAccount(HELP_GROUP.entry), backLabel: t(HELP_GROUP.title) } : { onBack: onHome })} />
                  : hasV2Page(section.id, ui.flags["ui_v2.library"])
                    ? <SettingsPagesV2 section={section} threeLevels={ui.flags["ui_v2.library"]} grouped={ui.flags["ui_v2.library"]} available={settingsAvailable} zoom={ui.flags.v21_zoom}
                      language={urdu && account ? { value: accountLanguage(account) ?? lang, onChange: (l) => void changeLanguage(l), error: languageError } : undefined}
                      onHome={onHome} onSection={(id) => openAccount(id)} run={run} status={status} onUnpaired={setStatus} theme={theme} onTheme={chooseTheme} />
                    : <SettingsView status={status} run={run} onUnpaired={setStatus} theme={theme} onTheme={chooseTheme} section={section} pageOnly={{ onHome }} />} />
          </div>
        </Suspense>
      )}
      {/* Classic Settings offers the new design from its own Appearance page (docs/PLAN_V2_PARITY.md 0). */}
      {tab === "settings" && !ui.flags["ui_v2.settings"] && <SettingsView status={status} run={run} onUnpaired={setStatus} theme={theme} onTheme={chooseTheme} section={settingsSection} />}
    </>
  );
  const overlays = (
    <>
    {palette && (
        <CommandPalette status={status} onClose={() => { setPalette(false); setPaletteFrom(""); }} onGo={goTo} look={ui.flags["ui_v2.shell"] ? "v2" : "classic"} initialQuery={paletteFrom}
          inRow={ui.flags["ui_v2.shell"] && ui.flags["ui_v2.library"]}
          actionState={{ canRecord, recording: !!status.recording, autoRecord: status.autoMode }}
          onShowAll={(q) => {
            // The Library becomes the results: All recordings, nothing filtered, searched for what was typed.
            setTab("library");
            setLibraryFolder(null);
            setLibraryView(null);
            setSharedFocus(null);
            setLibraryScope(ALL_SCOPE);
            setScopeRequest({ scope: ALL_SCOPE, seq: ++navigations.current, restore: { scope: ALL_SCOPE, spec: {}, query: q, tab: "all" } });
          }}
          onAsk={ui.flags["ui_v2.ask"] ? (q) => {
            setAskProject((a) => ({ id: a.id, seq: a.seq + 1, question: q }));
            setPlaceRequest((r) => ({ place: "ask", seq: (r?.seq ?? 0) + 1 }));
          } : undefined}
          folders={shownFolders(views?.folders, tab === "library" ? libraryFolder : null)} onFolder={openFolder}
          run={(fn, ok, target) => void run(fn, ok, target)} pages={pages}
          extra={{ upload: canRecord ? () => void pickImport() : undefined, newProject, requestLink: () => setRequestSheet({ projectId: null }),
            invite: () => void api.openServerPage("app/workspace").catch((e) => notify(errorMessage(e), true)), account: openAccount, help: () => setHelp(true),
            upgrade: entitlements && (upgradeOpen || plansAbove(entitlements).length > 0) ? { open: upgradeOpen, run: () => (upgradeOpen ? openUpgradeSheet(null, { step: "status" }) : openUpgradeSheet(null, { plan: plansAbove(entitlements)[0]?.key ?? null })) } : undefined }}
          settings={findSettings}
          onOpenRecording={(id, ms) => openRecording(id, ms)} />
      )}
      {dragging && !importPaths && !ui.flags["ui_v2.shell"] && (
        <div className="drop-hint" aria-hidden="true"><div className="drop-hint-card">Drop voice notes, audio or video files to import them</div></div>
      )}
      {importPaths && !ui.flags["ui_v2.shell"] && <ImportSheet paths={importPaths} notify={notify} onClose={closeImport} />}
      {/* The new design's drop hint and import sheet: the same drop handler, its own look. */}
      {dragging && !importPaths && ui.flags["ui_v2.shell"] && <Suspense fallback={null}><div className="el-root el-embedded"><DropHintV2 /></div></Suspense>}
      {importPaths && ui.flags["ui_v2.shell"] && (
        <Suspense fallback={null}>
          <div className="el-root el-embedded"><ImportSheetV2 paths={importPaths} notify={notify} onClose={closeImport} /></div>
        </Suspense>
      )}
      {help && <HelpDialog platform={status.platform} threeLevels={ui.flags["ui_v2.library"]} zoom={ui.flags.v21_zoom} onClose={() => setHelp(false)} onSettings={openAccount} />}
      {requestSheet && !ui.flags["ui_v2.shell"] && <RequestLinksSheet projects={projects ?? []} notify={notify} onClose={() => setRequestSheet(false)} />}
      {requestSheet && ui.flags["ui_v2.shell"] && (
        <Suspense fallback={null}>
          <div className="el-root el-embedded">
            <RequestLinkSheetV2 projects={projects ?? []} projectId={requestSheet.projectId} notify={notify} onClose={() => setRequestSheet(false)} onShowLinks={() => openShared("requests")} />
          </div>
        </Suspense>
      )}
      <UpgradeSheet />
      <ConfirmHost />
      {menuBarView}
      {toastView}
    </>
  );

  // The new app shell (ui_v2.shell): the five modules around today's pages, without the classic sidebar and top bar.
  if (ui.flags["ui_v2.shell"]) {
    return (
      <>
        {/* macOS, a normal window, the classic sidebar: the empty strip for the window's own buttons, which drags and zooms
            the window (D-214, lib/titlebar.ts); in full screen it is not drawn and the classic strip below keeps 2.0.12's
            window. The three levels have their title row instead (UX-20): the buttons sit in the rail's top cell. */}
        {!ui.flags["ui_v2.library"] && <div className="el-titlebar" data-tauri-drag-region aria-hidden="true" data-testid="titlebar-strip" />}
        {!ui.flags["ui_v2.library"] && <div className="titlebar-drag" data-tauri-drag-region aria-hidden="true" />}
        <Suspense fallback={null}>
          <ShellV2 tab={tab} onGo={goTo} status={shellChip(status, (app) => sourceAppLabel(app, "en"))}
            rail={collapsed} onRail={toggleSidebar} width={sidebarWidth} onWidth={resizeSidebar} uploading={uploading} uploadsWait={!!waitingFor}
            workspace={shellWorkspace} activity={needsYou} activityTone={activityBadge.tone} recording={!!status.recording} onRecord={canRecord ? () => run(status.recording ? api.stopRecording : api.recordNow) : undefined} onVoiceNote={canRecord ? voiceNote : undefined}
            onSetup={() => openAccount(ui.flags["ui_v2.settings"] && ui.flags["ui_v2.setup_health"] ? "setup_health" : "mic")}
            pinnedProjects={(projects ?? []).filter((p) => p.pinned).map((p) => ({ id: p.id, name: p.name }))} projectId={projectId} onProject={(id) => openProject(id)}
            projectTree={(projects ?? []).map((p) => ({ id: p.id, name: p.name, parent_id: p.parent_id ?? null, pinned: !!p.pinned, archived: !!p.archived || !!p.archived_at }))}
            shared={{ tabs: SHARED_TABS, current: tab === "shared" ? sharedTab : null, open: (t) => openShared(t as SharedTab) }}
            onUpload={canRecord ? () => void pickImport() : undefined} onNewProject={newProject} onRequestLink={() => setRequestSheet({ projectId: null })} onSearch={(typed?: string) => { setPaletteFrom(typed ?? ""); setPalette(true); }} searchKeys={paletteKeys(status.platform)} onHelp={openHelp}
            library={ui.flags["ui_v2.library"] ? (
              <Suspense fallback={null}>
                <LibraryNavV2 current={tab === "library" ? scopeKey(libraryScope) : tab === "shared" ? `shared:${sharedTab}` : null}
                  counts={libraryCounts} labelCounts={labelCounts} onScope={openScope}
                  shared={{ tabs: SHARED_TABS, current: tab === "shared" ? sharedTab : null, open: (t) => openShared(t as SharedTab) }}
                  onNewView={() => { if (tab !== "library") openScope(libraryScope); setFiltersRequest({ seq: ++navigations.current }); }}
                  say={(text, _action, error) => notify(text, !!error, NO_TARGET)} onLibraryChanged={() => void syncLibrary().catch(() => undefined)} />
              </Suspense>
            ) : undefined}
            libraryAttention={(libraryCounts?.needs ?? 0) > 0}
            nav={{
              panelFlyout: ui.flags.v21_panel_flyout,
              noProjects: projects !== null ? projects.length === 0 : hadNoProjects,
              projectsLoading: projects === null && !projectsError,
              projectsError: projects === null ? projectsError : null,
              layoutFor: liveViews ?? lastViews,
              // The status items: Restart to update, then the zoom's chip while it isn't 100% (UX-07).
              titleEnd: <><RestartToUpdate appStatus={status} notify={notify} />{zoomOn && <ZoomChip />}</>,
              placeRequest,
              onPlace: openNavPlace,
              libraryCount: libraryCounts?.needs || undefined,
              liveDot: !!status.recording || status.captionsLive || status.dictationActive,
              liveDotWords: status.recording ? "recording" : status.captionsLive || status.dictationActive ? "on" : undefined,
              setupAttention: shellChip(status, (app) => sourceAppLabel(app, "en")).kind === "setup",
              callRecording: canRecord ? { on: status.autoMode, toggle: toggleAutoRecord } : undefined,
              account: account ? [
                // Who it is: the menu's own header (DES-08: the avatar, the name, the email, the plan).
                { label: "Account", icon: Icons.UserIcon, onPick: () => openAccount("profile") },
                { label: "Plan & usage", icon: Icons.PlanIcon, onPick: () => openAccount("plan") },
                { label: "Theme", icon: Icons.AppearanceIcon, items: ([["system", "System"], ["light", "Light"], ["dark", "Dark"]] as const).map(([value, label]) => ({ label, radio: true, checked: theme === value, onPick: () => chooseTheme(value) })) },
                // UX-07: "Zoom  [−] 100% [+]", as Chrome's menu has it, next to Theme.
                ...(zoomOn ? [{ label: "Zoom", icon: Icons.ZoomIcon, stepper: zoomStepper(zoomLevel) }] : []),
                ...(shellWorkspace ? [
                  { label: shellWorkspace.role ? `${shellWorkspace.name} · ${shellWorkspace.role}` : shellWorkspace.name, group: "Workspace", disabled: true },
                  ...(shellWorkspace.invite ? [{ label: shellWorkspace.invite.locked ? `Invite people (${shellWorkspace.invite.locked})` : "Invite people", icon: Icons.UsersIcon, group: "Workspace", onPick: shellWorkspace.invite.onPick }] : []),
                  { label: "Switch or manage in the browser", icon: Icons.WebIcon, group: "Workspace", onPick: shellWorkspace.onManage },
                ] : []),
                ...(account.super_admin === true ? [{ label: "Admin panel", icon: Icons.LinkIcon, onPick: () => void api.openServerPage("admin").catch((e) => notify(errorMessage(e), true)) }] : []),
                { label: "Sign out", icon: Icons.SignOutIcon, separator: true, onPick: () => void signOut() },
              ] : undefined,
              panels: {
                live: <Suspense fallback={null}><LivePanelV2 callRecording={status.autoMode} onCalls={() => openAccount("calls")} onPart={showLivePart} /></Suspense>,
                ask: <Suspense fallback={null}><AskPanelV2 projects={(projects ?? []).map((p) => ({ id: p.id, name: p.name }))} current={askProject.id}
                  onAsk={(id) => setAskProject((a) => ({ id, seq: a.seq + 1 }))} /></Suspense>,
                activity: <Suspense fallback={null}><ActivityPanelV2 show={activityShow} needs={needsYou} onShow={(next) => { setActivityShow(next); setTab("activity"); }} /></Suspense>,
                settings: <Suspense fallback={null}><SettingsPanelV2 current={tab === "settings" ? settingsSection?.id ?? null : null} onOpen={openAccount} available={settingsAvailable} /></Suspense>,
              },
            }}
            footStart={ui.flags["ui_v2.library"] ? (
              <Suspense fallback={null}>
                <LibraryFootV2 trashCurrent={tab === "library" && libraryScope.kind === "trash"} onTrash={() => openScope({ kind: "trash" })}
                  onSync={() => void syncLibrary().catch((e) => notify(errorMessage(e), true))} />
              </Suspense>
            ) : undefined}
            places={{
              // Classic's sidebar form: no All, the places that hold something, the Inbox always (it takes drops). Shared
              // with me is Shared's own, under Library.
              places: places.filter((p) => p.key !== "all" && p.folder !== "shared" && (p.count > 0 || p.drop || p.key === (libraryFolder ?? libraryView))).map((p) => ({ ...p, label: placeWords(p) }))
                .map((p) => ({ key: p.key, label: p.label, count: p.count, drop: p.drop })),
              current: libraryFolder ?? libraryView ?? null,
              open: (key) => { const p = places.find((x) => x.key === key); if (p) openPlace(p); },
            }}
            onAdmin={account?.super_admin === true ? () => void api.openServerPage("admin").catch((e) => notify(errorMessage(e), true)) : undefined}
            me={account ? { name: account.name || account.email, email: account.email, plan: entitlements?.plan?.name ?? null,
              usage: minutesLine(entitlements?.limits.monthly_minutes?.used, entitlements?.limits.monthly_minutes?.limit), color: account.avatar_color ?? null, photo: avatarPhoto } : null}
            notices={<Suspense fallback={null}><div className="el-root el-embedded">
              <AfterCallNoticeV2 text={status.afterCallNotice} onStatus={setStatus} />
              <ShareDefaultNotice text={status.shareNotice} />
            </div></Suspense>}
            ask={ui.flags["ui_v2.ask"] ? (
              <Suspense fallback={null}><div className="el-root el-embedded">
                <AskV2 key={askProject.seq} initialProjectId={askProject.id} initialQuestion={askProject.question} projects={(projects ?? []).map((p) => ({ id: p.id, name: p.name }))} onOpenAt={(id, ms) => openRecording(id, ms)}
                  onOpenProject={(id) => openProject(id)} onLibrary={() => openFolder(null)} />
              </div></Suspense>
            ) : undefined}
            live={ui.flags["ui_v2.live"] ? (
              <Suspense fallback={null}><div className="el-root el-embedded">
                <LiveV2 status={status} assistOn={ui.flags.live_assist} projects={(projects ?? []).map((p) => ({ id: p.id, name: p.name }))}
                  onRecord={canRecord ? record : undefined} onVoiceNote={canRecord ? voiceNote : undefined}
                  onCaptions={() => void run(api.toggleCaptions)} onAutoRecord={canRecord ? toggleAutoRecord : undefined} onSettings={openAccount} run={run} />
              </div></Suspense>
            ) : undefined}
            banners={<NoticeLook.Provider value="v2"><div className="el-root el-embedded el-app-banners" role="status" aria-live="polite" aria-label="Notices" data-testid="app-banners">{banners}</div></NoticeLook.Provider>}>
            <section ref={contentRef} className={`content page-${tab}`}>{page}</section>
          </ShellV2>
        </Suspense>
        {overlays}
      </>
    );
  }

  return (
    <div className={`app ${collapsed ? "collapsed" : ""} ${pageWidth < NARROW_CONTENT ? "narrow" : ""} ${pageWidth < COLUMN_CONTENT ? "no-column" : ""}`}
      style={{ ["--sidebar-w" as string]: `${collapsed ? SIDEBAR_RAIL : sidebarWidth}px` }}>
      {/* macOS: the title bar is inset (traffic lights over the sidebar); this strip keeps the window draggable. */}
      <div className="titlebar-drag" data-tauri-drag-region aria-hidden="true" />
      <Sidebar tab={tab} collapsed={collapsed} onCollapse={toggleSidebar} width={sidebarWidth} onWidth={resizeSidebar} needsYou={needsYou}
        recording={!!status.recording} places={places} folder={libraryFolder} view={libraryView} onPlace={openPlace}
        projects={projects} projectId={projectId} onProject={openProject} sharedTab={tab === "shared" ? sharedTab : null} onShared={openShared}
        onGo={goTo} onUpload={() => void pickImport()} onHelp={() => setHelp(true)} onSearch={() => setPalette(true)} searchKeys={paletteKeys(status.platform)} uploading={uploading} uploadsWait={!!waitingFor} canUpload={canRecord}
        libraryColumn={pageWidth >= COLUMN_CONTENT} />
      <div className="main-col">
        <TopBar status={status} account={account} needsYou={needsYou} theme={theme} onTheme={chooseTheme} onPalette={() => setPalette(true)}
          onActivity={() => goTo("activity")} onAccount={() => openAccount("overview")} onPlan={() => openAccount("plan")}
          onAdmin={() => void api.openServerPage("admin").catch((e) => notify(errorMessage(e), true))}
          onWorkspaces={() => void api.openServerPage("app/workspace").catch((e) => notify(errorMessage(e), true))}
          onReady={() => goTo("record")} onRecord={record} onVoiceNote={voiceNote} onUpload={() => void pickImport()} onNewProject={newProject}
          onRequestLink={() => setRequestSheet({ projectId: null })} onHelp={() => setHelp(true)} onSignOut={() => void signOut()} canRecord={canRecord} />
        <main ref={contentRef} className={`content page-${tab}`}>
          {banners}
          {page}
        </main>
      </div>
      {overlays}
    </div>
  );
}

/** The last call's side that was never heard (Phase 1C): one sentence and the Ready check that fixes it; why and how
 * in full behind "Learn more". */
function OneSidedBanner({ said, onDismiss, onReady, onOpen }: { said: OneSided | null; onDismiss: () => void; onReady: () => void; onOpen: () => void }) {
  const shown = useBannerTurn("one-sided", !!said);
  if (!shown || !said) return null;
  return (
    <Notice kind="banner" tone="warn" className="banner warn one-sided-banner" role="alert" testId="one-sided" icon={<AlertIcon size={16} />} timeoutMs={null}
      onOpen={onOpen} onDismiss={onDismiss} details={<LearnMore>{said.reason} {said.fix}</LearnMore>}
      actions={<button type="button" className="btn compact" onClick={onReady}>Ready check</button>}>
      <span className="one-sided-text"><b>{said.title}.</b></span>
    </Notice>
  );
}

/** The budget banner's state: the level and the month it is about (its reset date), so closing it lasts that month
 * only; without the date it is closed for this run only. */
export function budgetState(level: string, resetsAt: string | null | undefined): string {
  return resetsAt ? `${level}|${resetsAt}` : level;
}
