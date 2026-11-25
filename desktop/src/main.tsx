import React, { Suspense, lazy } from "react";
import ReactDOM from "react-dom/client";
import { getCurrentWebviewWindow } from "@tauri-apps/api/webviewWindow";
import "./styles.css";
import { applyTheme, getThemeChoice, watchSystemTheme } from "./lib/theme";
import { applyPreferences, loadPreferences } from "./lib/shell";
import { api, listenTo } from "./lib/ipc";
import { watchTitlebar, watchWinTitlebar } from "./lib/titlebar";
import { loadZoom, setZoom, ZOOM_DEFAULT } from "./lib/zoom";
import { installNativeFeel, SYNC_NOW_EVENT } from "./lib/nativeFeel";
import { watchTitleDoubleClick } from "./lib/titleClick";
import { getCurrentWindow } from "@tauri-apps/api/window";
import { WindowErrorBoundary } from "./components/WindowErrorBoundary";

// One index.html for every window; the view is chosen from the window label.
const MainApp = lazy(() => import("./views/main/MainApp"));
const OverlayApp = lazy(() => import("./views/overlay/OverlayApp"));
const DictationApp = lazy(() => import("./views/dictation/DictationApp"));
const PillApp = lazy(() => import("./views/pill/PillApp"));
const ReviewApp = lazy(() => import("./views/review/ReviewApp"));
const TranslateOverlayApp = lazy(() => import("./views/translate/TranslateOverlayApp"));
const ShareCheckWindow = lazy(() => import("./views/sharecheck/ShareCheckWindow"));
// Live Assist's card (v1.9 L4), a window the core opens for a session; it draws nothing unless its flags are on.
const AssistApp = lazy(() => import("./v2/assist/AssistApp"));
const ShareCheckTest = () => <ShareCheckWindow kind="test" />;
const ShareCheckControl = () => <ShareCheckWindow kind="control" />;
const ShareCheckCard = () => <ShareCheckWindow kind="card" />;

/** Views of the overlay windows the core builds on demand (lifecycle.rs); any other label is the main window. */
const WINDOW_VIEWS = new Map<string, React.ComponentType>([
  ["overlay", OverlayApp],
  ["dictation", DictationApp],
  ["pill", PillApp],
  ["review", ReviewApp],
  ["translate", TranslateOverlayApp],
  // Share Check (since v1.8.1): its test window, its control and the guided test's card.
  ["share-check-test", ShareCheckTest],
  ["share-check-control", ShareCheckControl],
  ["share-check-card", ShareCheckCard],
  ["assist", AssistApp],
]);

function currentLabel(): string {
  try {
    return getCurrentWebviewWindow().label;
  } catch {
    return "main"; // plain browser (Vite dev / e2e)
  }
}

const label = currentLabel();
document.documentElement.dataset.window = label;
applyTheme(getThemeChoice());
watchSystemTheme();
// Account → Preferences (v1.6): the sidebar's style, density and the clock, before anything is drawn.
applyPreferences(loadPreferences());
// UX-07: the main window's kept zoom, before it first shows (it is hidden until its page is ready), so it doesn't jump;
// only when the core's cached flags say v21_zoom is on, so with it off the window never opens zoomed.
if (label === "main" && loadZoom() !== ZOOM_DEFAULT) {
  api.uiFlags().then((a) => { if (a.ui_flags?.v21_zoom) setZoom(loadZoom(), false); }, () => undefined);
}

// C2: the window material (Mica / vibrancy). The accent stays the design system's indigo, as on the phone, so the OS
// accent colour is not applied (lib/accent.ts can still map one). Backgrounds turn translucent only when the core
// says the material applied; the native title bar follows the app's theme.
// The main window's title bar is overlaid on a Mac: its buttons get their strip, except in full screen (D-214). The core
// ran titlebarStart.js before this page, so the strip is already drawn: the window is asked at once whether it is full
// screen (no wait for window_appearance), and only that answer can take the strip away.
const isFullscreen = () => getCurrentWindow().isFullscreen();
let watchingTitlebar = false;
if (label === "main" && document.documentElement.dataset.platform === "macos") {
  watchingTitlebar = true;
  watchTitlebar("macos", isFullscreen);
  // A double click on the title row does what the Mac's setting says (UX-20), not always zoom.
  watchTitleDoubleClick("macos");
}
api
  .windowAppearance()
  .then((a) => {
    document.documentElement.dataset.platform = a.platform;
    if (label === "main" && a.material !== "none") document.documentElement.dataset.material = a.material;
    if (label === "main" && !watchingTitlebar) {
      watchTitlebar(a.platform, isFullscreen);
      watchTitleDoubleClick(a.platform);
    }
    // Windows with v21_win_titlebar: the page draws the title row and the window's buttons (wintitle.rs).
    if (label === "main" && a.platform === "windows") watchWinTitlebar(api.windowTitlebar, (follow) => listenTo<boolean>("window:titlebar", follow));
  })
  .catch(() => undefined);
if (label === "main") api.setWindowTheme(getThemeChoice()).catch(() => undefined);
// FIX-10: an app, not a web page. In a release build the web view's menu and keys go from the main window (its
// floating windows have their own few keys and no menu either); F5 and ⌘R sync now.
installNativeFeel({ release: import.meta.env.PROD, onSyncNow: () => window.dispatchEvent(new Event(SYNC_NOW_EVENT)) });

const View = WINDOW_VIEWS.get(label) ?? MainApp;

const root = document.getElementById("root") as HTMLElement;
// The v2 design gallery: a development build with ?v2gallery only. The build check comes first and nothing of the
// gallery is imported above, so a release build removes this branch and the gallery with it (v2/gallery/release.test.ts).
if (import.meta.env.DEV && new URLSearchParams(location.search).has("v2gallery")) {
  void import("./v2/gallery/mount").then((m) => m.mountGallery(root));
} else {
  ReactDOM.createRoot(root).render(
    <React.StrictMode>
      <WindowErrorBoundary label={label}>
        {/* While the window's code loads, index.html's "Starting EchoLedger…" stays (the main window), never nothing. */}
        <Suspense fallback={label === "main" ? <div className="boot" role="status">Starting EchoLedger…</div> : null}>
          <View />
        </Suspense>
      </WindowErrorBoundary>
    </React.StrictMode>,
  );
}
