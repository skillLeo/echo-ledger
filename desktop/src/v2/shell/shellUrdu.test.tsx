// Desktop Urdu, D-253: what D-248 left English, in the account's language. The palette's own commands and their
// notices, found by their Urdu and their English; the pages MainApp gives it; the hidden line of a locked command; the
// shell's Record (the rail's split button and the sidebar's) and More ways to record; the rail (its places, More, Setup,
// Help, the account, their tooltips and spoken names) and the panel's head. Keys stay Latin and left to right; brand
// and plan names stay Latin inside Urdu. English is the shell's and the palette's own tests', unchanged; a few checks
// here say so too.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { useState } from "react";
import MainApp from "../../views/main/MainApp";
import { CommandPalette, goToLine, lockedWords, paletteCommands } from "../../views/main/CommandPalette";
import { NavShell } from "./NavShell";
import { Shell } from "./Shell";
import { accountTip, placeList, placeName, PLACE_NAMES, type Place } from "./NavRail";
import { flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeEntitlements, makeSettings, makeStatus, makeRecording, sampleEntitlements } from "../../test/fixtures";
import { resetEntitlementsForTests } from "../../lib/entitlements";
import { FSI, PDI, resetLanguageForTests, setLanguage, stripIsolates, t } from "../../i18n";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const iso = (value: string | number) => `${FSI}${value}${PDI}`;
/** Latin letters but the names kept as they are (brands, plans, EchoLedger): what an Urdu line must not have. */
const KEPT = ["WhatsApp Business", "WhatsApp", "Meet", "Zoom", "Teams", "EchoLedger", "Ultra", "Pro", "Free"];
const latinIn = (words: string) => /[A-Za-z]/.test(KEPT.reduce((rest, name) => rest.split(name).join(""), stripIsolates(words)));
const urdu = () => act(() => setLanguage("ur", { root: false }));
const setWidth = (width: number) => {
  Object.defineProperty(window, "innerWidth", { value: width, configurable: true, writable: true });
  window.dispatchEvent(new Event("resize"));
};

beforeEach(() => {
  resetTauriMock();
  resetLanguageForTests();
  window.localStorage.clear();
});
afterEach(() => {
  cleanup();
  resetLanguageForTests();
  resetEntitlementsForTests();
});

describe("the palette's own commands in Urdu", () => {
  const extra = { upload: vi.fn(), newProject: vi.fn(), requestLink: vi.fn(), invite: vi.fn(), help: vi.fn(), upgrade: { open: false, run: vi.fn() } };
  const folders = [
    { folder: "inbox" as const, label: "Inbox" }, { folder: "whatsapp" as const, label: "WhatsApp" }, { folder: "phone" as const, label: "SIM" },
    { folder: "shared" as const, label: "Shared with me" },
  ];

  it("every label in Urdu (brand names Latin), its English kept as its name; English as before", async () => {
    const english = paletteCommands(makeStatus(), { go: vi.fn(), run: vi.fn(), folders, openFolder: vi.fn(), extra, v2: true });
    expect(english.every((c) => c.name === c.label)).toBe(true);
    await urdu();
    const list = paletteCommands(makeStatus(), { go: vi.fn(), run: vi.fn(), folders, openFolder: vi.fn(), extra, v2: true });
    expect(list.map((c) => c.name)).toEqual(english.map((c) => c.label));
    const said = Object.fromEntries(list.map((c) => [c.id, stripIsolates(c.label)]));
    expect(said).toMatchObject({
      start: "ریکارڈ", captions: "کیپشنز آن کریں", voice: "ابھی چلتا ہوا وائس نوٹ محفوظ کریں", mic_note: "وائس نوٹ ریکارڈ کریں",
      "live-translate": "لائیو ترجمہ", copy: "کیپشنز کے آخری 2 منٹ کاپی کریں", upload: "فائلیں اپ لوڈ کریں", invite: "لوگوں کو ورک اسپیس میں دعوت دیں",
      sync: "لائبریری سنک کریں", library: "لائبریری پر جائیں", "folder-inbox": "بغیر پروجیکٹ کی ریکارڈنگز دکھائیں",
      "folder-whatsapp": "WhatsApp کی ریکارڈنگز دکھائیں", "folder-phone": "سم کالز دکھائیں", "folder-shared": "میرے ساتھ شیئر شدہ پر جائیں",
      projects: "پروجیکٹس پر جائیں", record: "ریکارڈ کے صفحے پر جائیں", ready: "دیکھیں کہ کالز ریکارڈ ہوں گی", activity: "سرگرمی پر جائیں",
      shared: "شیئر شدہ پر جائیں", settings: "ترتیبات پر جائیں", upgrade: "پلان اپ گریڈ کریں", help: "مدد اور شارٹ کٹس",
    });
    expect(list.filter((c) => latinIn(c.label)).map((c) => c.label)).toEqual([]);
    // While recording, and the classic design's words.
    const recording = paletteCommands(makeStatus({ recording: makeRecording(), captionsLive: true }), { go: vi.fn(), run: vi.fn() });
    expect(recording.filter((c) => ["stop", "pause", "mark", "captions"].includes(c.id)).map((c) => c.label))
      .toEqual(["ریکارڈنگ بند کریں", "ریکارڈنگ روکیں", "یہ لمحہ بُک مارک کریں", "کیپشنز بند کریں"]);
    const classic = paletteCommands(makeStatus(), { go: vi.fn(), run: vi.fn(), folders, openFolder: vi.fn() });
    expect(classic.filter((c) => ["start", "record", "folder-inbox"].includes(c.id)).map((c) => stripIsolates(c.label)))
      .toEqual(["ابھی ریکارڈ کریں", "ان باکس پر جائیں", "ہوم پر جائیں"]);
  });

  it("their notices in Urdu: Bookmarked, Library synced", async () => {
    await urdu();
    const run = vi.fn();
    const list = paletteCommands(makeStatus({ recording: makeRecording() }), { go: vi.fn(), run });
    list.find((c) => c.id === "mark")!.run();
    list.find((c) => c.id === "sync")!.run();
    expect(run.mock.calls.map((c) => c[1])).toEqual(["بُک مارک شدہ", "لائبریری سنک ہو گئی"]);
  });

  it("found by their Urdu and by their English; Enter runs it", async () => {
    respond("search_library", { data: [] });
    await urdu();
    const onGo = vi.fn();
    render(<CommandPalette status={makeStatus()} onClose={vi.fn()} onGo={onGo} run={vi.fn()} onOpenRecording={vi.fn()} extra={extra} />);
    const input = screen.getByRole("combobox");
    const shown = () => screen.getAllByRole("option").map((o) => stripIsolates(o.textContent ?? ""));
    fireEvent.change(input, { target: { value: "سرگرمی" } });
    expect(shown()[0]).toBe("سرگرمی پر جائیں");
    fireEvent.change(input, { target: { value: "go to activity" } });
    expect(shown()[0]).toBe("سرگرمی پر جائیں");
    fireEvent.change(input, { target: { value: "sync" } });
    expect(shown()).toContain("لائبریری سنک کریں");
    fireEvent.change(input, { target: { value: "سرگرمی" } });
    fireEvent.keyDown(input, { key: "Enter" });
    expect(onGo).toHaveBeenCalledWith("activity");
  });

  it("a locked command's hidden line: the feature in its plan, the plan's name Latin", async () => {
    expect([lockedWords("Team workspace", "Ultra"), lockedWords("Team workspace", null)]).toEqual([", Team workspace is in Ultra", ", Team workspace is in a paid plan"]);
    await urdu();
    expect(lockedWords("Team workspace", "Ultra")).toBe(`، ${iso("Team workspace")}، ${iso("Ultra")} میں ہے`);
    expect(stripIsolates(lockedWords("Team workspace", null))).toBe("، Team workspace، کسی پیڈ پلان میں ہے");
    resetEntitlementsForTests(sampleEntitlements("free"));
    render(<CommandPalette status={makeStatus()} onClose={vi.fn()} onGo={vi.fn()} run={vi.fn()} onOpenRecording={vi.fn()} extra={{ invite: vi.fn() }} />);
    fireEvent.change(screen.getByRole("combobox"), { target: { value: "invite" } });
    const row = screen.getByRole("option", { name: /لوگوں کو ورک اسپیس میں دعوت دیں/ });
    expect(stripIsolates(row.querySelector(".sr-only")?.textContent ?? "")).toBe("، Team workspace، Ultra میں ہے");
  });
});

describe("the pages MainApp gives the palette, in Urdu", () => {
  it("each place's line in Urdu with its group; found by Urdu and English; a project's name as it is", async () => {
    respond("app_status", makeStatus({ platform: "windows" }));
    respond("get_settings", makeSettings());
    respond("library_list", []);
    respond("library_refresh", []);
    respond("list_clients", { data: [{ id: "p1", name: "Acme Holdings" }] });
    respond("mic_users", []);
    respond("bin_list", { data: [] });
    respond("search_library", { data: [] });
    respond("account_get", { name: "Ayesha Khan", email: "ayesha@example.test", email_verified: true, two_factor: false, google: false, created_at: null, workspace: null, deletes_at: null, language: "ur" });
    respond("projects_list", { data: [{ id: "p1", name: "Acme Holdings", session_count: 0, ai_notes: true, parent_id: null, path: [], children_count: 0, nested_session_count: 0 }] });
    respond("ui_flags", { ui_flags: { "ui_v2.shell": true, "ui_v2.library": true, v21_urdu: true }, ui_classic: false });
    resetEntitlementsForTests(makeEntitlements());
    setWidth(1440);
    render(<MainApp />);
    for (let i = 0; i < 6; i++) await act(flushAsync);
    await screen.findByRole("navigation", { name: "Main" }, { timeout: 15_000 });
    for (let i = 0; i < 4; i++) await act(flushAsync);
    fireEvent.keyDown(document.body, { key: "k", ctrlKey: true });
    await act(flushAsync);
    const input = await screen.findByRole("combobox", {}, { timeout: 5000 });
    const shown = () => screen.queryAllByRole("option").map((o) => stripIsolates(o.textContent ?? ""));
    fireEvent.change(input, { target: { value: "میرے لنکس" } });
    expect(shown()).toContain("میرے لنکس پر جائیں · شیئر شدہ");
    fireEvent.change(input, { target: { value: "my links" } });
    expect(shown()).toContain("میرے لنکس پر جائیں · شیئر شدہ");
    fireEvent.change(input, { target: { value: "help" } });
    expect(shown()).toContain("مدد اور شارٹ کٹس");
    fireEvent.change(input, { target: { value: "acme" } });
    expect(within(screen.getByRole("group", { name: "پروجیکٹس" })).getAllByRole("option").map((o) => o.textContent)).toEqual(["Acme Holdings"]);
    expect(goToLine({ label: "Acme Holdings", name: "Acme Holdings", group: t("Projects") })).toBe(`${iso("Acme Holdings")} پر جائیں · پروجیکٹس`);
  }, 30_000);
});

describe("the shell in Urdu", () => {
  function App({ tone, short = false }: { tone?: "accent" | "danger"; short?: boolean }) {
    const [place, setPlace] = useState<Place | null>("library");
    return (
      <NavShell place={place} onPlace={(p) => setPlace(p)} onHome={() => setPlace(null)} status={{ kind: "ready", text: "Ready to record" }} libraryCount={4}
        activityCount={2} activityTone={tone} liveDot liveDotWords={t("shell_context.live_recording")} setupAttention onSetupHealth={() => undefined}
        onHelp={() => undefined} onRecord={() => undefined} onUpload={() => undefined} callRecording={{ on: true, toggle: vi.fn() }} onSearch={() => undefined} searchKeys="Ctrl+K"
        me={{ name: "Ayesha Khan", email: "ayesha@example.test", plan: null }} account={[{ label: t("Sign out"), onPick: () => undefined }]}
        panels={{ library: <p>views</p> }} layoutFor={short ? "short" : "tall"}>
        <p>The page</p>
      </NavShell>
    );
  }
  const rail = () => within(screen.getByRole("navigation", { name: "Main" }));

  it("the rail: Record, the places, Setup, Help, Settings and the account, their names and labels in Urdu", async () => {
    await urdu();
    setWidth(1440);
    render(<App />);
    const names = rail().getAllByRole("button").map((b) => stripIsolates(b.getAttribute("aria-label") ?? b.textContent ?? ""));
    expect(names).toEqual([
      "ریکارڈ", "ریکارڈ کرنے کے مزید طریقے",
      "لائبریری", "پروجیکٹس", "لائیو، ریکارڈنگ ہو رہی ہے", "پوچھیں", "سرگرمی، 2",
      "سیٹ اپ، سیٹ اپ باقی ہے", "مدد", "ترتیبات", "اکاؤنٹ، Ayesha Khan",
    ]);
    const labels = [...document.querySelectorAll(".el-rail__label")].map((l) => l.textContent);
    expect(labels).toEqual(["ریکارڈ", "لائبریری", "پروجیکٹس", "لائیو", "پوچھیں", "سرگرمی", "سیٹ اپ", "مدد", "ترتیبات", "اکاؤنٹ"]);
    // The shortcuts are still the keys' own.
    expect(rail().getByRole("button", { name: "لائبریری" })).toHaveAttribute("aria-keyshortcuts", "Meta+1 Control+1");
    // The panel's head: its name and its buttons.
    const panel = screen.getByTestId("nav-panel");
    expect(stripIsolates(panel.getAttribute("aria-label") ?? "")).toBe("لائبریری کا پینل");
    expect(within(panel).getByRole("heading", { level: 2 })).toHaveTextContent("لائبریری");
    const hide = within(panel).getByRole("button", { name: "پینل چھپائیں" });
    expect(stripIsolates(hide.getAttribute("title") ?? "")).toBe("پینل چھپائیں (Ctrl+\\)");
    expect(hide.getAttribute("title")).toContain(iso("Ctrl+\\"));
    // Something failed: said, in Urdu.
    cleanup();
    render(<App tone="danger" />);
    expect(stripIsolates(rail().getByRole("button", { name: /^سرگرمی/ }).getAttribute("aria-label") ?? "")).toBe("سرگرمی، 2، کچھ ناکام ہوا");
  });

  it("Record's menu (More ways to record) in Urdu, with its keys Latin and left to right", async () => {
    await urdu();
    setWidth(1440);
    render(<App />);
    const group = rail().getByRole("group", { name: "ریکارڈ" });
    fireEvent.click(within(group).getByRole("button", { name: "ریکارڈ کرنے کے مزید طریقے" }));
    const menu = screen.getByRole("menu", { name: "ریکارڈ کرنے کے مزید طریقے" });
    const words = [...menu.querySelectorAll('[role^="menuitem"]')].map((m) => m.querySelector(".el-menu__label")?.textContent);
    expect(words).toEqual(["نئی ریکارڈنگ", "آڈیو فائلیں امپورٹ کریں…", "کالز اور میٹنگز خودکار ریکارڈ کریں", "ریکارڈ کا صفحہ"]);
    expect([...menu.querySelectorAll("kbd")].every((k) => k.getAttribute("dir") === "ltr" && /^[A-Za-z0-9+]+$/.test(k.textContent ?? ""))).toBe(true);
  });

  it("the sidebar shell's Record and its menu, in Urdu", async () => {
    await urdu();
    render(<Shell tab="library" onGo={() => undefined} status={{ kind: "ready", text: "Ready" }} onRecord={() => undefined} onUpload={() => undefined}
      onVoiceNote={() => undefined} onNewProject={() => undefined} onRequestLink={() => undefined}><p /></Shell>);
    const nav = screen.getByRole("navigation", { name: "Main" });
    const group = within(nav).getByRole("group", { name: "ریکارڈ" });
    expect(within(group).getByRole("button", { name: "ریکارڈ" })).toBeInTheDocument();
    fireEvent.click(within(group).getByRole("button", { name: "ریکارڈ کرنے کے مزید طریقے" }));
    const menu = screen.getByRole("menu", { name: "ریکارڈ کرنے کے مزید طریقے" });
    expect([...menu.querySelectorAll('[role^="menuitem"]')].map((m) => m.querySelector(".el-menu__label")?.textContent ?? m.textContent))
      .toEqual(["ابھی ریکارڈ کریں", "آڈیو فائلیں امپورٹ کریں…", "وائس نوٹ", "نیا پروجیکٹ…", "درخواست کا لنک…", "ریکارڈ کا صفحہ"]);
    expect(within(menu).getByText("بنائیں")).toBeInTheDocument();
  });

  it("the places as a phrase and the avatar's tooltip, in Urdu; English as before", async () => {
    expect([placeList(["library", "projects", "activity"]), accountTip(["live", "ask"], true), accountTip([])])
      .toEqual(["Library, Projects and Activity", "Live and Ask; account, theme and settings, something failed", "Account, theme and settings"]);
    expect(Object.values(PLACE_NAMES)).toEqual(["Library", "Projects", "Live", "Ask", "Activity", "Settings"]);
    await urdu();
    expect(stripIsolates(placeList(["library", "projects", "activity"]))).toBe("لائبریری، پروجیکٹس اور سرگرمی");
    expect(stripIsolates(accountTip(["live", "ask"], true))).toBe("لائیو اور پوچھیں؛ اکاؤنٹ، تھیم اور ترتیبات، کچھ ناکام ہوا");
    expect(accountTip([])).toBe("اکاؤنٹ، تھیم اور ترتیبات");
    expect((["library", "live", "ask", "settings"] as Place[]).map(placeName)).toEqual(["لائبریری", "لائیو", "پوچھیں", "ترتیبات"]);
    // The same words as Settings' and the Library's own.
    expect([placeName("library"), placeName("live"), placeName("ask")]).toEqual([t("Library"), t("Live"), t("Ask")]);
  });
});
