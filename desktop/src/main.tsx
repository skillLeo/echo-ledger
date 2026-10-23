import React, { Suspense, lazy } from "react";
import ReactDOM from "react-dom/client";
import { getCurrentWebviewWindow } from "@tauri-apps/api/webviewWindow";
import "./styles.css";

// One index.html for every window; the view is chosen from the window label.
const MainApp = lazy(() => import("./views/main/MainApp"));
const OverlayApp = lazy(() => import("./views/overlay/OverlayApp"));
const DictationApp = lazy(() => import("./views/dictation/DictationApp"));

function currentLabel(): string {
  try {
    return getCurrentWebviewWindow().label;
  } catch {
    return "main"; // plain browser (Vite dev / e2e)
  }
}

const label = currentLabel();
document.documentElement.dataset.window = label;

const View = label === "overlay" ? OverlayApp : label === "dictation" ? DictationApp : MainApp;

ReactDOM.createRoot(document.getElementById("root") as HTMLElement).render(
  <React.StrictMode>
    <Suspense fallback={null}>
      <View />
    </Suspense>
  </React.StrictMode>,
);
