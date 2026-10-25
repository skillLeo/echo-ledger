import { useCallback, useEffect, useRef, useState } from "react";
import { listen } from "@tauri-apps/api/event";
import { api, errorMessage, listenTo, type AppStatus } from "../../lib/ipc";
import { AlertIcon, CaptionsIcon, CloseIcon, LibraryIcon, MicIcon, MonitorIcon, MoonIcon, SettingsIcon, SunIcon } from "../../components/Icons";
import { applyTheme, getThemeChoice, nextThemeChoice, saveThemeChoice, type ThemeChoice } from "../../lib/theme";
import { PairingView } from "./PairingView";
import { LibraryView } from "./LibraryView";
import type { PendingFocus } from "./LibraryQueue";
import { RecordView } from "./RecordView";
import { SettingsView } from "./SettingsView";

type Tab = "library" | "record" | "settings";
const TABS: readonly Tab[] = ["library", "record", "settings"];

const BUDGET_REACHED = "Monthly transcription limit reached. Recordings are kept but not transcribed.";
const BUDGET_WARN = "You have used 80% of this month's transcription limit.";

export default function MainApp() {
  const [status, setStatus] = useState<AppStatus | null>(null);
  const [tab, setTab] = useState<Tab>("library");
  const [toast, setToast] = useState<{ text: string; error?: boolean } | null>(null);
  const [theme, setTheme] = useState<ThemeChoice>(getThemeChoice);
  const [focus, setFocus] = useState<PendingFocus | null>(null);
  const navigations = useRef(0);
  // The 80% notice can be dismissed until the app restarts; the limit-reached banner stays.
  const [budgetWarnDismissed, setBudgetWarnDismissed] = useState(false);

  const cycleTheme = () => {
    const next = nextThemeChoice(theme);
    saveThemeChoice(next);
    applyTheme(next);
    setTheme(next);
  };

  const clearFocus = useCallback(() => setFocus(null), []);

  const refreshStatus = useCallback(() => {
    api.status().then(setStatus).catch(() => undefined);
  }, []);

  useEffect(() => {
    refreshStatus();
    const timer = window.setInterval(refreshStatus, 1500);
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
      }),
    ];
    return () => {
      window.clearInterval(timer);
      unlisten.forEach((p) => p.then((f) => f()).catch(() => undefined));
    };
  }, [refreshStatus]);

  useEffect(() => {
    if (!toast) return;
    const t = window.setTimeout(() => setToast(null), 4500);
    return () => window.clearTimeout(t);
  }, [toast]);

  const notify = (text: string, error = false) => setToast({ text, error });
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

  if (!status || !status.paired) {
    return (
      <main className="gate">
        {status ? <PairingView onPaired={setStatus} notify={notify} /> : <div className="empty">Starting EchoLedger…</div>}
        {toastView}
      </main>
    );
  }

  return (
    <div className="app">
      <aside className="sidebar">
        <div className="brand"><span className="brand-mark" />EchoLedger</div>
        <button className={`nav-btn ${tab === "library" ? "active" : ""}`} onClick={() => setTab("library")}><LibraryIcon />Library</button>
        <button className={`nav-btn ${tab === "record" ? "active" : ""}`} onClick={() => setTab("record")}>
          <MicIcon />Record {status.recording && <span className="status-dot live" style={{ marginLeft: "auto" }} />}
        </button>
        <button className={`nav-btn ${tab === "settings" ? "active" : ""}`} onClick={() => setTab("settings")}><SettingsIcon />Settings</button>
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
      <main className={`content ${status.budget === "reached" || (status.budget === "warn" && !budgetWarnDismissed) ? "has-banner" : ""}`}>
        {status.budget === "reached" && (
          <div className="banner danger" data-testid="budget-banner"><AlertIcon size={16} /><span>{BUDGET_REACHED}</span></div>
        )}
        {status.budget === "warn" && !budgetWarnDismissed && (
          <div className="banner warn" data-testid="budget-banner">
            <AlertIcon size={16} /><span>{BUDGET_WARN}</span>
            <button type="button" className="banner-close" aria-label="Dismiss" onClick={() => setBudgetWarnDismissed(true)}><CloseIcon size={14} /></button>
          </div>
        )}
        {tab === "library" && <LibraryView notify={notify} focus={focus} onFocusHandled={clearFocus} />}
        {tab === "record" && <RecordView status={status} run={run} />}
        {tab === "settings" && <SettingsView status={status} run={run} onUnpaired={setStatus} />}
      </main>
      {toastView}
    </div>
  );
}
