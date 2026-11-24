import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { Suspense, lazy, useState } from "react";
import MainApp from "../../views/main/MainApp";
import type { MainTab } from "../../lib/mainView";
import { callsOf, emitEvent, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeEntitlements, makeItem, makeNavigate, makePendingItem, makeSettings, makeStatus, makeTranscript } from "../../test/fixtures";
import { LibraryV2 } from "../library/LibraryV2";
import { resetEntitlementsForTests } from "../../lib/entitlements";
import { Shell, moduleOf } from "./Shell";
import { shellStatus } from "./status";
import { AccountCard, ClassicSwitch, StorageCard, initials, minutesLine } from "../settings/AccountCard";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

beforeEach(() => {
  // A wide window: the place's panel shows beside the page (under 1100 px it is a flyout from the rail).
  Object.defineProperty(window, "innerWidth", { value: 1440, configurable: true, writable: true });
  resetTauriMock();
  resetEntitlementsForTests(null);
  window.localStorage.clear();
});
afterEach(async () => {
  await flushAsync();
});

describe("the shell", () => {
  function Host({ start = "library" as MainTab }) {
    const [tab, setTab] = useState<MainTab>(start);
    return <Shell tab={tab} onGo={setTab} status={{ kind: "ready", text: "Ready to record" }}><p>page {tab}</p></Shell>;
  }

  it("plan 2's sidebar: Record at the top; Library, Projects, Live, Ask; Activity with its count, Settings, the account and the status at the foot", () => {
    render(<Host />);
    const nav = screen.getByRole("navigation", { name: "Main" });
    expect(within(nav).getAllByRole("button").map((b) => b.textContent)).toEqual(["Record", "", "Library", "Projects", "Live", "Ask", "Activity", "Settings"]);
    expect(within(nav).getByRole("button", { name: "Library" })).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("status")).toHaveTextContent("Ready to record");
  });

  it("two quick presses (or a held key) on Record start exactly one recording; it works again once the status says so", async () => {
    let answer: () => void = () => undefined;
    const onRecord = vi.fn(() => new Promise<void>((done) => { answer = done; }));
    const { rerender } = render(<Shell tab="library" onGo={() => undefined} status={{ kind: "ready", text: "Ready" }} onRecord={onRecord}><p /></Shell>);
    const record = () => within(screen.getByRole("navigation", { name: "Main" })).getByRole("button", { name: /^(Record|Stop recording)$/ });
    fireEvent.click(record());
    fireEvent.click(record());
    fireEvent.keyDown(record(), { key: "Enter", repeat: true });
    fireEvent.click(record());
    expect(onRecord).toHaveBeenCalledTimes(1);
    await act(async () => answer());
    // Still blocked until the status changes to recording; then one press stops it.
    fireEvent.click(record());
    expect(onRecord).toHaveBeenCalledTimes(1);
    rerender(<Shell tab="library" onGo={() => undefined} status={{ kind: "recording", text: "Recording 00:01" }} recording onRecord={onRecord}><p /></Shell>);
    expect(record()).toHaveTextContent("Stop recording");
    fireEvent.click(record());
    fireEvent.click(record());
    expect(onRecord).toHaveBeenCalledTimes(2);
  });

  it("without onRecord (a viewer) Record never says Stop recording; Needs setup opens Setup & health; the Record group marks the Record page", () => {
    const onSetup = vi.fn();
    render(<Shell tab="record" onGo={() => undefined} status={{ kind: "setup", text: "Needs setup" }} recording onSetup={onSetup}><p /></Shell>);
    const nav = screen.getByRole("navigation", { name: "Main" });
    expect(within(nav).getByRole("button", { name: "Record" })).toBeInTheDocument();
    expect(within(nav).queryByRole("button", { name: "Stop recording" })).toBeNull();
    expect(within(nav).getByRole("group", { name: "Record" })).toHaveAttribute("aria-current", "page");
    fireEvent.click(within(nav).getByRole("button", { name: /Needs setup/ }));
    expect(onSetup).toHaveBeenCalled();
    fireEvent.click(within(nav).getByRole("button", { name: "More ways to record" }));
    expect(screen.getByRole("menuitem", { name: "Record page" })).not.toHaveAttribute("aria-checked");
  });

  it("Search (with its keys) as an icon at the end of the header, Record now and Import audio files in Record's menu, and Help & shortcuts at the foot", () => {
    const onSearch = vi.fn();
    const onUpload = vi.fn();
    const onHelp = vi.fn();
    render(<Shell tab="library" onGo={() => undefined} status={{ kind: "ready", text: "Ready" }} onRecord={() => undefined} onSearch={onSearch} searchKeys="⌘K" onUpload={onUpload} onHelp={onHelp}><p /></Shell>);
    const nav = screen.getByRole("navigation", { name: "Main" });
    expect(within(nav).getAllByRole("button").map((b) => b.textContent)).toEqual(["", "Record", "", "Library", "Projects", "Live", "Ask", "Activity", "Settings", "Help & shortcuts"]);
    // The full-width Search row is gone: the icon sits right after the name.
    expect(document.querySelector(".el-shell__search")).toBeNull();
    const brand = nav.querySelector(".el-sidebar__brand")!;
    expect(brand).toHaveTextContent("EchoLedger");
    const search = within(nav).getByRole("button", { name: "Search" });
    expect(brand.lastElementChild?.firstElementChild).toBe(search);
    expect(search).toHaveAttribute("title", "Search · ⌘F");
    expect(search).toHaveAttribute("aria-keyshortcuts", "Meta+F Meta+K");
    fireEvent.click(search);
    expect(onSearch).toHaveBeenCalled();
    fireEvent.click(within(nav).getByRole("button", { name: "More ways to record" }));
    // ● Record ▾ (PLAN_LIBRARY_FILTERS): Record now and Import audio files first (files can be dropped on the window too).
    expect(screen.getAllByRole("menuitem").slice(0, 2).map((m) => m.textContent)).toEqual(["Record now", "Import audio files…"]);
    fireEvent.click(screen.getByRole("menuitem", { name: "Import audio files…" }));
    expect(onUpload).toHaveBeenCalled();
    fireEvent.click(within(nav).getByRole("button", { name: "Help & shortcuts" }));
    expect(onHelp).toHaveBeenCalled();
  });

  it("Search's tooltip says Ctrl+F on Windows; Cmd+F and Ctrl+F open search, never while typing in a field", () => {
    const onSearch = vi.fn();
    render(<Shell tab="library" onGo={() => undefined} status={{ kind: "ready", text: "Ready" }} onSearch={onSearch} searchKeys="Ctrl+K"><input aria-label="Name" /></Shell>);
    const search = within(screen.getByRole("navigation", { name: "Main" })).getByRole("button", { name: "Search" });
    expect(search).toHaveAttribute("title", "Search · Ctrl+F");
    expect(search).toHaveAttribute("aria-keyshortcuts", "Control+F Control+K");
    fireEvent.keyDown(document.body, { key: "f", ctrlKey: true });
    fireEvent.keyDown(document.body, { key: "F", metaKey: true });
    expect(onSearch).toHaveBeenCalledTimes(2);
    fireEvent.keyDown(screen.getByRole("textbox", { name: "Name" }), { key: "f", ctrlKey: true });
    fireEvent.keyDown(document.body, { key: "f", ctrlKey: true, shiftKey: true });
    fireEvent.keyDown(document.body, { key: "f" });
    expect(onSearch).toHaveBeenCalledTimes(2);
  });

  it("Cmd/Ctrl+F does not open search behind an open dialog or sheet", () => {
    const onSearch = vi.fn();
    const { rerender } = render(<Shell tab="library" onGo={() => undefined} status={{ kind: "ready", text: "Ready" }} onSearch={onSearch} searchKeys="⌘K"><div aria-modal="true" /></Shell>);
    fireEvent.keyDown(document.body, { key: "f", metaKey: true });
    expect(onSearch).not.toHaveBeenCalled();
    rerender(<Shell tab="library" onGo={() => undefined} status={{ kind: "ready", text: "Ready" }} onSearch={onSearch} searchKeys="⌘K"><p /></Shell>);
    fireEvent.keyDown(document.body, { key: "f", metaKey: true });
    expect(onSearch).toHaveBeenCalledTimes(1);
  });

  it("in the collapsed rail the Search icon stays: it is the brand's end, which the rail keeps at its top", () => {
    const onSearch = vi.fn();
    render(<Shell tab="library" onGo={() => undefined} status={{ kind: "ready", text: "Ready" }} onSearch={onSearch} searchKeys="⌘K"><p /></Shell>);
    const nav = screen.getByRole("navigation", { name: "Main" });
    const end = nav.querySelector(".el-sidebar__brand > .el-sidebar__brand-end")!;
    expect(nav.querySelector(".el-sidebar__brand-name")).toHaveTextContent("EchoLedger");
    fireEvent.click(within(end as HTMLElement).getByRole("button", { name: "Search" }));
    expect(onSearch).toHaveBeenCalled();
  });

  it("on an open recording Cmd+F stays Find in this transcript; the icon still opens app search", async () => {
    const item = makeItem("s1", { title: "Kick-off", transcript: { final_status: "completed", final_transcript_id: "tr-1", live_available: false, preview: "Hello" } });
    respond("library_list", [item]);
    respond("library_refresh", [item]);
    respond("session_detail", { playback_url: "https://ledger.example.com/a.m4a", speaker_names: {} });
    respond("transcript_detail", makeTranscript({ id: "tr-1" }));
    respond("skipped_list", []);
    Element.prototype.scrollIntoView = vi.fn();
    const onSearch = vi.fn();
    render(<Shell tab="library" onGo={() => undefined} status={{ kind: "ready", text: "Ready" }} onSearch={onSearch} searchKeys="⌘K">
      <LibraryV2 openRequest={{ id: "s1", seq: 1 }} />
    </Shell>);
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.keyDown(document.body, { key: "f", metaKey: true });
    expect(screen.getByRole("searchbox", { name: "Find in this transcript" })).toHaveFocus();
    expect(onSearch).not.toHaveBeenCalled();
    fireEvent.click(within(screen.getByRole("navigation", { name: "Main" })).getByRole("button", { name: "Search" }));
    expect(onSearch).toHaveBeenCalled();
  });

  it("on a recording with no transcript (nothing to find) Cmd+F opens app search", async () => {
    const item = makeItem("s2", { title: "Voice note", transcript: { final_status: null, final_transcript_id: null, live_available: false, preview: null } });
    respond("library_list", [item]);
    respond("library_refresh", [item]);
    respond("session_detail", { playback_url: "https://ledger.example.com/b.m4a", speaker_names: {} });
    respond("skipped_list", []);
    Element.prototype.scrollIntoView = vi.fn();
    const onSearch = vi.fn();
    render(<Shell tab="library" onGo={() => undefined} status={{ kind: "ready", text: "Ready" }} onSearch={onSearch} searchKeys="⌘K">
      <LibraryV2 openRequest={{ id: "s2", seq: 1 }} />
    </Shell>);
    await act(flushAsync);
    await act(flushAsync);
    expect(screen.getByRole("heading", { name: "Voice note" })).toBeInTheDocument();
    fireEvent.keyDown(document.body, { key: "f", metaKey: true });
    expect(screen.queryByRole("searchbox", { name: "Find in this transcript" })).toBeNull();
    expect(onSearch).toHaveBeenCalledTimes(1);
  });

  it("the sub-lists: pinned projects under Projects and Shared's places under Library, each opening its page", () => {
    const onProject = vi.fn();
    const open = vi.fn();
    render(<Shell tab="projects" onGo={() => undefined} status={{ kind: "ready", text: "Ready" }} projectId="p2"
      pinnedProjects={[{ id: "p1", name: "Acme" }, { id: "p2", name: "قریشی ٹیکسٹائل" }]} onProject={onProject}
      shared={{ tabs: [["with-me", "With me"], ["links", "My links"], ["requests", "Request links"]], current: null, open }}><p /></Shell>);
    const pinned = screen.getByRole("list", { name: "Pinned projects" });
    expect(within(pinned).getAllByRole("button").map((b) => b.textContent)).toEqual(["Acme", "قریشی ٹیکسٹائل"]);
    expect(within(pinned).getByRole("button", { name: "قریشی ٹیکسٹائل" })).toHaveAttribute("aria-current", "page");
    fireEvent.click(within(pinned).getByRole("button", { name: "Acme" }));
    expect(onProject).toHaveBeenCalledWith("p1");
    const sharedList = screen.getByRole("list", { name: "Shared" });
    expect(within(sharedList).getAllByRole("button").map((b) => b.textContent)).toEqual(["Shared with me", "My links", "Request links"]);
    fireEvent.click(within(sharedList).getByRole("button", { name: "My links" }));
    expect(open).toHaveBeenCalledWith("links");
  });

  it("Record's menu also makes a new project and a request link (classic's + menu)", () => {
    const onNewProject = vi.fn();
    const onRequestLink = vi.fn();
    render(<Shell tab="library" onGo={() => undefined} status={{ kind: "ready", text: "Ready" }} onNewProject={onNewProject} onRequestLink={onRequestLink}><p /></Shell>);
    const nav = screen.getByRole("navigation", { name: "Main" });
    fireEvent.click(within(nav).getByRole("button", { name: "More ways to record" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "New project…" }));
    expect(onNewProject).toHaveBeenCalled();
    fireEvent.click(within(nav).getByRole("button", { name: "More ways to record" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Request link…" }));
    expect(onRequestLink).toHaveBeenCalled();
  });

    it("Activity shows how many need you; none shows no badge", () => {
    const { rerender } = render(<Shell tab="library" onGo={() => undefined} status={{ kind: "ready", text: "Ready" }} activity={3}><p /></Shell>);
    expect(within(screen.getByRole("navigation", { name: "Main" })).getByRole("button", { name: /^Activity/ })).toHaveTextContent("Activity3");
    rerender(<Shell tab="library" onGo={() => undefined} status={{ kind: "ready", text: "Ready" }} activity={0}><p /></Shell>);
    expect(within(screen.getByRole("navigation", { name: "Main" })).getByRole("button", { name: "Activity" })).toHaveTextContent(/^Activity$/);
  });

  it("Record records now (or stops); its menu has a voice note and the Record page; a viewer's Record opens the page", () => {
    const onRecord = vi.fn();
    const onVoiceNote = vi.fn();
    const onGo = vi.fn();
    const { rerender } = render(<Shell tab="library" onGo={onGo} status={{ kind: "ready", text: "Ready" }} onRecord={onRecord} onVoiceNote={onVoiceNote}><p /></Shell>);
    const nav = screen.getByRole("navigation", { name: "Main" });
    fireEvent.click(within(nav).getByRole("button", { name: "Record" }));
    expect(onRecord).toHaveBeenCalledTimes(1);
    fireEvent.click(within(nav).getByRole("button", { name: "More ways to record" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Voice note" }));
    expect(onVoiceNote).toHaveBeenCalled();
    fireEvent.click(within(nav).getByRole("button", { name: "More ways to record" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Record page" }));
    expect(onGo).toHaveBeenLastCalledWith("record");
    rerender(<Shell tab="library" onGo={onGo} status={{ kind: "recording", text: "Recording 00:04" }} recording onRecord={onRecord}><p /></Shell>);
    fireEvent.click(within(nav).getByRole("button", { name: "Stop recording" }));
    expect(onRecord).toHaveBeenCalledTimes(2);
    rerender(<Shell tab="library" onGo={onGo} status={{ kind: "ready", text: "Ready" }}><p /></Shell>);
    fireEvent.click(within(nav).getByRole("button", { name: "Record" }));
    expect(onGo).toHaveBeenLastCalledWith("record");
  });

  it("Library, Projects, Activity and Settings open today's pages; Live and Ask say where to go meanwhile", () => {
    render(<Host />);
    const nav = screen.getByRole("navigation", { name: "Main" });
    fireEvent.click(within(nav).getByRole("button", { name: "Settings" }));
    expect(screen.getByText("page settings")).toBeInTheDocument();
    fireEvent.click(within(nav).getByRole("button", { name: "Projects" }));
    expect(screen.getByText("page projects")).toBeInTheDocument();
    expect(within(nav).getByRole("button", { name: "Projects" })).toHaveAttribute("aria-current", "page");
    fireEvent.click(within(nav).getByRole("button", { name: "Activity" }));
    expect(screen.getByText("page activity")).toBeInTheDocument();
    fireEvent.click(within(nav).getByRole("button", { name: "Record" }));
    expect(screen.getByText("page record")).toBeInTheDocument();
    fireEvent.click(within(nav).getByRole("button", { name: "Live" }));
    expect(within(nav).getByRole("button", { name: "Live" })).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("heading", { name: "Live is on its way" })).toBeInTheDocument();
    fireEvent.click(within(nav).getByRole("button", { name: "Ask" }));
    fireEvent.click(screen.getByRole("button", { name: "Open Projects" }));
    expect(screen.getByText("page projects")).toBeInTheDocument();
    expect(within(nav).getByRole("button", { name: "Projects" })).toHaveAttribute("aria-current", "page");
  });

  it("a Live page that loads late stays open (it suspends once, then shows)", async () => {
    const LatePage = lazy(() => Promise.resolve({ default: () => <h1>Live page</h1> }));
    function LiveHost() {
      const [tab, setTab] = useState<MainTab>("library");
      return (
        <Suspense fallback={null}>
          <Shell tab={tab} onGo={setTab} status={{ kind: "ready", text: "Ready to record" }} live={<Suspense fallback={null}><LatePage /></Suspense>}><p>page {tab}</p></Shell>
        </Suspense>
      );
    }
    render(<LiveHost />);
    fireEvent.click(within(screen.getByRole("navigation", { name: "Main" })).getByRole("button", { name: "Live" }));
    expect(await screen.findByRole("heading", { name: "Live page" })).toBeInTheDocument();
    await act(flushAsync);
    expect(screen.getByRole("heading", { name: "Live page" })).toBeInTheDocument();
    expect(within(screen.getByRole("navigation", { name: "Main" })).getByRole("button", { name: "Live" })).toHaveAttribute("aria-current", "page");
  });

  it("maps today's pages to modules and words the status", () => {
    expect(moduleOf("record")).toBe("record");
    expect(moduleOf("activity")).toBe("activity");
    expect(moduleOf("projects")).toBe("projects");
    expect(moduleOf("shared")).toBe("library");
    expect(shellStatus({ recording: {}, elapsedMs: 134_000 })).toEqual({ kind: "recording", text: "Recording 02:14" });
    expect(shellStatus({ recording: null, elapsedMs: 0, callsReady: false })).toEqual({ kind: "setup", text: "Needs setup" });
    expect(shellStatus({ recording: null, elapsedMs: 0, callsReady: true })).toEqual({ kind: "ready", text: "Ready to record" });
  });
});

describe("the After a call notice in the new shell", () => {
  it("says it once, with Transcribe every call; closing keeps Ask me", async () => {
    const { AfterCallNoticeV2 } = await import("./AfterCallNotice");
    respond("after_call_notice_answer", makeStatus());
    const onStatus = vi.fn();
    const { rerender } = render(<AfterCallNoticeV2 text="After a call, EchoLedger now asks what to do." onStatus={onStatus} />);
    expect(screen.getByTestId("after-call-notice-v2")).toHaveTextContent("After a call, EchoLedger now asks what to do.");
    fireEvent.click(screen.getByRole("button", { name: "Transcribe every call" }));
    await act(flushAsync);
    expect(callsOf("after_call_notice_answer")).toEqual([{ alwaysTranscribe: true }]);
    expect(onStatus).toHaveBeenCalled();
    expect(screen.getByText("Every call is now transcribed.")).toBeInTheDocument();
    rerender(<AfterCallNoticeV2 key="again" text="After a call, EchoLedger now asks what to do." onStatus={onStatus} />);
    fireEvent.click(screen.getByRole("button", { name: "Close" }));
    await act(flushAsync);
    expect(callsOf("after_call_notice_answer")).toEqual([{ alwaysTranscribe: true }, { alwaysTranscribe: false }]);
  });

  it("shows in the shell from the core's status, and not without it", async () => {
    respond("app_status", { ...makeStatus(), afterCallNotice: "After a call, EchoLedger now asks what to do." });
    respond("library_list", [makeItem("a")]);
    respond("library_refresh", [makeItem("a")]);
    respond("get_settings", makeSettings());
    respond("list_clients", { data: [] });
    respond("mic_users", []);
    respond("session_detail", {});
    respond("ui_flags", { ui_flags: { "ui_v2.shell": true }, ui_classic: false });
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    expect(await screen.findByTestId("after-call-notice-v2")).toBeInTheDocument();
  });
});

describe("Settings' account card", () => {
  it("shows who, the plan with Upgrade, the month's minutes and the storage numbers", async () => {
    respond("account_get", { name: "Ayesha Siddiqui", email: "ayesha@qureshitextiles.pk", email_verified: true, two_factor: false, google: false, created_at: null, workspace: null, deletes_at: null });
    respond("storage_info", { recordings_bytes: 3_435_973_837, recordings_count: 212, disk_free_bytes: 118_111_600_640, disk_total_bytes: null, retention_days: 0 });
    resetEntitlementsForTests(makeEntitlements({ limits: { monthly_minutes: { limit: 300, used: 212 }, storage_bytes: { limit: 10_737_418_240, used: 3_435_973_837 } } }));
    render(<AccountCard />);
    expect(screen.getByRole("status", { busy: true })).toHaveTextContent("Loading your account");
    await act(flushAsync);
    const card = screen.getByRole("region", { name: "Ayesha Siddiqui" });
    expect(within(card).getByText("AS")).toBeInTheDocument();
    expect(within(card).getByText("Free")).toBeInTheDocument();
    expect(within(card).getByText("212 of 300 minutes used this month")).toBeInTheDocument();
    expect(within(card).getByRole("button", { name: "Upgrade" })).toBeInTheDocument();
  });

  it("the storage numbers: the account's from the plan, this computer's free space, how long recordings stay", async () => {
    respond("storage_info", { recordings_bytes: 3_435_973_837, recordings_count: 212, disk_free_bytes: 118_111_600_640, disk_total_bytes: null, retention_days: 0 });
    resetEntitlementsForTests(makeEntitlements({ limits: { storage_bytes: { limit: 10_737_418_240, used: 3_435_973_837 } } }));
    render(<StorageCard />);
    await act(flushAsync);
    const storage = screen.getByRole("list", { name: "Storage" });
    expect(within(storage).getByText("Your recordings")).toBeInTheDocument();
    expect(within(storage).getByRole("progressbar", { name: "Storage used" })).toBeInTheDocument();
    expect(within(storage).getByText("Free on this computer")).toBeInTheDocument();
    expect(within(storage).getByText("Until you delete them")).toBeInTheDocument();
  });

  it("says so when the account does not load, with Try again", async () => {
    respond("storage_info", null);
    const { fail } = await import("../../test/tauri");
    fail("account_get", "The server is out of reach");
    render(<AccountCard />);
    await act(flushAsync);
    expect(screen.getByRole("alert")).toHaveTextContent("Your account didn't load");
  });

  it("initials and the minutes line", () => {
    expect(initials("Ayesha Siddiqui", "a@b.pk")).toBe("AS");
    expect(initials("", "bilal@qureshitextiles.pk")).toBe("B");
    expect(minutesLine(212, 300)).toBe("212 of 300 minutes used this month");
    expect(minutesLine(45, null)).toBe("45 minutes used this month");
    expect(minutesLine(null, 300)).toBeNull();
  });
});

describe("Switch back to classic (the foot of the new design's Settings)", () => {
  it("is hidden while no redesigned screen is on", async () => {
    respond("ui_flags", { ui_flags: {}, ui_classic: false });
    const { container } = render(<ClassicSwitch />);
    await act(flushAsync);
    expect(container).toBeEmptyDOMElement();
  });

  it("an older server: goes back to classic on this computer and is then gone", async () => {
    respond("ui_flags", { ui_flags: { "ui_v2.shell": true }, ui_classic: false });
    render(<ClassicSwitch />);
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "Switch back to classic" }));
    await act(flushAsync);
    expect(localStorage.getItem("echoledger-design")).toBe("classic");
    expect(callsOf("ui_design_set")).toHaveLength(0);
    expect(screen.queryByRole("button", { name: "Switch back to classic" })).toBeNull();
  });
});

describe("the app with the flags", () => {
  function backend(flags: Record<string, boolean> | null) {
    respond("app_status", makeStatus());
    respond("library_list", [makeItem("a", { title: "Bilal Qureshi · Qureshi Textiles" })]);
    respond("library_refresh", [makeItem("a", { title: "Bilal Qureshi · Qureshi Textiles" })]);
    respond("get_settings", makeSettings());
    respond("list_clients", { data: [] });
    respond("mic_users", []);
    respond("session_detail", {});
    if (flags) respond("ui_flags", { ui_flags: flags, ui_classic: false });
  }

  it("without the flags (or the command) the classic app stays as it is", async () => {
    backend(null);
    render(<MainApp />);
    await screen.findByRole("heading", { name: "Library" });
    await act(flushAsync);
    expect(document.querySelector("[data-shell=v2]")).toBeNull();
    expect(document.querySelector(".sidebar")).not.toBeNull();
  });

  it("a window that read its flags before the core's first config answer switches to the new design when ui:flags comes, on the same page", async () => {
    backend({});
    render(<MainApp />);
    await screen.findByRole("heading", { name: "Library" });
    await act(flushAsync);
    expect(document.querySelector("[data-shell=v2]")).toBeNull();
    expect(document.querySelector(".sidebar")).not.toBeNull();
    const v2 = { ui_flags: { "ui_v2.shell": true, "ui_v2.library": true }, ui_classic: false,
      ui_design: { choice: "v2", current: "v2", can_try: false, can_switch_back: true } };
    respond("ui_flags", v2);
    await emitEvent("ui:flags", v2);
    await act(flushAsync);
    await act(flushAsync);
    const nav = await screen.findByRole("navigation", { name: "Main" });
    expect(document.querySelector("[data-shell=v2]")).not.toBeNull();
    expect(document.querySelector(".sidebar")).toBeNull();
    expect(within(nav).getByRole("button", { name: "Library" })).toHaveAttribute("aria-current", "page");
  });

  it("ui_v2.shell shows the new shell around today's pages", async () => {
    backend({ "ui_v2.shell": true });
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    const nav = await screen.findByRole("navigation", { name: "Main" });
    expect(document.querySelector("[data-shell=v2]")).not.toBeNull();
    expect(document.querySelector(".sidebar")).toBeNull();
    expect(within(nav).getByRole("button", { name: "Library" })).toHaveAttribute("aria-current", "page");
  });

  it("in the app, the super admin's account has Admin panel at the sidebar's foot (the website's admin page); any other has none (DT-NAV-036)", async () => {
    const account = (superAdmin: boolean) => ({ name: "Ayesha", email: "ayesha@example.test", email_verified: true, two_factor: false, google: false, created_at: null,
      workspace: null, deletes_at: null, super_admin: superAdmin });
    backend({ "ui_v2.shell": true });
    respond("account_get", account(true));
    respond("server_page_open", null);
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    const nav = await screen.findByRole("navigation", { name: "Main" });
    fireEvent.click(await within(nav).findByRole("button", { name: "Admin panel" }));
    await act(flushAsync);
    expect(callsOf("server_page_open")).toEqual([{ page: "admin", serverUrl: null }]);
    cleanup();
    resetTauriMock();
    backend({ "ui_v2.shell": true });
    respond("account_get", account(false));
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    await screen.findByRole("navigation", { name: "Main" });
    expect(screen.queryByRole("button", { name: "Admin panel" })).toBeNull();
  });

  it("in the app, the ? key opens Help & shortcuts (DT-NAV-032)", async () => {
    backend({ "ui_v2.shell": true });
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    await screen.findByRole("navigation", { name: "Main" });
    fireEvent.keyDown(document.body, { key: "?" });
    await act(flushAsync);
    expect(await screen.findByRole("dialog", { name: "Help & shortcuts" })).toBeInTheDocument();
  });

  it("in the app, Activity opens the new design's Activity page, and its Review opens the Library with the waiting calls", async () => {
    backend({ "ui_v2.shell": true, "ui_v2.library": true });
    respond("activity_list", { data: [], unread_count: 0 });
    respond("pending_list", [makePendingItem("p1", { title: "Board call" })]);
    respond("upload_list", []);
    respond("skipped_list", []);
    respond("bin_list", { data: [] });
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    const nav = await screen.findByRole("navigation", { name: "Main" });
    fireEvent.click(within(nav).getByRole("button", { name: /^Activity/ }));
    await act(flushAsync);
    await act(flushAsync);
    expect(document.querySelector("[data-v2=activity]")).not.toBeNull();
    expect(document.querySelector(".activity-page")).toBeNull();
    fireEvent.click(await screen.findByRole("button", { name: "Review" }));
    await act(flushAsync);
    await act(flushAsync);
    // The Library on Needs you (the waiting calls' home, PLAN_LIBRARY_FILTERS), its list of waiting calls open.
    expect(within(screen.getByTestId("nav-panel")).getByRole("button", { name: /^Needs you/ })).toHaveAttribute("aria-current", "page");
    expect(await screen.findByRole("list", { name: "Waiting recordings" })).toHaveTextContent("Board call");
  });

  it("in the app, Shared opens the new design's Shared page, and classic's stays away", async () => {
    backend({ "ui_v2.shell": true });
    respond("shared_with_me", { data: [] });
    respond("shares_of", { data: [] });
    respond("request_links", { data: [] });
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    const nav = await screen.findByRole("navigation", { name: "Main" });
    fireEvent.click(within(nav).getByRole("button", { name: "Shared with me" }));
    await act(flushAsync);
    await act(flushAsync);
    expect(document.querySelector("[data-v2=shared]")).not.toBeNull();
    expect(document.querySelector(".shared-page")).toBeNull();
  });

  for (const [what, resolved] of [
    ["a recording a teammate sent", { kind: "session", session_id: "other-1" }],
    ["an item of Shared with me", { kind: "shared", shared_id: "01JDSHARE1" }],
  ] as const) {
    it(`in the app, a link to ${what} opens it read-only in the new Shared page, not the classic Library folder`, async () => {
      backend({ "ui_v2.shell": true });
      respond("links_resolve", resolved);
      respond("shared_with_me", { data: [{ id: "01JDSHARE1", kind: "session", from: { name: "Imran" }, note: null, title: "Call with Omar", sent_at: null, saved_at: null, client: null,
        session: makeItem("other-1", { title: "Call with Omar" }) }] });
      respond("shares_of", { data: [] });
      respond("request_links", { data: [] });
      render(<MainApp />);
      await act(flushAsync);
      await act(flushAsync);
      await screen.findByRole("navigation", { name: "Main" });
      await act(async () => emitEvent("main:navigate", makeNavigate({ link: "https://ledger.example.test/s/AbC123" })));
      await act(flushAsync);
      await act(flushAsync);
      await act(flushAsync);
      expect(document.querySelector("[data-v2=shared-reader]")).not.toBeNull();
      expect(screen.getByRole("heading", { level: 1, name: "Call with Omar" })).toBeInTheDocument();
      expect(document.querySelector("[data-testid=shared-banner]")).toBeNull();
      expect(callsOf("link_open")).toHaveLength(0);
    });
  }

  it("in the app, a link to a request link opens Shared › Request links with that link selected, not the classic sheet", async () => {
    backend({ "ui_v2.shell": true });
    respond("links_resolve", { kind: "request", request_link_id: "rq1" });
    respond("shared_with_me", { data: [] });
    respond("shares_of", { data: [] });
    respond("request_links", { data: [{ id: "rq1", title: "Voice notes for Acme", client_id: null, expires_at: null, max_files: null, max_mb: null, received: 2, revoked_at: null }] });
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    await screen.findByRole("navigation", { name: "Main" });
    await act(async () => emitEvent("main:navigate", makeNavigate({ link: "https://ledger.example.test/r/tok" })));
    await act(flushAsync);
    await act(flushAsync);
    await act(flushAsync);
    const page = document.querySelector("[data-v2=shared]") as HTMLElement;
    expect(page).not.toBeNull();
    expect(within(page).getByRole("tab", { name: /^Request links/ })).toHaveAttribute("aria-selected", "true");
    expect(within(page).getByText("Voice notes for Acme").closest("li")).toHaveClass("el-row--selected");
    expect(screen.queryByRole("dialog", { name: "Request files" })).toBeNull();
    expect(callsOf("link_open")).toHaveLength(0);
  });

  it("in the app, two quick presses on the sidebar's Record send one record_now", async () => {
    backend({ "ui_v2.shell": true });
    respond("record_now", null);
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    const nav = await screen.findByRole("navigation", { name: "Main" });
    fireEvent.click(within(nav).getByRole("button", { name: "Record" }));
    fireEvent.click(within(nav).getByRole("button", { name: "Record" }));
    await act(flushAsync);
    expect(callsOf("record_now")).toHaveLength(1);
  });

  it("the command palette in the new design: the sidebar's Search and Ctrl/⌘+K open it in the new look, with every row, the arrows, Enter and Esc", async () => {
    backend({ "ui_v2.shell": true });
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    const nav = await screen.findByRole("navigation", { name: "Main" });
    fireEvent.click(within(nav).getByRole("button", { name: /^Search/ }));
    const palette = screen.getByRole("dialog", { name: "Command palette" });
    expect(palette.closest(".el-palette")).not.toBeNull();
    const input = within(palette).getByRole("combobox");
    expect(input).toHaveFocus();
    // Every group and command classic has; the new design's Record page by its name.
    const labels = () => within(palette).queryAllByRole("option").map((o) => o.textContent);
    expect(labels()).toEqual(expect.arrayContaining(["Record", "Capture voice note playing now", "Copy the last 2 minutes of captions", "Go to Projects", "Go to Activity", "Go to Shared", "Go to Record page", "Help & shortcuts", "Sync the library"]));
    expect(labels()).not.toContain("Go to Home");
    fireEvent.change(input, { target: { value: "activ" } });
    expect(within(palette).getAllByRole("option")[0]).toHaveTextContent("Go to Activity");
    fireEvent.keyDown(palette, { key: "ArrowDown" });
    fireEvent.keyDown(palette, { key: "ArrowUp" });
    fireEvent.keyDown(palette, { key: "Enter" });
    await act(flushAsync);
    expect(screen.queryByRole("dialog", { name: "Command palette" })).toBeNull();
    expect(within(nav).getByRole("button", { name: /^Activity/ })).toHaveAttribute("aria-current", "page");
    fireEvent.keyDown(window, { key: "k", ctrlKey: true, metaKey: true });
    await act(flushAsync);
    const again = screen.getByRole("dialog", { name: "Command palette" });
    expect(within(again).getByRole("combobox")).toHaveFocus();
    fireEvent.change(within(again).getByRole("combobox"), { target: { value: "x" } });
    expect(within(again).getByRole("status")).toHaveTextContent("Type one more letter to search recordings.");
    fireEvent.keyDown(again, { key: "Escape" });
    expect(screen.queryByRole("dialog", { name: "Command palette" })).toBeNull();
  });

  for (const [platform, key] of [["macos", { metaKey: true }], ["windows", { ctrlKey: true }]] as const) {
    it(`in the app, ${platform === "macos" ? "Cmd" : "Ctrl"}+F opens search with the cursor in its field, as ${platform === "macos" ? "⌘K" : "Ctrl+K"} does`, async () => {
      backend({ "ui_v2.shell": true });
      respond("app_status", makeStatus({ platform }));
      render(<MainApp />);
      await act(flushAsync);
      await act(flushAsync);
      const nav = await screen.findByRole("navigation", { name: "Main" });
      expect(within(nav).getByRole("button", { name: "Search" })).toHaveAttribute("title", platform === "macos" ? "Search · ⌘F" : "Search · Ctrl+F");
      fireEvent.keyDown(document.body, { key: "f", ...key });
      await act(flushAsync);
      expect(within(screen.getByRole("dialog", { name: "Command palette" })).getByRole("combobox")).toHaveFocus();
      fireEvent.keyDown(screen.getByRole("dialog", { name: "Command palette" }), { key: "Escape" });
      fireEvent.keyDown(window, { key: "k", ...key });
      await act(flushAsync);
      expect(within(screen.getByRole("dialog", { name: "Command palette" })).getByRole("combobox")).toHaveFocus();
    });
  }

  it("the profile sits at the sidebar's foot and opens Settings; with only part of the new design on, classic Appearance offers the way back", async () => {
    backend({ "ui_v2.shell": true });
    respond("account_get", { name: "Ayesha Siddiqui", email: "ayesha@qureshitextiles.pk", email_verified: true, two_factor: false, google: false, created_at: null, workspace: null, deletes_at: null });
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    const me = await screen.findByRole("button", { name: /^Ayesha Siddiqui/ });
    expect(me).toHaveAccessibleDescription("Opens Settings");
    fireEvent.click(me);
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(await screen.findByRole("button", { name: /^Appearance/ }));
    await act(flushAsync);
    expect(screen.getByRole("button", { name: "Switch back to classic" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Try the new design" })).toBeNull();
  });

  it("ui_v2.library shows the new Library on the real list", async () => {
    backend({ "ui_v2.shell": true, "ui_v2.library": true });
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    const list = await screen.findByRole("list", { name: "Recordings" });
    expect(within(list).getByRole("button", { name: "Bilal Qureshi · Qureshi Textiles" })).toBeInTheDocument();
    expect(document.querySelector("[data-v2=library]")).not.toBeNull();
  });
});
