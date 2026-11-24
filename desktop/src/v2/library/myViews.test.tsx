// My views (PLAN_LIBRARY_FILTERS, MY VIEWS; contract 2.0.0-p "Saved views"): the local-first store synced through
// /api/views (a view made offline sent with its client_ref, so twice never makes two; the copy read again when the
// library sync's views_version is not its own; the server's refusals), and in the app: Save as view puts it in MY VIEWS
// and opens it there, a view keeps its filters, search and sort, Save changes, and ⌘1…⌘9.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { useState } from "react";
import type { LibraryItem, ServerView } from "../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";
import { LibraryNav } from "./LibraryNav";
import { libraryViewCounts, prepare } from "./facets";
import { fromServer, MAX_VIEWS, syncedViewsStore, toServer, VIEW_WORDS, viewsAccount, viewsStore, type ViewSpec } from "./myViews";
import { ALL_SCOPE, scopeKey, type LibraryScope } from "./scope";
import { resetSyncClock } from "./syncClock";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const SPEC: ViewSpec = { filters: { source: { op: "is", values: ["whatsapp"] } }, search: null, sort: "newest", group: "date", density: null };
const server = (id: string, name: string, extra: Partial<ServerView> = {}): ServerView => ({
  id, name, filters: { source: { op: "is", values: ["whatsapp"] } }, search: null, sort: "newest", group: "date", density: "comfortable", position: 0, ...extra,
});
type Fake = {
  views: ServerView[]; version: number; offline: boolean;
  /** Answers wait for this while set (a sync caught on its way). */
  gate: Promise<void> | null;
  /** A refusal for good (as the core answers a 4xx), or null to answer. */
  refuse: (command: string, args: Record<string, unknown>) => { status: number; code: string; message: string } | null;
};
/** The server's views, as a test keeps them: creates answered with ids, the version counted, refusals as the core gives them. */
function fakeServer(start: ServerView[] | Fake = []): Fake {
  const state: Fake = Array.isArray(start) ? { views: [...start], version: 1, offline: false, gate: null, refuse: () => null } : start;
  const answer = (command: string, f: (args: Record<string, unknown>) => unknown) => async (args: Record<string, unknown>) => {
    if (state.gate) await state.gate;
    if (state.offline) throw "The server didn't answer.";
    const refused = state.refuse(command, args);
    return refused ? { refused } : f(args);
  };
  respondWith("views_get", answer("views_get", () => ({ data: state.views, views_version: state.version })));
  respondWith("library_views_version", answer("library_views_version", () => state.version));
  respondWith("view_create", answer("view_create", ({ view }) => {
    const v = view as Omit<ServerView, "id" | "position">;
    // Idempotent: a client_ref seen before answers with the view it made.
    const twin = state.views.find((x) => x.client_ref && x.client_ref === v.client_ref);
    if (twin) return { data: twin, views_version: state.version };
    if (state.views.length >= MAX_VIEWS) return { refused: { status: 422, code: "views_limit", message: "The server did not accept this." } };
    const made = { ...v, id: `v${state.views.length + 1}`, position: state.views.length } as ServerView;
    state.views.push(made);
    state.version++;
    return { data: made, views_version: state.version };
  }));
  respondWith("view_update", answer("view_update", ({ id, patch }) => {
    const at = state.views.findIndex((v) => v.id === id);
    if (at < 0) return { refused: { status: 404, code: "view_not_found", message: "That is no longer on the server." } };
    state.views[at] = { ...state.views[at], ...(patch as Partial<ServerView>) };
    state.version++;
    return { data: state.views[at], views_version: state.version };
  }));
  respondWith("view_delete", answer("view_delete", ({ id }) => {
    state.views = state.views.filter((v) => v.id !== id);
    state.version++;
    return { id, views_version: state.version };
  }));
  respondWith("views_order", answer("views_order", ({ ids }) => {
    state.views = (ids as string[]).map((id) => state.views.find((v) => v.id === id)!).filter(Boolean);
    state.version++;
    return { data: state.views, views_version: state.version };
  }));
  return state;
}
/** A gate the server's answers wait at, and its opening. */
function gate(srv: Fake): () => Promise<void> {
  let open: () => void = () => undefined;
  srv.gate = new Promise((r) => { open = r; });
  return async () => {
    srv.gate = null;
    open();
    await act(flushAsync);
  };
}

describe("the views store, synced through /api/views", () => {
  beforeEach(() => {
    resetTauriMock();
    window.localStorage.clear();
  });

  it("a view made offline shows at once, is kept on this computer and sent with its client_ref; sent again, it is made once", async () => {
    const srv = fakeServer();
    srv.offline = true;
    const store = syncedViewsStore();
    store.use("server:me@example.test");
    const made = await store.create("  WhatsApp   calls ", SPEC);
    expect(made).toMatchObject({ name: "WhatsApp calls", pending: "create" });
    expect(store.list().map((v) => v.name)).toEqual(["WhatsApp calls"]);
    await store.sync();
    // Offline: still here, still waiting, kept for this account.
    expect(store.list()[0]).toMatchObject({ id: made.id, pending: "create" });
    expect(JSON.parse(window.localStorage.getItem("echoledger-my-views:server:me@example.test") ?? "{}").views).toHaveLength(1);
    srv.offline = false;
    await store.sync();
    await store.sync();
    const refs = callsOf("view_create").map((c) => (c.view as { client_ref: string }).client_ref);
    expect(refs).toHaveLength(3);
    expect(new Set(refs)).toEqual(new Set([made.client_ref]));
    expect(srv.views).toHaveLength(1);
    expect(store.list()).toEqual([expect.objectContaining({ id: "v1", name: "WhatsApp calls", client_ref: made.client_ref })]);
    expect(store.list()[0].pending).toBeUndefined();
    // The contract's words: name for Name A–Z, source for Group by Source.
    expect(toServer({ ...made, spec: { ...SPEC, sort: "title", group: "app" } })).toMatchObject({ sort: "name", group: "source", density: "comfortable" });
  });

  it("the copy is read again when the library sync's views_version is not its own, never when it is", async () => {
    const srv = fakeServer([server("v1", "Clients")]);
    const store = syncedViewsStore();
    store.use("a");
    await store.sync();
    expect(store.list().map((v) => v.name)).toEqual(["Clients"]);
    const reads = callsOf("views_get").length;
    await store.sync();
    expect(callsOf("views_get")).toHaveLength(reads);
    // Another device renamed it and made one.
    srv.views = [server("v1", "Clients 2026"), server("v9", "Urdu", { filters: { language: { op: "is", values: ["ur"] } }, sort: "name", group: "source" })];
    srv.version = 7;
    await store.sync();
    expect(store.list().map((v) => v.name)).toEqual(["Clients 2026", "Urdu"]);
    expect(store.list()[1].spec).toMatchObject({ filters: { language: { op: "is", values: ["ur"] } }, sort: "title", group: "app" });
  });

  it("rename, save changes, move and delete show at once and are sent; the server's copy is read after", async () => {
    const srv = fakeServer([server("v1", "One"), server("v2", "Two"), server("v3", "Three")]);
    const store = syncedViewsStore();
    store.use("a");
    await store.sync();
    await store.rename("v1", "First");
    await store.update("v2", { ...SPEC, search: " invoice ", sort: "oldest" });
    await store.move("v3", 0);
    await store.remove("v2");
    expect(store.list().map((v) => v.name)).toEqual(["Three", "First"]);
    await store.sync();
    await store.sync();
    expect(callsOf("view_update")[0]).toEqual({ id: "v1", patch: expect.objectContaining({ name: "First" }) });
    expect(callsOf("view_delete")).toEqual([{ id: "v2" }]);
    expect(callsOf("views_order").at(-1)).toEqual({ ids: ["v3", "v1"] });
    expect(srv.views.map((v) => v.name)).toEqual(["Three", "First"]);
    expect(store.list().map((v) => [v.id, v.name, v.pending])).toEqual([["v3", "Three", undefined], ["v1", "First", undefined]]);
  });

  it("names: trimmed, at most 60 characters, never one of the person's other views; 30 at most", async () => {
    fakeServer();
    const store = syncedViewsStore();
    store.use("a");
    await store.create("Clients", SPEC);
    await expect(store.create("clients", SPEC)).rejects.toThrow(VIEW_WORDS.taken("Clients"));
    await expect(store.create("   ", SPEC)).rejects.toThrow(VIEW_WORDS.empty);
    await expect(store.create("x".repeat(61), SPEC)).rejects.toThrow(VIEW_WORDS.long);
    for (let i = 1; i < MAX_VIEWS; i++) await store.create(`View ${i}`, SPEC);
    await expect(store.create("One more", SPEC)).rejects.toThrow(VIEW_WORDS.full);
  });

  it("a view the server refuses (30 there already) goes and is said; one deleted on another device goes quietly", async () => {
    const srv = fakeServer(Array.from({ length: MAX_VIEWS }, (_, i) => server(`s${i}`, `Server ${i}`)));
    const store = syncedViewsStore();
    store.use("a");
    const heard: string[] = [];
    store.onProblem((t) => heard.push(t));
    // Made here before this computer knew of the server's 30.
    window.localStorage.setItem("echoledger-my-views:b", JSON.stringify({ views: [{ id: "local-1", name: "Late", spec: SPEC, client_ref: "r1", pending: "create" }], version: null, reorder: false }));
    store.use("b");
    await store.sync();
    expect(heard).toEqual([`Late wasn't saved: ${VIEW_WORDS.full}`]);
    expect(store.list().map((v) => v.name)).toHaveLength(MAX_VIEWS);
    // Deleted on another device, then renamed here: the server says it is gone, and it goes without a word.
    srv.views = srv.views.filter((v) => v.id !== "s1");
    await store.rename("s1", "Renamed");
    await store.sync();
    await store.sync();
    expect(store.list().some((v) => v.id === "s1")).toBe(false);
    expect(heard).toHaveLength(1);
  });

  it("a view deleted here while its create was on its way is deleted on the server too", async () => {
    const srv = fakeServer();
    let release: () => void = () => undefined;
    const real = srv;
    respondWith("view_create", ({ view }) => new Promise((resolve) => {
      release = () => {
        const made = { ...(view as ServerView), id: "v1", position: 0 };
        real.views.push(made);
        real.version++;
        resolve({ data: made, views_version: real.version });
      };
    }));
    const store = syncedViewsStore();
    store.use("a");
    const made = await store.create("Brief", SPEC);
    await act(flushAsync);
    await store.remove(made.id);
    expect(store.list()).toEqual([]);
    release();
    await act(flushAsync);
    expect(callsOf("view_delete")).toEqual([{ id: "v1" }]);
    expect(srv.views).toEqual([]);
  });

  it("nothing shows, is made or is sent until the account is known; then that account's own copy", async () => {
    fakeServer([server("v1", "Clients")]);
    window.localStorage.setItem("echoledger-my-views", JSON.stringify({ views: [{ id: "old", name: "Shared by nobody", spec: SPEC }], version: null, reorder: false }));
    const store = syncedViewsStore();
    const seen = vi.fn();
    const un = store.subscribe(seen);
    await act(flushAsync);
    expect(store.list()).toEqual([]);
    await expect(store.create("Early", SPEC)).rejects.toThrow(VIEW_WORDS.notReady);
    await store.sync();
    expect(callsOf("views_get")).toEqual([]);
    expect(callsOf("library_views_version")).toEqual([]);
    store.use(viewsAccount("https://ledger.example.test/", "Me@Example.test", 7));
    await act(flushAsync);
    expect(store.list().map((v) => v.name)).toEqual(["Clients"]);
    expect(window.localStorage.getItem("echoledger-my-views:https://ledger.example.test|me@example.test|7")).toContain("Clients");
    // The server, the email and the workspace are all needed.
    expect([viewsAccount("", "me@x.test", 1), viewsAccount("https://s", null, 1), viewsAccount("https://s", "me@x.test", null)]).toEqual([null, null, null]);
    un();
  });

  it("switching account or workspace mid-sync: what the older sync hears back is dropped, and each keeps its own copy", async () => {
    const one = fakeServer();
    const store = syncedViewsStore();
    const un = store.subscribe(() => undefined);
    store.use("srv|me|1");
    await act(flushAsync);
    const open = gate(one);
    const made = await store.create("Mine", SPEC);
    await act(flushAsync);
    expect(callsOf("view_create")).toHaveLength(1);
    // Workspace 2 (its own server copy) while workspace 1's create is on its way.
    const two = fakeServer([server("w1", "Theirs")]);
    store.use("srv|me|2");
    expect(store.list()).toEqual([]);
    await act(flushAsync);
    expect(store.list().map((v) => v.name)).toEqual(["Theirs"]);
    await open();
    // Workspace 1's answer changes nothing here, nor workspace 1's copy (its view still waits, sent again by its ref).
    expect(store.list().map((v) => v.name)).toEqual(["Theirs"]);
    const kept = JSON.parse(window.localStorage.getItem("echoledger-my-views:srv|me|1")!);
    expect(kept.views).toEqual([expect.objectContaining({ id: made.id, pending: "create" })]);
    expect(JSON.parse(window.localStorage.getItem("echoledger-my-views:srv|me|2")!).views.map((v: { name: string }) => v.name)).toEqual(["Theirs"]);
    // Back to workspace 1: the same view is sent again and made once.
    fakeServer(one);
    store.use("srv|me|1");
    await act(flushAsync);
    expect(one.views).toHaveLength(1);
    expect(store.list()).toEqual([expect.objectContaining({ id: "v1", name: "Mine" })]);
    expect(two.views.map((v) => v.name)).toEqual(["Theirs"]);
    // Another account on the same server: none of these.
    fakeServer();
    store.use("srv|someone@else|1");
    await act(flushAsync);
    expect(store.list()).toEqual([]);
    un();
  });

  it("a change the server refuses for good doesn't hold up the rest: a new view goes, a change goes back to the server's copy, each said", async () => {
    const srv = fakeServer([server("v1", "One"), server("v2", "Two")]);
    srv.refuse = (command, args) => {
      const body = (args.view ?? args.patch) as { name?: string } | undefined;
      return body?.name?.includes("!") ? { status: 422, code: "validation_failed", message: "Names can't have “!”." } : command === "view_delete" && args.id === "v2"
        ? { status: 422, code: "validation_failed", message: "That view is in use." } : null;
    };
    const store = syncedViewsStore();
    store.use("a");
    await store.sync();
    const heard: string[] = [];
    store.onProblem((t) => heard.push(t));
    srv.offline = true;
    await store.create("Bad!", SPEC);
    await store.rename("v1", "One!");
    await store.remove("v2");
    await store.create("Good", SPEC);
    await store.sync();
    srv.offline = false;
    await store.sync();
    await store.sync();
    // In the views' order, each by the name the person gave it.
    expect(heard).toEqual([
      "Your changes to One! weren't saved: Names can't have “!”.",
      "Two wasn't deleted: That view is in use.",
      "Bad! wasn't saved: Names can't have “!”.",
    ]);
    // Good was sent after them; One and Two are as the server has them.
    expect(srv.views.map((v) => v.name)).toEqual(["One", "Two", "Good"]);
    expect(store.list().map((v) => [v.name, v.pending])).toEqual([["One", undefined], ["Two", undefined], ["Good", undefined]]);
  });

  it("a refused rename or Save changes puts back the server's last copy at once, and the list is read again", async () => {
    const srv = fakeServer([server("v1", "One", { search: "invoice", sort: "oldest" })]);
    const store = syncedViewsStore();
    store.use("a");
    await store.sync();
    srv.refuse = (command) => (command === "view_update" ? { status: 422, code: "validation_failed", message: "Not like that." } : null);
    // The list can't be read meanwhile: what is shown is the copy kept here.
    respondWith("views_get", () => Promise.reject("The server didn't answer."));
    await store.update("v1", { ...SPEC, search: "budget", sort: "longest" });
    await store.rename("v1", "Uno");
    expect(store.list()[0]).toMatchObject({ name: "Uno", spec: { search: "budget", sort: "longest" }, pending: "update" });
    await store.sync();
    expect(store.list()[0]).toMatchObject({ name: "One", spec: { search: "invoice", sort: "oldest" } });
    expect(store.list()[0].pending).toBeUndefined();
    // Its version forgotten: the server's list is asked for again.
    const kept = JSON.parse(window.localStorage.getItem("echoledger-my-views:a")!);
    expect(kept.version).toBeNull();
    expect(kept.views[0].saved).toMatchObject({ name: "One" });
  });

  it("a view deleted while its rename is on its way is deleted on the server after", async () => {
    const srv = fakeServer([server("v1", "One"), server("v2", "Two")]);
    const store = syncedViewsStore();
    store.use("a");
    await store.sync();
    const open = gate(srv);
    await store.rename("v1", "First");
    await act(flushAsync);
    expect(callsOf("view_update")).toHaveLength(1);
    await store.remove("v1");
    expect(store.list().map((v) => v.name)).toEqual(["Two"]);
    await open();
    await store.sync();
    expect(callsOf("view_delete")).toEqual([{ id: "v1" }]);
    expect(srv.views.map((v) => v.name)).toEqual(["Two"]);
    expect(store.list().map((v) => v.name)).toEqual(["Two"]);
  });

  it("a server view read leniently: a sort or group this app lacks shows as Newest and Date; unknown sections are left out", () => {
    expect(fromServer(server("v", "Odd", { sort: "loudest", group: "mood", filters: { colour: { values: ["red"] }, source: { op: "is_not", values: ["zoom"] } } })).spec)
      .toEqual({ filters: { source: { op: "is_not", values: ["zoom"] } }, search: null, sort: "newest", group: "date", density: "comfortable" });
  });
});

const ITEMS: LibraryItem[] = [
  makeItem("wa", { title: "Bilal on WhatsApp", source_app: "whatsapp", source_app_label: "WhatsApp", started_at: "2026-11-17T06:00:00Z", duration_ms: 60_000 }),
  makeItem("wa2", { title: "Invoice on WhatsApp", source_app: "whatsapp", source_app_label: "WhatsApp", started_at: "2026-11-17T05:00:00Z", duration_ms: 900_000, opened: false }),
  makeItem("zoom", { title: "Weekly Zoom", source_app: "zoom", source_app_label: "Zoom", started_at: "2026-11-17T04:00:00Z" }),
];

/** The sidebar and the Library as MainApp joins them. */
function App() {
  const [scope, setScope] = useState<LibraryScope>(ALL_SCOPE);
  const [request, setRequest] = useState<{ scope: LibraryScope; seq: number } | null>(null);
  const [seen, setSeen] = useState<{ items: readonly LibraryItem[]; read: ReadonlySet<string> } | null>(null);
  const [filters, setFilters] = useState<{ seq: number } | null>(null);
  const facts = seen ? prepare(seen.items, { read: seen.read }) : null;
  const open = (next: LibraryScope) => {
    setScope(next);
    setRequest((r) => ({ scope: next, seq: (r?.seq ?? 0) + 1 }));
  };
  return (
    <>
      <nav aria-label="Main">
        <LibraryNav current={scopeKey(scope)} counts={facts ? libraryViewCounts(facts.facts) : null} labelCounts={{}} onScope={open}
          onNewView={() => setFilters((f) => ({ seq: (f?.seq ?? 0) + 1 }))} say={() => undefined} onLibraryChanged={() => undefined} />
      </nav>
      <LibraryV2 scopeRequest={request} onScope={setScope} filtersRequest={filters} onItems={(items, read) => setSeen({ items, read })} />
    </>
  );
}
const nav = () => within(screen.getByRole("navigation", { name: "Main" }));
const myViews = () => within(nav().getByRole("list", { name: "My views" }));
const rows = () => [...(screen.queryByRole("list", { name: "Recordings" })?.querySelectorAll<HTMLButtonElement>("button[data-row-id]") ?? [])].map((b) => b.dataset.rowId);
const pills = () => within(screen.getByRole("group", { name: "Filters on" }));
const title = () => screen.getByRole("heading", { level: 1 });

describe("My views in the sidebar and the Library", () => {
  beforeEach(() => {
    resetTauriMock();
    resetSyncClock();
    window.localStorage.clear();
    Object.defineProperty(window, "innerWidth", { value: 1440, configurable: true, writable: true });
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("library_folder", []);
    respond("bin_list", { data: [] });
    respond("lists_get", []);
    fakeServer();
    viewsStore.use(null);
    viewsStore.use("https://ledger.example.test|me@example.test|1");
  });
  afterEach(() => {
    Object.defineProperty(window, "innerWidth", { value: 1024, configurable: true, writable: true });
  });

  it("Save as view keeps the filters, the search and the sort; it appears in MY VIEWS, opens there and syncs", async () => {
    render(<App />);
    await act(flushAsync);
    fireEvent.click(screen.getAllByRole("button", { name: "Show only WhatsApp" })[0]);
    fireEvent.change(screen.getByRole("searchbox", { name: "Search recordings" }), { target: { value: "whatsapp" } });
    fireEvent.click(screen.getByRole("button", { name: "Sort & display" }));
    fireEvent.click(screen.getByRole("menuitemradio", { name: "Longest" }));
    fireEvent.click(pills().getByRole("button", { name: "Save as view" }));
    const dialog = within(screen.getByRole("dialog", { name: "Save as view" }));
    fireEvent.change(dialog.getByRole("textbox", { name: "View name" }), { target: { value: "WhatsApp calls" } });
    fireEvent.click(dialog.getByRole("button", { name: "Save view" }));
    await act(flushAsync);
    expect(myViews().getByRole("button", { name: "WhatsApp calls" })).toHaveAttribute("aria-current", "page");
    expect(title()).toHaveTextContent(/^WhatsApp calls/);
    expect(rows()).toEqual(["wa2", "wa"]);
    const sent = callsOf("view_create")[0].view as ServerView;
    expect(sent).toMatchObject({ name: "WhatsApp calls", filters: { source: { op: "is", values: ["whatsapp"] } }, search: "whatsapp", sort: "longest", group: "date" });
    // All again (no filters, no search; Longest stays this computer's choice), then the view: its filters and search come back.
    fireEvent.click(nav().getByRole("button", { name: "All recordings, 3" }));
    await act(flushAsync);
    expect(rows()).toEqual(["wa2", "zoom", "wa"]);
    expect(screen.getByRole("searchbox", { name: "Search recordings" })).toHaveValue("");
    fireEvent.click(myViews().getByRole("button", { name: "WhatsApp calls" }));
    await act(flushAsync);
    expect(rows()).toEqual(["wa2", "wa"]);
    expect(screen.getByRole("searchbox", { name: "Search recordings" })).toHaveValue("whatsapp");
  });

  it("a view changed offers Save changes, which saves it again; + New view opens the filters", async () => {
    render(<App />);
    await act(flushAsync);
    await viewsStore.create("WhatsApp", SPEC);
    await act(flushAsync);
    fireEvent.click(myViews().getByRole("button", { name: "WhatsApp" }));
    await act(flushAsync);
    expect(rows()).toEqual(["wa", "wa2"]);
    expect(pills().queryByRole("button", { name: "Save changes" })).toBeNull();
    fireEvent.click(pills().getByRole("button", { name: "Source: WhatsApp" }));
    fireEvent.click(within(screen.getByRole("dialog", { name: "Edit Source" })).getByRole("checkbox", { name: /^Zoom/ }));
    fireEvent.click(pills().getByRole("button", { name: "Save changes" }));
    await act(flushAsync);
    expect(viewsStore.list()[0].spec.filters).toEqual({ source: { op: "is", values: ["whatsapp", "zoom"] } });
    expect(pills().queryByRole("button", { name: "Save changes" })).toBeNull();
    fireEvent.click(myViews().getByRole("button", { name: "New view" }));
    await act(flushAsync);
    expect(screen.getByTestId("filter-panel")).toBeInTheDocument();
  });

  it("a view dragged (pointer events, as on Windows the page's drag and drop is off) moves in the order; Escape moves nothing", async () => {
    render(<App />);
    await act(flushAsync);
    for (const name of ["One", "Two", "Three"]) await viewsStore.create(name, SPEC);
    await act(flushAsync);
    const names = () => myViews().getAllByRole("button").filter((b) => !b.className.includes("el-nav-row__more")).map((b) => b.textContent);
    expect(names()).toEqual(["One", "Two", "Three", "New view"]);
    const rowOf = (name: string) => myViews().getByRole("button", { name }).closest("li")!;
    const drag = (from: HTMLElement, to: HTMLElement | null, escape = false) => {
      document.elementFromPoint = vi.fn(() => to) as typeof document.elementFromPoint;
      fireEvent.pointerDown(from, { button: 0, clientX: 10, clientY: 10, buttons: 1 });
      fireEvent.pointerMove(window, { clientX: 10, clientY: 40, buttons: 1 });
      if (to && !escape) expect(to).toHaveClass("el-nav-row--drop");
      if (escape) fireEvent.keyDown(window, { key: "Escape" });
      fireEvent.pointerUp(window, { clientX: 10, clientY: 40 });
      fireEvent.click(from.querySelector("button")!);
    };
    // Three before One: first.
    drag(rowOf("Three"), rowOf("One"));
    await act(flushAsync);
    expect(names()).toEqual(["Three", "One", "Two", "New view"]);
    // The press that ended the drag didn't open it.
    expect(title()).toHaveTextContent(/^All/);
    // One onto + New view: last.
    drag(rowOf("One"), rowOf("New view"));
    await act(flushAsync);
    expect(names()).toEqual(["Three", "Two", "One", "New view"]);
    drag(rowOf("One"), rowOf("Three"), true);
    await act(flushAsync);
    expect(names()).toEqual(["Three", "Two", "One", "New view"]);
    expect(document.querySelector(".el-nav-row--drop")).toBeNull();
    // A press without a drag opens it.
    fireEvent.pointerDown(rowOf("Two"), { button: 0, clientX: 10, clientY: 10, buttons: 1 });
    fireEvent.pointerUp(window, { clientX: 10, clientY: 10 });
    fireEvent.click(myViews().getByRole("button", { name: "Two" }));
    await act(flushAsync);
    expect(title()).toHaveTextContent(/^Two/);
  });

  it("Alt+1…Alt+9 open the panel's first nine: the library views, then My views (⌘1…⌘6 are the rail's)", async () => {
    render(<App />);
    await act(flushAsync);
    await viewsStore.create("WhatsApp", SPEC);
    await act(flushAsync);
    // Option+3 types "£" on a Mac: the key's place counts.
    fireEvent.keyDown(window, { key: "£", code: "Digit3", altKey: true });
    await act(flushAsync);
    expect(nav().getByRole("button", { name: "Unread, 1" })).toHaveAttribute("aria-current", "page");
    expect(rows()).toEqual(["wa2"]);
    // Five library views since v2.1 (Audio only took in Not transcribed), so the first of My views is Alt+6.
    fireEvent.keyDown(window, { key: "6", code: "Digit6", altKey: true });
    await act(flushAsync);
    expect(myViews().getByRole("button", { name: "WhatsApp" })).toHaveAttribute("aria-current", "page");
    expect(myViews().getByRole("button", { name: "WhatsApp" })).toHaveAttribute("aria-keyshortcuts", "Alt+6");
    // Typed in a field, Alt+1 is the field's (a character on some keyboards), never a view.
    const field = screen.getByRole("searchbox", { name: "Search recordings" });
    field.focus();
    fireEvent.keyDown(field, { key: "¡", code: "Digit1", altKey: true });
    await act(flushAsync);
    expect(myViews().getByRole("button", { name: "WhatsApp" })).toHaveAttribute("aria-current", "page");
    // Nothing at 9: nothing happens.
    fireEvent.keyDown(window, { key: "9", code: "Digit9", altKey: true });
    expect(myViews().getByRole("button", { name: "WhatsApp" })).toHaveAttribute("aria-current", "page");
  });
});
