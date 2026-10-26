import React, { Suspense, lazy } from "react";
import ReactDOM from "react-dom/client";
import { getCurrentWebviewWindow } from "@tauri-apps/api/webviewWindow";
import "./styles.css";
import { applyTheme, getThemeChoice, watchSystemTheme } from "./lib/theme";
import { cssAccent, parseHex, setSystemAccent } from "./lib/accent";
import { api } from "./lib/ipc";

// One index.html for every window; the view is chosen from the window label.
const MainApp = lazy(() => import("./views/main/MainApp"));
const OverlayApp = lazy(() => import("./views/overlay/OverlayApp"));
const DictationApp = lazy(() => import("./views/dictation/DictationApp"));
const PillApp = lazy(() => import("./views/pill/PillApp"));
const ReviewApp = lazy(() => import("./views/review/ReviewApp"));

/** Views of the overlay windows the core builds on demand (lifecycle.rs); any other label is the main window. */
const WINDOW_VIEWS = new Map([
  ["overlay", OverlayApp],
  ["dictation", DictationApp],
  ["pill", PillApp],
  ["review", ReviewApp],
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

// C2: the window material (Mica / vibrancy) and the system accent. Backgrounds turn translucent only when the core
// says the material applied; the native title bar follows the app's theme.
api
  .windowAppearance()
  .then((a) => {
    document.documentElement.dataset.platform = a.platform;
    if (label === "main" && a.material !== "none") document.documentElement.dataset.material = a.material;
    setSystemAccent(parseHex(a.accent) ?? cssAccent());
  })
  .catch(() => undefined);
if (label === "main") api.setWindowTheme(getThemeChoice()).catch(() => undefined);

const View = WINDOW_VIEWS.get(label) ?? MainApp;

ReactDOM.createRoot(document.getElementById("root") as HTMLElement).render(
  <React.StrictMode>
    <Suspense fallback={null}>
      <View />
    </Suspense>
  </React.StrictMode>,
);
