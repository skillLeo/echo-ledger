// The shared pieces every Urdu screen uses, in one window (desktop Urdu, the shared fixes): rows whose Urdu line starts
// with a Latin name, a plain English line, dates and times as lib/format writes them (in a row and in .el-ltr), a page
// head whose title is a Latin name, a toast, and (&scene=sheet) a Sheet over the window. The words are the app's own
// catalogue's (src/i18n/ur.json) through t(), the account in Urdu. scripts/urduShared.mjs takes its pictures.
//   urduShared.html?platform=macos|windows&theme=light|dark&scene=lines|sheet
import React from "react";
import ReactDOM from "react-dom/client";
import "../../src/styles.css";
import conf from "../../src-tauri/tauri.conf.json";
import { applyTitlebar, titlebarMode } from "../../src/lib/titlebar";
import { setLanguage, t } from "../../src/i18n";
import { formatWhen } from "../../src/lib/format";
import { Button, Card, Icons, List, ListRow, Sheet, Toast, ToastRegion } from "../../src/v2/components";

const params = new URLSearchParams(location.search);
const platform = params.get("platform") === "windows" ? "windows" : "macos";
const theme = params.get("theme") === "dark" ? "dark" : "light";
const scene = params.get("scene") === "sheet" ? "sheet" : "lines";
document.documentElement.dataset.theme = theme;
document.documentElement.style.colorScheme = theme;
document.documentElement.dataset.platform = platform;
applyTitlebar(titlebarMode(platform, false));

/** macOS's window buttons where the app puts them, as scripts/screens/main.tsx draws them. */
function drawTrafficLights() {
  const { x, y } = conf.app.windows[0].trafficLightPosition;
  const strip = document.createElement("div");
  strip.dataset.trafficLights = "";
  strip.style.cssText = `position: fixed; left: ${x + 1}px; top: ${y - 4}px; width: 54px; height: 12px; z-index: 2147483647; pointer-events: none; display: flex; gap: 8px; direction: ltr;`;
  for (const colour of ["#ff5f57", "#febc2e", "#28c840"]) {
    const light = document.createElement("span");
    light.style.cssText = `width: 12px; height: 12px; border-radius: 50%; background: ${colour}; box-shadow: inset 0 0 0 0.5px rgba(0, 0, 0, 0.2);`;
    strip.append(light);
  }
  document.body.append(strip);
}
if (platform === "macos") drawTrafficLights();

const now = new Date();
const at = (daysAgo: number, hour: number) => new Date(now.getFullYear(), now.getMonth(), now.getDate() - daysAgo, hour, 0).toISOString();

function Lines() {
  return (
    // Below the window's buttons, as a page sits below the title row in the app.
    <div className="el-page el-page--wide" style={{ paddingBlockStart: 56 }}>
      <header className="el-page__head">
        <div className="el-page__titles">
          <h1 className="el-title-l" dir="auto">Contoso website</h1>
          <p className="el-secondary" dir="auto">Acme Holdings</p>
        </div>
      </header>
      <Card>
        <List label={t("Recordings")}>
          <ListRow title="Kick-off: Contoso website" meta={t("Remove :name", { name: "Acme Ltd" })} endTop={<span className="el-ltr">{formatWhen(at(2, 14))}</span>} />
          <ListRow title="Supplier call with Bilal" meta={t("Sign out :name?", { name: "Work laptop" })} endTop={<span className="el-ltr">{formatWhen(at(1, 9))}</span>} />
          <ListRow title="Weekly sync" meta="Payment terms and the March invoice." endTop={<span className="el-ltr">{formatWhen(at(0, 11))}</span>} />
          <ListRow title={t("Recordings")} meta={formatWhen(at(3, 14))} />
        </List>
      </Card>
      <ToastRegion>
        <Toast message={t("Reset :page to defaults?", { page: "Appearance" })} actionLabel={t("Undo")} onAction={() => undefined} onClose={() => undefined} duration={600_000} />
      </ToastRegion>
    </div>
  );
}

function SheetScene() {
  return (
    <>
      <Lines />
      <Sheet open title={t("Import")} description={t("Sign out :name?", { name: "Work laptop" })} onClose={() => undefined}
        actions={<><Button onClick={() => undefined}>{t("Cancel")}</Button><Button variant="primary" icon={Icons.CheckIcon} onClick={() => undefined}>{t("Import")}</Button></>} />
    </>
  );
}

void setLanguage("ur").then(() => {
  ReactDOM.createRoot(document.getElementById("root")!).render(
    <React.StrictMode>
      <div className="el-root" data-ready="true">{scene === "sheet" ? <SheetScene /> : <Lines />}</div>
    </React.StrictMode>,
  );
});
