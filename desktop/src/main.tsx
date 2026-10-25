import React, { Suspense, lazy } from "react";
import ReactDOM from "react-dom/client";
import { getCurrentWebviewWindow } from "@tauri-apps/api/webviewWindow";
import "./styles.css";
import { applyTheme, getThemeChoice, watchSystemTheme } from "./lib/theme";

// One index.html for every window; the view is chosen from the window label.
const MainApp = lazy(() => import("./views/main/MainApp"));
const OverlayApp = lazy(() => import("./views/overlay/OverlayApp"));
const DictationApp = lazy(() => import("./views/dictation/DictationApp"));
const PillApp = lazy(() => import("./views/pill/PillApp"));
const ReviewApp = lazy(() => import("./views/review/ReviewApp"));

/** Views of the windows the core creates in its setup hook; any other label is the main window. */
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
if (label === "main") {
  applyTheme(getThemeChoice());
  watchSystemTheme();
}

const View = WINDOW_VIEWS.get(label) ?? MainApp;

ReactDOM.createRoot(document.getElementById("root") as HTMLElement).render(
  <React.StrictMode>
    <Suspense fallback={null}>
      <View />
    </Suspense>
  </React.StrictMode>,
);
