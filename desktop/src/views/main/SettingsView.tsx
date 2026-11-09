// Settings (v1.7; "Account" before): who you are at the top, then the same sections and rows as the phone and the
// console (Recording, Transcription, App, Account, Support) from one registry (lib/settingsRegistry.ts). Two columns
// from 760 px of the settings area (a list on the left, the page on the right, the Overview when nothing is chosen),
// a list and then the page below that. The search box finds every single setting (lib/settingsIndex.ts), opens its
// page and briefly highlights it.
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { api, listenTo, type Account, type AppStatus, type Entitlements, type InputDevice, type Settings, type SettingsPatch, type StorageInfo } from "../../lib/ipc";
import { prettyShortcut } from "../../lib/format";
import { applyTheme, getThemeChoice, saveThemeChoice, type ThemeChoice } from "../../lib/theme";
import { loadPreferences, PREFERENCES_EVENT, savePreferences, type Preferences } from "../../lib/shell";
import { isLocked, lowestPlan, openRequest, openUpgradeSheet, planName, plansAbove, requestStage, useEntitlements } from "../../lib/entitlements";
import { breadcrumbOf, entriesOn, findSetting, optionLabel, pageFor, ROOT_SECTIONS, SETTINGS, setting, type SettingEntry } from "../../lib/settingsRegistry";
import { searchSettings, type SettingResult } from "../../lib/settingsIndex";
import { LucideChevronLeftIcon, LucideChevronRightIcon, LucideSearchIcon, LucideXIcon, ROOT_ICONS } from "../../components/Icons";
import { SavedIndicator } from "../../components/Page";
import { ConfirmDialog, LinkRow, Section, Tag, highlightSetting, rowAttrs } from "./SettingsControls";
import { CallSitesPage, CallsPage, CaptionStylePage, CaptionsPage, MicrophonePage, callAppsValue } from "./CaptureSettings";
import { ExcludedAppsPage, ScreenMomentsPage } from "./ScreenSettings";
import { HistoryPage, ReplacementsPage, SnippetsPage, VoicePage } from "./DictationSettings";
import { DevicesPage, LockPage, NotificationsPage, SignInActivityPage, StoragePage, notificationsValue, storageValue } from "./SecuritySettings";
import { ConsentPage, PrivacyPage, ProfilePage, SecurityPage, roleOf, useAccount, workspaceLine } from "./AccountSettings";
import { AboutPage, AppearancePage, HelpPage, LanguagePage, ShortcutsPage, TechnicalPage, WordsPage, languageValue, useVersion } from "./AppSettings";
import { PlanUsagePage, ThisMonth, TranscriptionKeyPage, canUpgrade } from "./Upgrade";
import { AccountHeader, StateRow, stateRow } from "./ProfileCard";
import { MacCheckCard } from "./MacCheck";

/** Root rows that are a single setting but open a page of their own on the desktop (a select needs room). */
const ROW_PAGES: ReadonlySet<string> = new Set(["transcription.language"]);

/** Two columns from this width of the settings area (not the window: the sidebar takes part of it). */
export const TWO_COLUMNS = 760;

/** Sections other parts of the app open by their v1.6 ids, and where each is now ("page" or "page#setting"). */
export const LEGACY_SECTIONS: Record<string, string> = {
  profile: "profile", "sign-in": "security", "sign-in-methods": "security", devices: "devices", notifications: "notifications", preferences: "appearance",
  plan: "plan", "all-features": "plan#plan.compare", key: "key", "transcription-key": "key", privacy: "privacy", "your-data": "privacy",
  calls: "calls", voice: "voice", computer: "about", general: "words", shortcuts: "shortcuts", about: "about", help: "help", lock: "lock", storage: "storage",
};

/** What decides whether a setting is offered: this computer, the account, the plan. */
export interface SettingsContext {
  platform: string;
  account: Account | null | undefined;
  entitlements: Entitlements | null | undefined;
  role: string | null;
}

/** Whether an entry is offered here and now (the search, the list, the palette and Find a page use the same). */
export function settingsAvailability(ctx: SettingsContext): (entry: SettingEntry) => boolean {
  const e = ctx.entitlements;
  const open = openRequest(e);
  const allowed = canUpgrade(ctx.role);
  return (entry) => {
    const r = entry.requires;
    if (r?.os && r.os !== ctx.platform) return false;
    if (r?.account && !ctx.account) return false;
    if (r?.plans && !e) return false;
    switch (entry.id) {
      case "key":
      case "key.saved":
        // Shown on a plan without transcription of its own, or whenever a key is saved.
        return !!e && (isLocked(e, "platform_transcription") || !!e.transcription_key?.set) && (entry.id === "key" || !!e.transcription_key?.set);
      case "plan.upgrade":
        return allowed && !open && plansAbove(e).length > 0;
      case "plan.promo":
        return allowed && !open && plansAbove(e).length > 0;
      case "plan.payment_details":
      case "plan.receipt":
        return allowed && (!!open || plansAbove(e).length > 0);
      case "plan.request":
      case "plan.history":
        return allowed && !!e?.upgrade_request;
      case "plan.usage.members":
        return (e?.limits.seats?.limit ?? 0) > 1;
      case "plan.usage.uploads":
        return false;
      case "help.article.pay":
      case "help.article.approval_time":
      case "help.article.receipt":
        return allowed && !!e;
      default:
        if (entry.id.startsWith("plan.included.")) return e?.limits[entry.id.slice("plan.included.".length)]?.limit !== undefined;
        return true;
    }
  };
}

/** The pages "Find a page" and the palette offer: every available page of the root, under "Settings › {section}". */
export function settingsPages(available: (entry: SettingEntry) => boolean): { id: string; label: string; group: string; keywords: string; page: string }[] {
  return SETTINGS.filter((e) => e.page === null && e.kind === "page" && available(e))
    .map((e) => ({ id: `settings-${e.id}`, label: e.title, group: `Settings › ${e.root ?? ""}`.replace(/ › $/, ""), keywords: ["settings", ...(e.keywords ?? [])].join(" "), page: e.id }));
}

/** "Sign out of this computer?" and, when recordings wait, what happens to them. */
export function signOutBody(pending: number): string | null {
  if (pending <= 0) return null;
  return pending === 1
    ? "1 recording hasn't uploaded yet. It'll stay on this computer, but nobody else can see it until you sign in again."
    : `${pending} recordings haven't uploaded yet. They'll stay on this computer, but nobody else can see them until you sign in again.`;
}
/** The question the avatar menu's Sign out asks (window.confirm). */
export function signOutConfirm(pending: number): string {
  return ["Sign out of this computer?", signOutBody(pending)].filter(Boolean).join(" ");
}

/** The width of an element, followed as it changes (wide when it can't be measured). */
function useWidth(ref: React.RefObject<HTMLElement | null>): number {
  const [width, setWidth] = useState(Number.POSITIVE_INFINITY);
  useEffect(() => {
    const el = ref.current;
    if (!el || typeof ResizeObserver === "undefined") return;
    const observer = new ResizeObserver(([entry]) => setWidth(entry.contentRect.width));
    observer.observe(el);
    return () => observer.disconnect();
  }, [ref]);
  return width;
}

type Run = <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined>;

export function SettingsView({ status, run, onUnpaired, theme, onTheme, section = null }: {
  status: AppStatus;
  /** A page or setting to open ("plan", "lock#lock.after", or a v1.6 section id), with a fresh `seq`. */
  section?: { id: string; seq: number } | null;
  run: Run;
  onUnpaired: (s: AppStatus) => void;
  theme?: ThemeChoice;
  onTheme?: (theme: ThemeChoice) => void;
}) {
  const [ownTheme, setOwnTheme] = useState<ThemeChoice>(getThemeChoice);
  const themeChoice = theme ?? ownTheme;
  const [settings, setSettings] = useState<Settings | null>(null);
  const [settingsFailed, setSettingsFailed] = useState(false);
  const [prefs, setPrefs] = useState<Preferences>(loadPreferences);
  const [savedAt, setSavedAt] = useState<number | null>(null);
  const [page, setPage] = useState<string | null>(null);
  const [target, setTarget] = useState<{ id: string; seq: number } | null>(null);
  const [query, setQuery] = useState("");
  const [cursor, setCursor] = useState(0);
  const [article, setArticle] = useState<string | null>(null);
  const [signingOut, setSigningOut] = useState(false);
  const [account, setAccount, reloadAccount] = useAccount();
  const [, setDismissed] = useState(0);
  const entitlements = useEntitlements();
  const root = useRef<HTMLDivElement | null>(null);
  const width = useWidth(root);
  const wide = width >= TWO_COLUMNS;
  const seqRef = useRef(0);
  // Root rows' values: the devices signed in, the storage, the inputs and the projects, read once.
  const [deviceCount, setDeviceCount] = useState<number | null>(null);
  const [storage, setStorage] = useState<StorageInfo | null>(null);
  const [inputs, setInputs] = useState<InputDevice[]>([]);
  const [clients, setClients] = useState<{ id: string; name: string }[]>([]);
  const version = useVersion();

  const loadSettings = useCallback(() => {
    setSettingsFailed(false);
    api.settings().then(setSettings, () => setSettingsFailed(true));
  }, []);
  useEffect(() => {
    loadSettings();
    // Changes made elsewhere (tray, "Always ignore" on the ask chip, a removed client) show up while this is open.
    const un = listenTo("settings:changed", (next) => next && setSettings(next));
    api.securityOverview().then((o) => setDeviceCount((o.devices ?? []).length), () => undefined);
    api.storage().then(setStorage, () => undefined);
    api.inputDevices().then(setInputs, () => undefined);
    api.clients().then((r) => setClients(r.data), () => undefined);
    return () => {
      un.then((f) => f()).catch(() => undefined);
    };
  }, [loadSettings]);

  const role = roleOf(account, status);
  const workspace = account?.workspace?.name || status.plan.workspace || null;
  const ctx: SettingsContext = { platform: status.platform, account, entitlements, role };
  const available = useMemo(() => settingsAvailability(ctx), [ctx.platform, ctx.account, ctx.entitlements, ctx.role]); // eslint-disable-line react-hooks/exhaustive-deps

  /** Opens a page, scrolled to `id` and highlighted; null is the Overview (or the list, below 760 px). */
  const open = useCallback((next: string | null, id: string | null = null) => {
    setQuery("");
    setPage(next);
    if (id) setTarget({ id, seq: ++seqRef.current });
    if (!id) root.current?.querySelector(".settings-page")?.scrollTo?.({ top: 0 });
  }, []);
  // A page or a setting asked for from elsewhere (the avatar menu, a notice, the palette, a recording that needs the key).
  useEffect(() => {
    if (!section) return;
    const [where, id] = (LEGACY_SECTIONS[section.id] ?? section.id).split("#");
    const entry = findSetting(where);
    if (!entry) return open(null);
    const pageId = entry.kind === "page" || ROW_PAGES.has(entry.id) ? entry.id : pageFor(entry);
    open(pageId, id ?? (entry.kind === "page" ? null : entry.id));
  }, [section, open]);
  // Arriving at a setting: once its row is on the page, scroll to it and highlight it (the page may still be reading).
  useEffect(() => {
    if (!target) return;
    let tries = 0;
    const timer = window.setInterval(() => {
      if (highlightSetting(target.id, root.current ?? document) || ++tries > 40) {
        window.clearInterval(timer);
        setTarget(null);
      }
    }, 50);
    return () => window.clearInterval(timer);
  }, [target, page]);

  const patch = async (p: SettingsPatch): Promise<boolean> => {
    const next = await run(() => api.updateSettings(p));
    if (next) {
      setSettings(next);
      setSavedAt(Date.now());
    }
    return !!next;
  };
  const chooseTheme = (next: ThemeChoice) => {
    if (onTheme) onTheme(next);
    else {
      saveThemeChoice(next);
      applyTheme(next);
      setOwnTheme(next);
    }
    setSavedAt(Date.now());
  };
  const setPref = (change: Partial<Preferences>) => {
    const next = { ...prefs, ...change };
    setPrefs(next);
    savePreferences(next);
    window.dispatchEvent(new CustomEvent(PREFERENCES_EVENT, { detail: next }));
    setSavedAt(Date.now());
  };
  const signOut = async () => {
    setSigningOut(false);
    const s = await run(api.unpair);
    if (s) onUnpaired(s);
  };

  // ---- Where a search result or a row goes --------------------------------------------------------------------------
  const go = (entry: SettingEntry) => {
    const e = entitlements;
    switch (entry.id) {
      case "plan.upgrade":
        return openUpgradeSheet(null, { plan: plansAbove(e)[0]?.key ?? null });
      case "plan.request":
      case "plan.history":
        return e?.upgrade_request ? openUpgradeSheet(null, { step: "status" }) : open("plan");
      case "plan.payment_details":
        return openRequest(e) ? openUpgradeSheet(null, { step: "pay" }) : openUpgradeSheet(null, { plan: plansAbove(e)[0]?.key ?? null });
      case "plan.receipt": {
        const stage = requestStage(openRequest(e));
        return stage === "awaiting_payment" || stage === "needs_receipt" ? openUpgradeSheet(null, { step: "receipt" }) : openRequest(e) ? openUpgradeSheet(null, { step: "status" }) : open("plan", "plan.upgrade");
      }
      case "plan.promo":
        return openUpgradeSheet(null, { plan: plansAbove(e)[0]?.key ?? null, step: "choose" });
      case "signout":
        return setSigningOut(true);
    }
    if (entry.kind === "help") {
      setArticle(entry.id);
      return open("help", entry.id);
    }
    if (entry.kind === "page") return open(entry.id);
    if (ROW_PAGES.has(entry.id)) return open(entry.id, entry.id);
    if (entry.page === null) return open(null, entry.id);
    open(entry.page, entry.id);
  };
  const goId = (id: string) => go(setting(id));

  // ---- Root rows' values --------------------------------------------------------------------------------------------
  const e = entitlements;
  const valueOf = (id: string): ReactNode => {
    const s = settings;
    switch (id) {
      case "calls": return s ? callAppsValue(s) : "";
      case "mic": return s ? (s.inputDevice ? inputs.find((d) => d.id === s.inputDevice)?.name ?? "Chosen microphone" : "Automatic") : "";
      case "captions": return s ? (s.captionsOnRecord ? "On" : "Off") : "";
      case "screen": return isLocked(e, "screen_moments") ? <Tag>{planName(lowestPlan(e, "screen_moments"))}</Tag> : s ? (s.screenMoments ? "On" : "Off") : "";
      case "voice": return s?.shortcuts.dictation ? prettyShortcut(s.shortcuts.dictation, status.platform) : "";
      case "transcription.language": return s ? languageValue(s.transcriptionLanguage) : "";
      case "words": return s ? (s.clientId ? clients.find((c) => c.id === s.clientId)?.name ?? "A project" : "None") : "";
      case "key": return e?.transcription_key?.set ? "Added" : "Not added";
      case "appearance": return optionLabel("appearance.theme", themeChoice);
      case "notifications": return s ? notificationsValue(s) : "";
      case "lock": return s ? (s.appLock ? "On" : "Off") : "";
      case "storage": return storageValue(e?.limits.storage_bytes?.used ?? storage?.recordings_bytes, e?.limits.storage_bytes?.limit ?? status.plan.storageBytes);
      case "plan": return e?.plan ? planName(e.plan) : "";
      case "security": return account ? (account.two_factor ? "Two-step on" : "Two-step off") : "";
      case "devices": return deviceCount !== null ? `${deviceCount} signed in` : "";
      case "about": return version ?? "";
      default: return "";
    }
  };
  /** A search result's value: a switch's state, a choice's label, or a root row's value. */
  const resultValue = (entry: SettingEntry): ReactNode => {
    const s = settings;
    if (entry.page === null) return valueOf(entry.id);
    if (!s) return "";
    const choice: Record<string, string | undefined> = {
      "calls.after": s.afterCall, "calls.skip_short": String(s.skipCallsShorterThanS), "captions.size": s.captionStyle.size, "captions.lines": String(s.captionLines),
      "captions.position": s.overlayCorner, "captions.width": s.captionStyle.width, "captions.autohide": String(s.captionStyle.autoHideSeconds), "screen.target": s.screenTarget,
      "voice.shortcut_mode": s.dictationMode, "voice.output": s.dictationOutput, "appearance.theme": themeChoice, "appearance.sidebar": prefs.sidebarStyle,
      "appearance.density": prefs.density, "appearance.clock": prefs.clock, "lock.after": String(s.lockAfterMinutes),
    };
    if (entry.id in choice) return optionLabel(entry.id, choice[entry.id] ?? "");
    const on: Record<string, boolean> = {
      "calls.auto": s.autoMode, "calls.ask_unknown": s.askUnknownApps, "calls.browser_tabs": s.browserAutomation, "mic.far_side_warning": s.farSideWarning,
      "captions.enabled": s.captionsOnRecord, "captions.screen_share": !s.contentProtection, "captions.compact": s.captionStyle.compact, "screen.enabled": s.screenMoments,
      "voice.cleanup": s.dictationCleanup, "voice.sounds": s.dictationSounds, "notifications.enabled": s.activityNotifications, "notifications.quiet": s.quietHours, "lock.enabled": s.appLock,
    };
    if (entry.id in on) return on[entry.id] ? "On" : "Off";
    if (entry.id === "captions.opacity") return `${s.captionStyle.opacity}%`;
    return "";
  };
  const lockedTagOf = (entry: SettingEntry): string | null => (entry.id === "screen.enabled" && isLocked(e, "screen_moments") ? planName(lowestPlan(e, "screen_moments")) : null);

  // ---- Search -------------------------------------------------------------------------------------------------------
  const answer = useMemo(() => searchSettings(query, available), [query, available]);
  const results: SettingResult[] = useMemo(() => [...answer.settings, ...answer.help], [answer]);
  useEffect(() => setCursor(0), [query]);
  const pagesWithHits = useMemo(() => {
    const counts = new Map<string, number>();
    for (const r of answer.settings) {
      let p: SettingEntry | undefined = r.entry;
      while (p && p.page !== null) p = findSetting(p.page);
      if (p) counts.set(p.id, (counts.get(p.id) ?? 0) + 1);
    }
    return counts;
  }, [answer]);
  const searching = query.trim().length >= 2;
  const onSearchKey = (ev: React.KeyboardEvent) => {
    if (ev.key === "Escape") {
      ev.preventDefault();
      setQuery("");
    } else if (ev.key === "ArrowDown" && results.length > 0) {
      ev.preventDefault();
      setCursor((c) => (c + 1) % results.length);
    } else if (ev.key === "ArrowUp" && results.length > 0) {
      ev.preventDefault();
      setCursor((c) => (c - 1 + results.length) % results.length);
    } else if (ev.key === "Enter" && results[cursor]) {
      ev.preventDefault();
      go(results[cursor].entry);
    }
  };

  // ---- The list (left column, or the whole page below 760 px) ---------------------------------------------------------
  const rootRows = entriesOn(null).filter(available);
  const listRow = (entry: SettingEntry) => {
    const Icon = ROOT_ICONS[entry.id];
    const icon = Icon ? <Icon size={20} /> : undefined;
    const here = page !== null && (page === entry.id || pageChain(page).includes(entry.id));
    const hits = searching ? pagesWithHits.get(entry.id) : undefined;
    if (entry.id === "app.autostart") {
      return (
        <div key={entry.id} className={`set-row nav-row with-icon ${here ? "active" : ""}`.trim()} {...rowAttrs(entry.id)}>
          <label className="set-hit">
            <span className="set-icon" aria-hidden="true">{icon}</span>
            <span className="set-title">{entry.title}</span>
            {settings && <button type="button" className={`switch ${settings.autostart ? "on" : ""}`} role="switch" aria-checked={settings.autostart} aria-label={entry.title} onClick={() => void patch({ autostart: !settings.autostart })} />}
          </label>
        </div>
      );
    }
    if (entry.id === "signout") return null;
    return (
      <button key={entry.id} type="button" className={`set-row set-link nav-row with-icon ${here ? "active" : ""}`.trim()} data-nav={entry.id} aria-current={here ? "page" : undefined}
        onClick={() => go(entry)}>
        <span className="set-icon" aria-hidden="true">{icon}</span>
        <span className="set-title">{entry.title}</span>
        <span className="set-end">
          {hits ? <span className="nav-count" aria-label={`${hits} ${hits === 1 ? "match" : "matches"}`}>{hits}</span> : !wide && <span className="set-value">{valueOf(entry.id)}</span>}
          {!wide && <ChevronRight />}
        </span>
      </button>
    );
  };
  const sections = ROOT_SECTIONS.map((head) => {
    const rows = rootRows.filter((r) => (r.root ?? "") === head && (!searching || pagesWithHits.has(r.id) || r.id === "app.autostart" && answer.settings.some((x) => x.entry.id === r.id)));
    if (rows.length === 0 || head === "") return null;
    return (
      <Section key={head} title={head} className="nav-section">
        {rows.map(listRow)}
      </Section>
    );
  });
  const searchBox = (
    <div className="settings-search">
      <label className="searchbox set-search">
        <LucideSearchIcon size={16} />
        <input value={query} onChange={(ev) => setQuery(ev.target.value)} onKeyDown={onSearchKey} placeholder="Search settings" aria-label="Search settings"
          aria-controls="settings-results" />
        {query && <button type="button" className="btn ghost icon-only search-clear" aria-label="Clear search" onClick={() => setQuery("")}><LucideXIcon size={14} /></button>}
      </label>
    </div>
  );
  const header = (
    <>
      <AccountHeader account={account} e={e} role={role} workspace={workspace} teamLine={workspaceLine(account, status)} onPlan={() => (e ? open("plan") : undefined)} />
      <StateRow row={stateRow({
        // A server with plans has accounts: none from it means the read failed.
        account, accountFailed: account === null && status.paired && !!e, e, status,
        onPrivacy: () => open("privacy", "privacy.cancel_deletion"), onKey: () => open("key"), onRetry: reloadAccount,
        onPlan: () => open("plan"), onDismissed: () => setDismissed((n) => n + 1),
      })} />
    </>
  );
  const list = (
    <nav className="settings-list" aria-label="Settings">
      {wide ? searchBox : null}
      {!wide && !searching && header}
      {!wide && searchBox}
      {!(searching && !wide) && sections}
      {!searching && rootRows.some((r) => r.id === "signout") && status.paired && (
        <Section className="nav-section">
          <button type="button" className="set-row set-link set-danger nav-row" {...rowAttrs("signout")} onClick={() => setSigningOut(true)}><span className="set-title">Sign out</span></button>
        </Section>
      )}
    </nav>
  );

  // ---- The page -----------------------------------------------------------------------------------------------------
  const needSettings = (render: (s: Settings) => ReactNode) => settings ? render(settings) : settingsFailed ? (
    <div className="set-section"><div className="set-group set-block" role="alert">
      <p>Couldn't read this computer's settings.</p>
      <button type="button" className="btn compact" onClick={loadSettings}>Try again</button>
    </div></div>
  ) : null;
  const pages: Record<string, () => ReactNode> = {
    calls: () => needSettings((s) => <CallsPage settings={s} patch={patch} platform={status.platform} onSites={() => open("calls.sites")} consentRead={s.consentAcknowledged} onConsent={() => open("privacy.consent")} />),
    "calls.sites": () => needSettings((s) => <CallSitesPage rules={s.siteRules} onSave={(siteRules) => patch({ siteRules })} />),
    mic: () => needSettings((s) => <MicrophonePage settings={s} patch={patch} disabled={!!status.recording || status.dictationActive} systemAudioNote={status.systemAudioNote} />),
    captions: () => needSettings((s) => <CaptionsPage settings={s} patch={patch} onStyle={() => open("captions.style")} />),
    "captions.style": () => needSettings((s) => <CaptionStylePage settings={s} patch={patch} />),
    screen: () => needSettings((s) => <ScreenMomentsPage settings={s} patch={patch} onExcluded={() => open("screen.excluded")} />),
    "screen.excluded": () => needSettings((s) => <ExcludedAppsPage settings={s} patch={patch} platform={status.platform} />),
    voice: () => needSettings((s) => <VoicePage settings={s} patch={patch} onOpen={(p) => open(p)} />),
    "voice.replacements": () => needSettings((s) => <ReplacementsPage settings={s} patch={patch} />),
    "voice.snippets": () => needSettings((s) => <SnippetsPage settings={s} patch={patch} />),
    "voice.history": () => <HistoryPage />,
    "transcription.language": () => needSettings((s) => <LanguagePage settings={s} patch={patch} />),
    words: () => needSettings((s) => <WordsPage settings={s} patch={patch} />),
    key: () => (e ? <TranscriptionKeyPage e={e} /> : null),
    appearance: () => <AppearancePage theme={themeChoice} onTheme={chooseTheme} prefs={prefs} setPref={setPref} />,
    notifications: () => needSettings((s) => <NotificationsPage settings={s} patch={patch} run={run} />),
    lock: () => needSettings((s) => <LockPage settings={s} patch={patch} run={run} />),
    storage: () => <StoragePage limitBytes={e?.limits.storage_bytes?.limit ?? status.plan.storageBytes} />,
    shortcuts: () => needSettings((s) => <ShortcutsPage settings={s} status={status} run={run} onSettings={setSettings} />),
    profile: () => (account ? <ProfilePage account={account} status={status} run={run} /> : null),
    plan: () => (e ? <PlanUsagePage e={e} role={role} workspace={workspace} onKey={() => open("key")} /> : null),
    security: () => (account ? <SecurityPage account={account} run={run} onSignedOut={onUnpaired} onActivity={() => open("security.activity")} /> : null),
    "security.activity": () => <SignInActivityPage />,
    devices: () => <DevicesPage run={run} />,
    privacy: () => needSettings((s) => <PrivacyPage account={account ?? null} settings={s} onAccount={setAccount} onConsent={() => open("privacy.consent")} />),
    "privacy.consent": () => needSettings((s) => <ConsentPage status={status} settings={s} patch={patch} />),
    help: () => <HelpPage platform={status.platform} available={available} article={article} onArticle={setArticle} onTarget={goId} onMacCheck={() => open("help.mac_check")} />,
    "help.mac_check": () => <MacCheckCard status={status} />,
    about: () => <AboutPage status={status} run={run} onImported={setSettings} onDevices={() => open("devices")} onTechnical={() => open("about.technical")} />,
    "about.technical": () => <TechnicalPage status={status} />,
  };
  const pageEntry = page ? findSetting(page) : undefined;
  const shownPage = pageEntry && available(pageEntry) && pages[page!] ? page : null;
  const back = shownPage ? setting(shownPage).page : null;
  const backLabel = back ? setting(back).title : "Settings";
  const results_ = searching && (
    <div id="settings-results" className="settings-results" data-testid="settings-results">
      <h1 className="set-page-title">Results for “{query.trim()}”</h1>
      {results.length === 0 ? (
        <div className="set-empty" role="status">
          <p className="set-empty-title">No settings match “{query.trim()}”</p>
          <p className="muted">Check the spelling or try another word.</p>
          {answer.closest.length > 0 && (
            <Section title="Closest matches">
              {answer.closest.map((entry) => <ResultRow key={entry.id} entry={entry} breadcrumb={breadcrumbOf(entry)} matched={[]} value={resultValue(entry)} tag={lockedTagOf(entry)} active={false} onOpen={() => go(entry)} />)}
            </Section>
          )}
          <Section>
            <LinkRow id="help" title="Search help" onOpen={() => open("help")} chevron />
          </Section>
        </div>
      ) : (
        <>
          <div className="sr-only" aria-live="polite">{results.length === 1 ? "1 result" : `${results.length} results`}</div>
          {answer.settings.length > 0 && (
            <Section title="Settings">
              <div role="listbox" aria-label="Settings found">
                {answer.settings.map((r, i) => <ResultRow key={r.entry.id} entry={r.entry} breadcrumb={r.breadcrumb} matched={r.matched} value={resultValue(r.entry)} tag={lockedTagOf(r.entry)} active={i === cursor} onOpen={() => go(r.entry)} />)}
              </div>
            </Section>
          )}
          {answer.help.length > 0 && (
            <Section title="Help">
              <div role="listbox" aria-label="Help found">
                {answer.help.map((r, i) => <ResultRow key={r.entry.id} entry={r.entry} breadcrumb={firstLine(r.entry)} matched={r.matched} value="" tag={null} active={answer.settings.length + i === cursor} onOpen={() => go(r.entry)} />)}
              </div>
            </Section>
          )}
        </>
      )}
    </div>
  );
  const overview = (
    <div className="settings-overview" data-testid="settings-overview">
      <h1 className="set-page-title">Settings</h1>
      {header}
      {e && <ThisMonth e={e} quiet={!!openRequest(e) || !canUpgrade(role)} onKey={() => open("key")} footer={false}
        onUpgrade={plansAbove(e)[0] ? () => openUpgradeSheet(null, { plan: plansAbove(e)[0].key }) : undefined} />}
      {e && <Section><LinkRow id="plan" onOpen={() => open("plan")} value={e.plan ? planName(e.plan) : undefined} chevron /></Section>}
    </div>
  );
  const pageView = shownPage && (
    <div className="settings-page" data-page={shownPage}>
      {(!wide || back) && (
        <button type="button" className="btn text back-link" onClick={() => open(back)}><LucideChevronLeftIcon size={16} />{backLabel}</button>
      )}
      <div className="set-page-head">
        <h1 className="set-page-title">{setting(shownPage).title}</h1>
        <SavedIndicator at={savedAt} />
      </div>
      {pages[shownPage]()}
    </div>
  );
  const content = results_ || pageView || (wide ? overview : null);
  return (
    <div ref={root} className={`settings-root ${wide ? "wide" : "narrow"}`} data-testid="settings">
      {wide ? (
        <div className="settings-columns">
          {list}
          <div className="settings-content">{content}</div>
        </div>
      ) : (
        <div className="settings-single">
          {!shownPage && !searching && <h1 className="set-page-title">Settings</h1>}
          {shownPage ? pageView : searching ? <>{searchBox}{results_}</> : list}
        </div>
      )}
      {signingOut && (
        <ConfirmDialog title="Sign out of this computer?" body={signOutBody(status.pendingUploads) ?? undefined} confirm="Sign out"
          onConfirm={() => void signOut()} onClose={() => setSigningOut(false)} />
      )}
    </div>
  );
}

/** The pages above `page` (its parent, the parent's parent…). */
function pageChain(page: string): string[] {
  const chain: string[] = [];
  let p = findSetting(page)?.page ?? null;
  while (p) {
    chain.push(p);
    p = findSetting(p)?.page ?? null;
  }
  return chain;
}


function firstLine(entry: SettingEntry): string {
  const text = entry.answer ?? "";
  const end = text.search(/[.!?](\s|$)/);
  return end === -1 ? text : text.slice(0, end + 1);
}

function ChevronRight() {
  return <LucideChevronRightIcon size={16} className="set-trail" />;
}

/** The title with the words that matched in bold. */
function Highlighted({ text, words }: { text: string; words: string[] }) {
  if (words.length === 0) return <>{text}</>;
  const parts: ReactNode[] = [];
  const re = new RegExp(`\\b(${words.map((w) => w.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")).join("|")})`, "gi");
  let last = 0;
  for (const m of text.matchAll(re)) {
    const at = m.index ?? 0;
    if (at > last) parts.push(text.slice(last, at));
    parts.push(<b key={at}>{m[0]}</b>);
    last = at + m[0].length;
  }
  parts.push(text.slice(last));
  return <>{parts}</>;
}

function ResultRow({ entry, breadcrumb: crumb, matched, value, tag, active, onOpen }: {
  entry: SettingEntry;
  breadcrumb: string;
  matched: string[];
  value: ReactNode;
  tag: string | null;
  active: boolean;
  onOpen: () => void;
}) {
  let rootPage: SettingEntry | undefined = entry;
  while (rootPage && rootPage.page !== null) rootPage = findSetting(rootPage.page);
  const Icon = entry.kind === "help" ? ROOT_ICONS.help : rootPage ? ROOT_ICONS[rootPage.id] : undefined;
  const ref = useRef<HTMLButtonElement | null>(null);
  useEffect(() => {
    if (active) ref.current?.scrollIntoView?.({ block: "nearest" });
  }, [active]);
  const spoken = [entry.title, crumb ? `in ${crumb}` : null, typeof value === "string" && value ? value : null, tag ? `in ${tag}` : null].filter(Boolean).join(", ");
  return (
    <button ref={ref} type="button" role="option" aria-selected={active} className={`set-row set-link set-two with-icon result-row ${active ? "active" : ""}`.trim()}
      data-result={entry.id} aria-label={spoken} onClick={onOpen}>
      <span className="set-icon" aria-hidden="true">{Icon ? <Icon size={20} /> : null}</span>
      <span className="set-main">
        <span className="set-title"><Highlighted text={entry.title} words={matched} /></span>
        {crumb && <span className="set-sub">{crumb}</span>}
      </span>
      {(tag || value) && <span className="set-end">{tag ? <Tag>{tag}</Tag> : <span className="set-value">{value}</span>}</span>}
    </button>
  );
}

