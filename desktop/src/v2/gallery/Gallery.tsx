import { Icons, NavItem, Sidebar, Status } from "../components";
import { COPY } from "./content";
import type { GalleryParams } from "./guard";
import { LibraryScreen } from "./screens/Library";
import { RecordingScreen } from "./screens/Recording";
import { SettingsScreen } from "./screens/Settings";
import { KitScreen } from "./screens/Kit";
import "./gallery.css";
import { AssistCard } from "../assist/AssistCard";
import { FIXTURE_SESSION } from "../assist/fixture";
import { EMPTY_CARD, cardReducer } from "../assist/card";
import { PlanPageV2 } from "../settings/pages/PlanV2";
import { sampleEntitlements } from "../../test/fixtures";

/** Plan & usage with its meters part-full (amber minutes), to see the bars fill from the start edge in both directions. */
function planSample() {
  const e = sampleEntitlements("pro");
  e.limits.monthly_minutes = { limit: 600, used: 510, resets_at: "2026-12-01" };
  return e;
}

const noop = () => undefined;

/** The Gate 3 sample screens in the app's frame: the sidebar and one page, in the chosen language and direction. */
export function Gallery({ screen, lang, state = "ready" }: Pick<GalleryParams, "screen" | "lang"> & { state?: GalleryParams["state"] }) {
  const nav = COPY[lang].nav;
  const page = screen === "settings" || screen === "plan" ? "settings" : "library";
  return (
    <div className="el-root g-app" lang={lang} dir={lang === "ur" ? "rtl" : "ltr"} data-gallery-ready="true">
      <Sidebar label={nav.label} brand={<span lang="en" dir="ltr">EchoLedger</span>}
        footer={<div className="g-sidebar__status el-caption"><Status kind="ready">{nav.ready}</Status></div>}>
        <NavItem icon={Icons.HomeIcon} label={nav.home} />
        <NavItem icon={Icons.LibraryIcon} label={nav.library} current={page === "library"} />
        <NavItem icon={Icons.RecordIcon} label={nav.live} />
        <NavItem icon={Icons.AskIcon} label={nav.ask} />
        <NavItem icon={Icons.SettingsIcon} label={nav.settings} current={page === "settings"} />
      </Sidebar>
      <main className="g-main">
        {screen === "library" && <LibraryScreen lang={lang} state={state} />}
        {screen === "recording" && <RecordingScreen lang={lang} state={state} />}
        {screen === "settings" && <SettingsScreen lang={lang} state={state} />}
        {screen === "plan" && (
          <div className="el-page el-page--narrow el-settings-v2">
            <header className="el-page__head"><h1 className="el-title-l">{lang === "ur" ? "پلان اور استعمال" : "Plan & usage"}</h1></header>
            <PlanPageV2 e={planSample()} role={null} workspace={null} onKey={noop} />
          </div>
        )}
        {screen === "kit" && <KitScreen lang={lang} />}
        {screen === "assist" && (
          <div className="g-assist">
            <h1 className="el-visually-hidden">Live Assist</h1>
            <AssistCard state={FIXTURE_SESSION.reduce(cardReducer, EMPTY_CARD)} hint="outline" muted={false}
              actions={{ onSend: noop, onMode: noop, onHint: noop, onMute: noop, onHide: noop, onCopyConsent: noop, onStartOffer: noop, onDismissOffer: noop, onEndAndStart: noop, onKeepRunning: noop }} />
          </div>
        )}
      </main>
    </div>
  );
}
