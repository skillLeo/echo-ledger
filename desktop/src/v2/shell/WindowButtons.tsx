// Windows' own title row (UX-20, v21_win_titlebar; wintitle.rs): Minimize, Maximize (Restore Down once maximized) and
// Close at the row's end, each as wide as the system's (46 px) and as tall as the row; Close turns red under the pointer.
// They take the system's words for tooltips and names. As on Windows' own title bar they are not in the Tab order: the
// keyboard has Alt+Space (the window's menu), Win+↑ / Win+↓ and Alt+F4. On Windows 11 a small native window lies over
// Maximize (tauri-plugin-snap-layout) so the pointer resting on it opens Snap Layouts; a press there maximizes or
// restores through the system, and the plugin's `is-hovered` class draws its hover.
import { useEffect, useState } from "react";
import { getCurrentWindow } from "@tauri-apps/api/window";
import { Icon, Icons } from "../components";

/** The id the snap layout plugin looks for (wintitle.rs MAXIMIZE_ID). */
export const MAXIMIZE_ID = "el-window-maximize";

export function WindowButtons() {
  const [maximized, setMaximized] = useState(false);
  useEffect(() => {
    const win = getCurrentWindow();
    let alive = true;
    const read = () => void win.isMaximized().then((m) => { if (alive) setMaximized(m); }, () => undefined);
    read();
    const off = win.onResized(read);
    return () => {
      alive = false;
      void off.then((stop) => stop(), () => undefined);
    };
  }, []);
  const restore = maximized ? "Restore Down" : "Maximize";
  return (
    <div className="el-winbuttons" role="group" aria-label="Window" data-tauri-drag-region="false" data-testid="window-buttons">
      <button type="button" className="el-winbutton" tabIndex={-1} aria-label="Minimize" title="Minimize" onClick={() => void getCurrentWindow().minimize()}>
        <Icon icon={Icons.WindowMinimizeIcon} />
      </button>
      <button type="button" id={MAXIMIZE_ID} className="el-winbutton" tabIndex={-1} aria-label={restore} title={restore} onClick={() => void getCurrentWindow().toggleMaximize()}>
        <Icon icon={maximized ? Icons.WindowRestoreIcon : Icons.WindowMaximizeIcon} />
      </button>
      <button type="button" className="el-winbutton el-winbutton--close" tabIndex={-1} aria-label="Close" title="Close" onClick={() => void getCurrentWindow().close()}>
        <Icon icon={Icons.CloseIcon} />
      </button>
    </div>
  );
}
