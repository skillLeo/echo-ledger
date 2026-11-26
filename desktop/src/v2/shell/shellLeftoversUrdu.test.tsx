// Desktop Urdu, D-255: the shell's last English, in the account's language. The Projects panel (Pinned, Projects and
// its sort, Archived, its search, loading and failing), the title row (History, Back and Forward with their keys, the
// search's words, "In:"), the status line, the sidebar shell without the Library's sections (its places, groups,
// buttons, tooltips and spoken names, the workspace menu, Live's and Ask's pages to come), the other places' panels, the
// zoom's chip, and the rail's navigation name ("Main", مرکزی), which code finds by data-nav="main" in every language.
// Keys stay Latin and left to right; a project's or a workspace's name is its own. English is the shell's own tests',
// unchanged; a few checks here say so too.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeEntitlements, makeSettings, makeStatus } from "../../test/fixtures";
import { resetEntitlementsForTests } from "../../lib/entitlements";
import MainApp from "../../views/main/MainApp";
import { ProjectsPanel, type PanelProject, type ProjectList } from "./NavRail";
import { NavShell } from "./NavShell";
import { Shell, searchHint } from "./Shell";
import { TitleRow, type TitleRowProps } from "./TitleRow";
import { shellChip, shellStatus } from "./status";
import { ActivityPanel, AskPanel, LivePanel } from "./PlacePanels";
import { zoomStepper } from "./Zoom";
import { resetProjectSortForTests } from "./projectSort";
import { setProjectsAccount } from "./kept";
import { FSI, PDI, resetLanguageForTests, setLanguage, stripIsolates } from "../../i18n";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const iso = (value: string | number) => `${FSI}${value}${PDI}`;
const urdu = () => act(() => setLanguage("ur", { root: false }));
/** Latin letters but the names kept as they are (projects', workspaces', the app's, keys): what an Urdu line must not have. */
const latinIn = (words: string, kept: readonly string[] = []) =>
  /[A-Za-z]/.test(kept.reduce((rest, name) => rest.split(name).join(""), stripIsolates(words)));

beforeEach(() => {
  resetTauriMock();
  resetLanguageForTests();
  localStorage.clear();
  setProjectsAccount("ledger.example.test|ayesha@example.test|w1");
  resetProjectSortForTests();
});
afterEach(() => {
  cleanup();
  resetLanguageForTests();
});

const PROJECTS: PanelProject[] = [
  { id: "p1", name: "Acme", pinned: true },
  { id: "p2", name: "Contracts", parent_id: "p1" },
  { id: "p3", name: "Zed" },
  { id: "p4", name: "Bilal Traders" },
  { id: "p5", name: "Old client", archived: true },
];
const tree = (list: readonly PanelProject[], _current: string | null, open: (id: string) => void, how: ProjectList) =>
  <ul aria-label={how.label}>{list.map((p) => <li key={p.id}><button type="button" className="el-nav-tree__name" onClick={() => open(p.id)}>{p.name}</button></li>)}</ul>;

describe("the Projects panel in Urdu", () => {
  it("Pinned, Projects with its sort and New project, Archived; the lists named in Urdu, the projects as they are", async () => {
    await urdu();
    render(<ProjectsPanel projects={PROJECTS} current={null} allCurrent={false} onAll={vi.fn()} onOpen={vi.fn()} onNew={vi.fn()} tree={tree} />);
    expect(screen.getByRole("heading", { level: 3, name: "پن شدہ" })).toBeInTheDocument();
    expect(within(screen.getByRole("list", { name: "پن شدہ" })).getAllByRole("button").map((b) => b.textContent)).toEqual(["Acme", "Contracts"]);
    expect(screen.getByRole("list", { name: "پروجیکٹس" })).toBeInTheDocument();
    const all = screen.getByRole("button", { name: "تمام پروجیکٹس" });
    expect(all).toHaveTextContent("پروجیکٹس");
    const sort = screen.getByRole("button", { name: "پروجیکٹس کی ترتیب" });
    expect(stripIsolates(sort.getAttribute("title") ?? "")).toBe("پروجیکٹس کی ترتیب: نام");
    fireEvent.click(sort);
    expect(within(screen.getByRole("menu", { name: "پروجیکٹس کی ترتیب" })).getAllByRole("menuitemradio").map((m) => m.textContent)).toEqual(["نام", "حالیہ"]);
    fireEvent.click(screen.getByRole("menuitemradio", { name: "حالیہ" }));
    expect(stripIsolates(screen.getByRole("button", { name: "پروجیکٹس کی ترتیب" }).getAttribute("title") ?? "")).toBe("پروجیکٹس کی ترتیب: حالیہ");
    expect(screen.getByRole("button", { name: "نیا پروجیکٹ" })).toBeInTheDocument();
    const archived = screen.getByRole("heading", { level: 3, name: /^آرکائیو شدہ/ });
    fireEvent.click(within(archived).getByRole("button"));
    expect(screen.getByRole("list", { name: "آرکائیو شدہ" })).toHaveTextContent("Old client");
  });

  it("its search (from 11 projects), nothing found, loading and failing, in Urdu", async () => {
    await urdu();
    const many = Array.from({ length: 12 }, (_, i) => ({ id: `p${i}`, name: `Client ${i}` }));
    render(<ProjectsPanel projects={many} current={null} allCurrent={false} onAll={vi.fn()} onOpen={vi.fn()} tree={tree} />);
    const field = screen.getByRole("searchbox", { name: "پروجیکٹس میں تلاش کریں" });
    fireEvent.change(field, { target: { value: "Client 1" } });
    expect(screen.getByRole("list", { name: "نتائج" })).toBeInTheDocument();
    fireEvent.change(field, { target: { value: "nothing like it" } });
    expect(screen.getByText("کوئی پروجیکٹ نہیں ملا")).toBeInTheDocument();
    cleanup();
    render(<ProjectsPanel projects={[]} current={null} allCurrent={false} onAll={vi.fn()} onOpen={vi.fn()} tree={tree} loading />);
    expect(screen.getByRole("status")).toHaveTextContent("پروجیکٹس لوڈ ہو رہے ہیں");
    cleanup();
    render(<ProjectsPanel projects={[]} current={null} allCurrent={false} onAll={vi.fn()} onOpen={vi.fn()} tree={tree} error="offline" />);
    expect(screen.getByRole("status")).toHaveTextContent("پروجیکٹس لوڈ نہیں ہو سکے۔");
  });

  it("in the shell: the real tree's folds and the drop hint in Urdu", async () => {
    await urdu();
    render(<Shell tab="projects" onGo={vi.fn()} status={{ kind: "ready", text: "x" }} library={<p>views</p>} onProject={vi.fn()}
      projectTree={[{ id: "p1", name: "Acme", pinned: false }, { id: "p2", name: "Contracts", parent_id: "p1" }]}
      nav={{ layoutFor: "ledger.example.test|ayesha@example.test|w1" }}><p>page</p></Shell>);
    fireEvent.click(screen.getByRole("button", { name: "پروجیکٹس" }));
    const fold = screen.getAllByRole("button", { name: new RegExp("Acme") }).find((b) => b.classList.contains("el-nav-tree__fold"))!;
    expect(stripIsolates(fold.getAttribute("aria-label") ?? "")).toBe("Acme کے فولڈرز سمیٹیں");
    fireEvent.click(fold);
    expect(stripIsolates(fold.getAttribute("aria-label") ?? "")).toBe("Acme کے فولڈرز دکھائیں");
  });
});

describe("the title row in Urdu", () => {
  const base: TitleRowProps = { place: "library", title: null, toggle: null, back: () => undefined, forward: null, onEverything: () => undefined };
  const row = () => screen.getByTestId("title-row");

  it("History, Back and Forward with their keys left to right; the search's words in Urdu", async () => {
    await urdu();
    render(<TitleRow {...base} />);
    const history = within(row()).getByRole("group", { name: "تاریخچہ" });
    const back = within(history).getByRole("button", { name: "پیچھے" });
    expect(back.getAttribute("title")).toMatch(new RegExp(`^پیچھے \\(${FSI}.+${PDI}\\)$`));
    expect(within(history).getByRole("button", { name: "آگے" })).toBeDisabled();
    // The Library's place words when the page gives none, with ⌘K / Ctrl+K in its title.
    const search = within(row()).getByRole("button", { name: "ریکارڈنگز میں تلاش کریں" });
    expect(stripIsolates(search.getAttribute("title") ?? "")).toMatch(/^ریکارڈنگز میں تلاش کریں \((⌘K|Ctrl\+K)\)$/);
    cleanup();
    render(<TitleRow {...base} place={null} />);
    expect(within(row()).getByRole("button", { name: "ہر جگہ تلاش کریں" })).toBeInTheDocument();
  });

  it("a page's search: \"In:\" its scope in Urdu (its name a value), and Search everything instead", async () => {
    await urdu();
    render(<TitleRow {...base} title={{ title: "Clients", count: 3, search: { placeholder: "ریکارڈنگز میں تلاش کریں", scope: "Clients", query: "", setQuery: () => undefined } }} />);
    fireEvent.focus(within(row()).getByRole("searchbox"));
    const scope = row().querySelector(".el-titlerow__scope span")!;
    expect(scope.textContent).toBe(`${iso("Clients")} میں`);
    expect(scope).toHaveAttribute("dir", "rtl");
    expect(within(row()).getByRole("button", { name: "اس کے بجائے ہر جگہ تلاش کریں" })).toBeInTheDocument();
    // Empty, the field reads right to left, so its placeholder starts right after the chip; typed, as what is typed.
    expect(within(row()).getByRole("searchbox")).toHaveAttribute("dir", "rtl");
  });

  it("the field typed in reads as what was typed (dir auto), in Urdu too", async () => {
    await urdu();
    render(<TitleRow {...base} title={{ title: "Clients", count: 3, search: { placeholder: "ریکارڈنگز میں تلاش کریں", scope: "Clients", query: "invoice", setQuery: () => undefined } }} />);
    expect(within(row()).getByRole("searchbox")).toHaveAttribute("dir", "auto");
  });

  it("English as before: In: Clients, Back (⌘[ or Alt+←), Search everything", () => {
    render(<TitleRow {...base} place={null} title={{ title: "Clients", count: 3, search: { placeholder: "Search recordings", scope: "Clients", query: "", setQuery: () => undefined } }} />);
    fireEvent.focus(within(row()).getByRole("searchbox"));
    expect(row().querySelector(".el-titlerow__scope span")).toHaveTextContent(/^In: Clients$/);
    expect(row().querySelector(".el-titlerow__scope span")).toHaveAttribute("dir", "auto");
    expect(within(row()).getByRole("searchbox")).toHaveAttribute("dir", "auto");
    expect(within(row()).getByRole("button", { name: "Back" }).getAttribute("title")).toMatch(/^Back \((⌘\[|Alt\+←)\)$/);
  });
});

describe("the status line in Urdu", () => {
  it("ready, needs setup, recording with its time and app, paused; English as before", async () => {
    const recording = { recording: { mode: "call", sourceApp: "zoom" }, elapsedMs: 134_000 };
    expect([shellStatus({ recording: null, elapsedMs: 0 }).text, shellChip(recording, () => "Zoom"), shellChip({ ...recording, paused: true, recording: { mode: "mic_note", sourceApp: "x" } }, () => "x")])
      .toEqual(["Ready to record", { kind: "recording", text: "Recording · Zoom", time: "02:14" }, { kind: "recording", text: "Paused · voice note", time: "02:14" }]);
    await urdu();
    expect(shellStatus({ recording: null, elapsedMs: 0 })).toEqual({ kind: "ready", text: "ریکارڈ کے لیے تیار" });
    expect(shellStatus({ recording: null, elapsedMs: 0, callsReady: false })).toEqual({ kind: "setup", text: "سیٹ اپ باقی ہے" });
    expect(shellStatus({ recording: {}, elapsedMs: 134_000 }).text).toBe(`ریکارڈنگ ${iso("02:14")}`);
    expect(shellChip(recording, () => "Zoom")).toEqual({ kind: "recording", text: `ریکارڈنگ · ${iso("Zoom")}`, time: "02:14" });
    expect(stripIsolates(shellChip({ ...recording, paused: true, recording: { mode: "mic_note", sourceApp: "x" } }, () => "x").text)).toBe("رکی ہوئی · وائس نوٹ");
  });
});

describe("the sidebar shell (without the Library's sections) in Urdu", () => {
  const workspace = { name: "Qureshi Textiles", role: "مالک", others: [{ key: "w2", name: "Home office", role: null }], invite: { locked: null, onPick: vi.fn() }, onManage: vi.fn() };
  const render2 = () => render(
    <Shell tab="library" onGo={vi.fn()} status={{ kind: "ready", text: "ریکارڈ کے لیے تیار" }} onRecord={vi.fn()} onSearch={vi.fn()} searchKeys="Ctrl+K" onHelp={vi.fn()}
      onAdmin={vi.fn()} rail={false} onRail={vi.fn()} width={240} onWidth={vi.fn()} workspace={workspace}
      me={{ name: "Ayesha Khan", email: "ayesha@example.test", plan: "Pro" }} onProject={vi.fn()} pinnedProjects={[{ id: "p1", name: "Acme" }]}
      places={{ places: [{ key: "inbox", label: "کوئی پروجیکٹ نہیں", count: 3, drop: true }], current: null, open: vi.fn() }}
      shared={{ tabs: [["with-me", "With me"], ["links", "My links"]], current: null, open: vi.fn() }}><p>page</p></Shell>);

  it("its name (مرکزی, data-nav=\"main\"), places, groups, buttons and tooltips in Urdu; names of people and projects as they are", async () => {
    await urdu();
    render2();
    const nav = screen.getByRole("navigation", { name: "مرکزی" });
    expect(nav).toHaveAttribute("data-nav", "main");
    expect(nav.querySelector(".el-sidebar__brand")).toHaveTextContent("EchoLedger");
    const search = within(nav).getByRole("button", { name: "تلاش کریں" });
    expect(search).toHaveAttribute("aria-keyshortcuts", "Control+F Control+K");
    expect(stripIsolates(search.getAttribute("title") ?? "")).toBe("تلاش کریں · Ctrl+F");
    const words = within(nav).getAllByRole("button").map((b) => b.getAttribute("aria-label") ?? b.textContent ?? "");
    expect(words.filter((w) => latinIn(w, ["Qureshi Textiles", "Acme", "Ayesha Khan", "AK", "Pro", "EchoLedger"]))).toEqual([]);
    for (const name of ["لائبریری", "پروجیکٹس", "لائیو", "پوچھیں", "سرگرمی", "ترتیبات", "مدد اور شارٹ کٹس", "ایڈمن پینل", "سائیڈ بار چھوٹا کریں"]) {
      expect(within(nav).getAllByRole("button", { name }).length, name).toBeGreaterThan(0);
    }
    expect(within(nav).getByRole("list", { name: "لائبریری کے حصے" })).toBeInTheDocument();
    expect(stripIsolates(within(nav).getByRole("button", { name: /^کوئی پروجیکٹ نہیں/ }).getAttribute("aria-label") ?? "")).toBe("کوئی پروجیکٹ نہیں، 3");
    expect(within(within(nav).getByRole("list", { name: "شیئر شدہ" })).getAllByRole("button").map((b) => b.textContent)).toEqual(["میرے ساتھ شیئر شدہ", "میرے لنکس"]);
    expect(within(nav).getByRole("list", { name: "پن شدہ پروجیکٹس" })).toHaveTextContent("Acme");
    const collapse = within(nav).getByRole("button", { name: "سائیڈ بار چھوٹا کریں" });
    expect(stripIsolates(collapse.getAttribute("title") ?? "")).toBe("سائیڈ بار چھوٹا کریں ([)");
    expect(screen.getByText("ترتیبات کھولتا ہے")).toBeInTheDocument();
    expect(screen.getByRole("separator", { name: "سائیڈ بار کا سائز بدلیں" })).toBeInTheDocument();
  });

  it("the workspace menu, and Live's and Ask's pages to come, in Urdu", async () => {
    await urdu();
    render2();
    const nav = screen.getByRole("navigation", { name: "مرکزی" });
    const ws = within(nav).getByRole("button", { name: /Qureshi Textiles/ });
    expect(ws).toHaveAttribute("title", "ورک اسپیس");
    fireEvent.click(ws);
    const menu = screen.getByRole("menu");
    expect(stripIsolates(menu.getAttribute("aria-label") ?? "")).toBe("ورک اسپیس: Qureshi Textiles، مالک");
    expect(within(menu).getByText("آپ کی دوسری ورک اسپیسز")).toBeInTheDocument();
    expect(within(menu).getByRole("menuitem", { name: "لوگوں کو دعوت دیں" })).toBeInTheDocument();
    expect(within(menu).getByRole("menuitem", { name: "براؤزر میں بدلیں یا سنبھالیں" })).toBeInTheDocument();
    fireEvent.keyDown(menu, { key: "Escape" });
    fireEvent.click(within(nav).getByRole("button", { name: "لائیو" }));
    expect(screen.getByRole("main", { name: "لائیو" })).toHaveTextContent("“لائیو” کا صفحہ جلد آ رہا ہے");
    expect(screen.getByRole("button", { name: "ریکارڈ کا صفحہ کھولیں" })).toBeInTheDocument();
    fireEvent.click(within(nav).getByRole("button", { name: "پوچھیں" }));
    expect(screen.getByRole("main", { name: "پوچھیں" })).toHaveTextContent("“پوچھیں” کا صفحہ جلد آ رہا ہے");
    expect(screen.getByRole("button", { name: "پروجیکٹس کھولیں" })).toBeInTheDocument();
  });

  it("English as before: Main, Search · Ctrl+F, Collapse the sidebar ([), Library places", () => {
    expect(searchHint("⌘K")).toEqual({ title: "Search · ⌘F", keys: "Meta+F Meta+K" });
    render(<Shell tab="library" onGo={vi.fn()} status={{ kind: "ready", text: "Ready to record" }} onSearch={vi.fn()} searchKeys="Ctrl+K" rail={false} onRail={vi.fn()}
      places={{ places: [{ key: "inbox", label: "No project", count: 3, drop: true }], current: null, open: vi.fn() }}><p>page</p></Shell>);
    const nav = screen.getByRole("navigation", { name: "Main" });
    expect(nav).toHaveAttribute("data-nav", "main");
    expect(within(nav).getByRole("button", { name: "Search" })).toHaveAttribute("title", "Search · Ctrl+F");
    expect(within(nav).getByRole("button", { name: "Collapse sidebar" })).toHaveAttribute("title", "Collapse the sidebar ([)");
    expect(within(nav).getByRole("button", { name: "No project, 3" })).toBeInTheDocument();
  });
});

describe("the rail's name, the other places' panels and the zoom, in Urdu", () => {
  it("the rail is مرکزی in Urdu and Main in English, data-nav=\"main\" in both", async () => {
    const shell = () => <NavShell place="library" onPlace={vi.fn()} onHome={vi.fn()} status={{ kind: "ready", text: "Ready to record" }} onSetupHealth={vi.fn()} panels={{}}><p>page</p></NavShell>;
    render(shell());
    expect(screen.getByRole("navigation", { name: "Main" })).toHaveAttribute("data-nav", "main");
    cleanup();
    await urdu();
    render(shell());
    expect(screen.getByRole("navigation", { name: "مرکزی" })).toHaveAttribute("data-nav", "main");
  });

  it("Live's, Ask's and Activity's panels in Urdu; a project's name as it is", async () => {
    await urdu();
    render(<>
      <LivePanel callRecording onCalls={vi.fn()} onPart={vi.fn()} />
      <AskPanel projects={Array.from({ length: 11 }, (_, i) => ({ id: `p${i}`, name: `Client ${i}` }))} current={null} onAsk={vi.fn()} />
      <ActivityPanel show="all" needs={2} onShow={vi.fn()} />
    </>);
    expect(screen.getByRole("button", { name: /کال ریکارڈنگ/ })).toHaveTextContent("آن");
    expect(screen.getByRole("region", { name: "کال کے دوران" })).toHaveTextContent("لائیو اسسٹ");
    expect(screen.getByRole("button", { name: "نیا سوال" })).toBeInTheDocument();
    expect(screen.getByRole("region", { name: "کسی پروجیکٹ کے بارے میں" })).toHaveTextContent("Client 0");
    expect(screen.getByRole("searchbox", { name: "پروجیکٹس میں تلاش کریں" })).toBeInTheDocument();
    expect(within(screen.getByRole("region", { name: "سرگرمی" })).getAllByRole("button").map((b) => b.textContent)).toEqual(["سب", "آپ کی توجہ چاہیے 2", "ہو گیا"]);
  });

  it("the zoom's steps in Urdu", async () => {
    expect([zoomStepper(110).down.label, zoomStepper(110).up.label]).toEqual(["Zoom out", "Zoom in"]);
    await urdu();
    expect([zoomStepper(110).down.label, zoomStepper(110).up.label, zoomStepper(110).value]).toEqual(["چھوٹا کریں", "بڑا کریں", "110%"]);
  });
});

describe("what MainApp gives the shell, in Urdu", () => {
  it("the status line, the uploads' line (its count through tc) and the avatar's menu", async () => {
    respond("app_status", makeStatus({ platform: "windows", pendingUploads: 2, callsReady: true }));
    respond("get_settings", makeSettings());
    respond("library_list", []);
    respond("library_refresh", []);
    respond("list_clients", { data: [] });
    respond("mic_users", []);
    respond("bin_list", { data: [] });
    respond("account_get", { name: "Ayesha Khan", email: "ayesha@example.test", email_verified: true, two_factor: false, google: false, created_at: null, workspace: null, deletes_at: null, language: "ur" });
    respond("projects_list", { data: [] });
    respond("ui_flags", { ui_flags: { "ui_v2.shell": true, "ui_v2.library": true, v21_urdu: true }, ui_classic: false });
    resetEntitlementsForTests(makeEntitlements());
    Object.defineProperty(window, "innerWidth", { value: 1440, configurable: true, writable: true });
    render(<MainApp />);
    for (let i = 0; i < 6; i++) await act(flushAsync);
    await waitFor(() => expect(document.querySelector('nav[data-nav="main"]')).not.toBeNull(), { timeout: 15_000 });
    for (let i = 0; i < 4; i++) await act(flushAsync);
    const rail = screen.getByRole("navigation", { name: "مرکزی" });
    expect(document.querySelector(".el-shell__status")).toHaveTextContent("ریکارڈ کے لیے تیار");
    expect(stripIsolates(screen.getByTestId("sidebar-uploads").textContent ?? "")).toBe("2 ریکارڈنگز اپ لوڈ ہو رہی ہیں…");
    fireEvent.click(within(rail).getByRole("button", { name: /^اکاؤنٹ، / }));
    const menu = screen.getByRole("menu");
    const items = [...menu.querySelectorAll('[role^="menuitem"]')].map((m) => m.querySelector(".el-menu__label")?.textContent);
    expect(items).toEqual(expect.arrayContaining(["اکاؤنٹ", "پلان اور استعمال", "تھیم", "سائن آؤٹ"]));
  }, 30_000);
});
