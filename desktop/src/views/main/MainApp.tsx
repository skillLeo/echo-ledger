import { useCallback, useEffect, useState } from "react";
import { listen } from "@tauri-apps/api/event";
import { api, errorMessage, type AppStatus } from "../../lib/ipc";
import { CaptionsIcon, LibraryIcon, MicIcon, MonitorIcon, MoonIcon, SettingsIcon, SunIcon } from "../../components/Icons";
import { applyTheme, getThemeChoice, nextThemeChoice, saveThemeChoice, type ThemeChoice } from "../../lib/theme";
import { PairingView } from "./PairingView";
import { LibraryView } from "./LibraryView";
import { RecordView } from "./RecordView";
import { SettingsView } from "./SettingsView";

type Tab = "library" | "record" | "settings";

export default function MainApp() {
  const [status, setStatus] = useState<AppStatus | null>(null);
  const [tab, setTab] = useState<Tab>("library");
  const [toast, setToast] = useState<{ text: string; error?: boolean } | null>(null);
  const [theme, setTheme] = useState<ThemeChoice>(getThemeChoice);

  const cycleTheme = () => {
    const next = nextThemeChoice(theme);
    saveThemeChoice(next);
    applyTheme(next);
    setTheme(next);
  };

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

  if (!status) return <div className="empty">Starting EchoLedger…</div>;
  if (!status.paired) return <PairingView onPaired={setStatus} notify={notify} />;

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
          <div className="row"><CaptionsIcon size={14} />{status.captionsLive ? "Captions live" : "Captions off"}</div>
          <div>{status.deviceName}</div>
          <div className="small">{status.pendingUploads > 0 ? `${status.pendingUploads} upload(s) pending` : "All uploads done"}</div>
        </div>
      </aside>
      <main className="content">
        {tab === "library" && <LibraryView notify={notify} />}
        {tab === "record" && <RecordView status={status} run={run} />}
        {tab === "settings" && <SettingsView status={status} run={run} onUnpaired={setStatus} />}
      </main>
      {toast && <div className={`toast ${toast.error ? "error" : ""}`} role="status">{toast.text}</div>}
    </div>
  );
}
