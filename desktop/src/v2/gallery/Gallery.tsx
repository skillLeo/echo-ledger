import { Icons, NavItem, Sidebar, Status } from "../components";
import { COPY } from "./content";
import type { GalleryParams } from "./guard";
import { LibraryScreen } from "./screens/Library";
import { RecordingScreen } from "./screens/Recording";
import { SettingsScreen } from "./screens/Settings";
import "./gallery.css";

/** The Gate 3 sample screens in the app's frame: the sidebar and one page, in the chosen language and direction. */
export function Gallery({ screen, lang, state = "ready" }: Pick<GalleryParams, "screen" | "lang"> & { state?: GalleryParams["state"] }) {
  const nav = COPY[lang].nav;
  const page = screen === "settings" ? "settings" : "library";
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
      </main>
    </div>
  );
}
