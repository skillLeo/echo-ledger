// Smart folders of the Library (docs/API.md "Projects", since v1.5): the sidebar's folders with their counts, the
// Library on one folder as the server lists it (what is saved here until it answers), and Shared with me with its
// read-only recordings and Save to my library.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import MainApp from "./MainApp";
import { LibraryView } from "./LibraryView";
import { sentLine } from "./SharedWithMe";
import type { LibraryItem, SharedItem } from "../../lib/ipc";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeSettings, makeStatus, makeTranscript } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const ITEMS: LibraryItem[] = [
  makeItem("a", { title: "Zoom with Acme", client: { id: "c1", name: "Acme" }, source_app: "zoom", started_at: "2026-10-24T09:00:00Z" }),
  makeItem("b", { title: "WhatsApp call", client: null, source_app: "whatsapp", started_at: "2026-10-24T08:00:00Z" }),
  makeItem("c", { title: "Imported memo", client: null, source_app: "voice_note", started_at: "2026-10-23T08:00:00Z" }),
];
const SHARED: SharedItem[] = [
  {
    id: "01JDSHARE1", kind: "session", from: { name: "Hassam", email: "h@example.com" }, note: "for tomorrow", title: "Call with Omar",
    sent_at: "2026-10-30T11:00:00Z", saved_at: null, client: null,
    session: makeItem("other-1", { title: "Call with Omar", client: { id: "x9", name: "Their project" }, transcript: { final_status: "completed", final_transcript_id: "tr-o", live_available: false, preview: null } }),
  },
  { id: "01JDSHARE2", kind: "project", from: { name: null, email: "ana@example.com" }, note: null, title: "Northwind", sent_at: null, saved_at: null, session: null, client: { id: "x2", name: "Northwind" } },
];

const titles = () => [...screen.getByTestId("library-list").querySelectorAll(".item-title")].map((t) => t.textContent);

function libraryBackend() {
  respond("library_list", ITEMS);
  respond("library_refresh", ITEMS);
  respond("pending_list", []);
  respond("upload_list", []);
  respond("unsure_markers", null);
  respond("list_clients", { data: [] });
  respond("transcript_detail", makeTranscript({ id: "tr-o" }));
  respond("session_detail", { playback_url: null, markers: [], speaker_names: {} });
}

beforeEach(() => {
  resetTauriMock();
  window.localStorage.clear();
});
afterEach(async () => {
  await flushAsync();
  vi.restoreAllMocks();
});

describe("the Library on a smart folder", () => {
  it("lists the recordings the server puts in the folder", async () => {
    libraryBackend();
    respondWith("library_folder", ({ folder }) => (folder === "imported" ? [ITEMS[2]] : []));
    const onSmartFolder = vi.fn();
    render(<LibraryView notify={vi.fn()} smartFolder="imported" onSmartFolder={onSmartFolder} />);
    expect(await screen.findByRole("heading", { name: "Imported" })).toBeInTheDocument();
    await waitFor(() => expect(titles()).toEqual(["Imported memo"]));
    expect(callsOf("library_folder")).toEqual([{ folder: "imported" }]);
    expect(screen.getByText(/1 recording in this smart folder/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Show every recording" }));
    expect(onSmartFolder).toHaveBeenCalledWith(null);
  });

  it("shows what this computer can tell while the server cannot be reached", async () => {
    libraryBackend();
    fail("library_folder", "Network error: offline");
    render(<LibraryView notify={vi.fn()} smartFolder="inbox" />);
    await waitFor(() => expect(titles()).toEqual(["WhatsApp call", "Imported memo"]));
  });

  it("reads the folder again after the library changed", async () => {
    libraryBackend();
    let inbox = [ITEMS[1], ITEMS[2]];
    respondWith("library_folder", () => inbox);
    render(<LibraryView notify={vi.fn()} smartFolder="inbox" />);
    await waitFor(() => expect(titles()).toEqual(["WhatsApp call", "Imported memo"]));
    inbox = [ITEMS[2]];
    await emitEvent("library:updated", 1);
    await waitFor(() => expect(titles()).toEqual(["Imported memo"]), { timeout: 2000 });
  });

  it("says so when the Inbox is empty", async () => {
    libraryBackend();
    respond("library_folder", []);
    render(<LibraryView notify={vi.fn()} smartFolder="inbox" />);
    expect(await screen.findByText("The Inbox is empty")).toBeInTheDocument();
  });
});

describe("Shared with me", () => {
  async function renderShared(extra: Partial<Parameters<typeof LibraryView>[0]> = {}) {
    libraryBackend();
    respond("shared_with_me", { data: SHARED });
    const props = { notify: vi.fn(), onSmartFolder: vi.fn(), onOpenClient: vi.fn(), ...extra };
    render(<LibraryView smartFolder="shared" {...props} />);
    expect(await screen.findByRole("list", { name: "Shared with me" })).toBeInTheDocument();
    return props;
  }

  it("lists what teammates sent, who sent it and their note", async () => {
    await renderShared();
    const list = screen.getByRole("list", { name: "Shared with me" });
    expect(within(list).getByText("Call with Omar")).toBeInTheDocument();
    expect(within(list).getByText(/From Hassam/)).toBeInTheDocument();
    expect(within(list).getByText("“for tomorrow”")).toBeInTheDocument();
    expect(within(list).getByText("Project")).toBeInTheDocument();
    expect(within(list).getByText(/From ana@example\.com/)).toBeInTheDocument();
    expect(screen.queryByRole("textbox", { name: "Search recordings" })).not.toBeInTheDocument();
  });

  it("opens a recording read-only: no rename, delete, project, labels or transcript tools", async () => {
    await renderShared();
    fireEvent.click(within(screen.getByRole("list", { name: "Shared with me" })).getByRole("button", { name: "Open" }));
    expect(await screen.findByRole("heading", { name: "Call with Omar" })).toBeInTheDocument();
    expect(screen.getByTestId("shared-banner")).toHaveTextContent("From Hassam");
    expect(screen.queryByRole("button", { name: "Delete" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Share" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Click to rename" })).not.toBeInTheDocument();
    expect(screen.queryByRole("group", { name: "Organise" })).not.toBeInTheDocument();
    await waitFor(() => expect(screen.getByRole("button", { name: "Copy text" })).toBeInTheDocument());
    expect(screen.queryByRole("toolbar", { name: "Transcript tools" })).not.toBeInTheDocument();
    expect(callsOf("transcript_detail")).toEqual([{ id: "tr-o" }]);
  });

  const openSent = async () => {
    fireEvent.click(within(screen.getByRole("list", { name: "Shared with me" })).getByRole("button", { name: "Open" }));
    await screen.findByText("Hello, this is Sam.");
  };
  const ROMAN = { status: "ready", available: ["roman"], roman: ["Salaam, main Sam hoon.", null, null, null], english: null, urdu: null, model: "claude-opus-5-5", made_at: null, error: null };

  it("offers no suggested fixes and no views to make on a recording a teammate sent (only its owner changes it)", async () => {
    await renderShared();
    respond("transcript_detail", makeTranscript({ id: "tr-o", views: { available: ["roman"], status: "none" },
      suggestions: [{ id: 7, index: 2, from: "invoice", to: "invoices", reason: null, status: "open" }] }));
    await openSent();
    expect(document.querySelector(".suggest-mark")).toBeNull();
    expect(screen.queryByText(/suggested fix/)).not.toBeInTheDocument();
    expect(screen.queryByRole("group", { name: "Show as" })).not.toBeInTheDocument();
  });

  it("shows the views its owner made, only reading them", async () => {
    await renderShared();
    respond("transcript_detail", makeTranscript({ id: "tr-o", views: { available: ["roman"], status: "ready" } }));
    respond("transcript_views", ROMAN);
    await openSent();
    fireEvent.click(within(screen.getByRole("group", { name: "Show as" })).getByRole("button", { name: "Roman Urdu" }));
    expect(await screen.findByText("Salaam, main Sam hoon.")).toBeInTheDocument();
    expect(callsOf("transcript_views")).toEqual([{ id: "tr-o" }]);
    expect(callsOf("transcript_views_make")).toHaveLength(0);
  });

  it("stays on the original words when a view is no longer made, without asking for it", async () => {
    await renderShared();
    respond("transcript_detail", makeTranscript({ id: "tr-o", views: { available: ["roman"], status: "ready" } }));
    respond("transcript_views", { ...ROMAN, status: "none", roman: null });
    await openSent();
    const roman = within(screen.getByRole("group", { name: "Show as" })).getByRole("button", { name: "Roman Urdu" });
    fireEvent.click(roman);
    await waitFor(() => expect(callsOf("transcript_views")).toHaveLength(1));
    await waitFor(() => expect(roman).toHaveAttribute("aria-pressed", "false"));
    expect(screen.getByText("Let us go over the invoice.")).toBeInTheDocument();
    expect(callsOf("transcript_views_make")).toHaveLength(0);
  });

  it("saves a recording to the library and opens the copy", async () => {
    respond("shared_save", { session_id: "a" });
    const props = await renderShared();
    fireEvent.click(within(screen.getAllByRole("listitem")[0]).getByRole("button", { name: "Save to my library" }));
    await waitFor(() => expect(callsOf("shared_save")).toEqual([{ id: "01JDSHARE1" }]));
    await waitFor(() => expect(props.notify).toHaveBeenCalledWith("Saved to your Library"));
    await waitFor(() => expect(props.onSmartFolder).toHaveBeenCalledWith(null));
    expect(screen.getAllByText("Saved to your Library").length).toBeGreaterThan(0);
  });

  it("saves a project and opens it among the projects", async () => {
    respond("shared_save", { client_id: "c-new" });
    const props = await renderShared();
    fireEvent.click(within(screen.getAllByRole("listitem")[1]).getByRole("button", { name: "Save to my library" }));
    await waitFor(() => expect(props.onOpenClient).toHaveBeenCalledWith("c-new"));
    expect(props.notify).toHaveBeenCalledWith("Saved Northwind to your projects");
  });

  it("takes an item off the list", async () => {
    respond("shared_remove", undefined);
    await renderShared();
    fireEvent.click(screen.getByRole("button", { name: "Remove Northwind from Shared with me" }));
    await waitFor(() => expect(callsOf("shared_remove")).toEqual([{ id: "01JDSHARE2" }]));
    await waitFor(() => expect(screen.queryByText("Northwind")).not.toBeInTheDocument());
  });

  it("opens the item an app link points at", async () => {
    await renderShared({ sharedFocus: "01JDSHARE1" });
    expect(await screen.findByRole("heading", { name: "Call with Omar" })).toBeInTheDocument();
  });

  it("says so on a server without it", async () => {
    libraryBackend();
    respond("shared_with_me", null);
    render(<LibraryView notify={vi.fn()} smartFolder="shared" />);
    expect(await screen.findByRole("alert")).toHaveTextContent("This server does not have Shared with me yet.");
  });

  it("writes who sent it and when", () => {
    expect(sentLine({ from: { name: "Hassam", email: "h@x.org" }, sent_at: null })).toBe("From Hassam");
    expect(sentLine({ from: null, sent_at: null })).toBe("From A teammate");
  });
});

describe("the sidebar's smart folders", () => {
  function appBackend() {
    libraryBackend();
    respond("app_status", makeStatus());
    respond("get_settings", makeSettings());
    respond("mic_users", []);
  }

  it("lists the folders with their counts and opens the Library on one", async () => {
    appBackend();
    respond("library_views", { views: { today: 1 }, labels: [], folders: { inbox: 2, whatsapp: 1, zoom: 0, imported: 1, shared: 0 } });
    respondWith("library_folder", ({ folder }) => (folder === "inbox" ? [ITEMS[1], ITEMS[2]] : []));
    render(<MainApp />);
    const folders = await screen.findByRole("list", { name: "Smart folders" });
    // Shared with me is listed with nothing counted in it: a project sent with no recordings counts none.
    expect(within(folders).getAllByRole("button").map((b) => b.textContent)).toEqual(["Inbox2", "WhatsApp1", "Imported1", "Shared with me"]);
    expect(callsOf("library_views")[0].tz).toEqual(expect.any(String));
    fireEvent.click(within(folders).getByRole("button", { name: "Inbox" }));
    expect(await screen.findByRole("heading", { name: "Inbox" })).toBeInTheDocument();
    expect(within(folders).getByRole("button", { name: "Inbox" })).toHaveAttribute("aria-current", "true");
    await waitFor(() => expect(titles()).toEqual(["WhatsApp call", "Imported memo"]));
    fireEvent.click(screen.getByRole("button", { name: "Library" }));
    expect(await screen.findByRole("heading", { name: "Library" })).toBeInTheDocument();
    await waitFor(() => expect(titles()).toEqual(["Zoom with Acme", "WhatsApp call", "Imported memo"]));
  });

  it("opens Shared with me from the command palette with the sidebar collapsed", async () => {
    appBackend();
    respond("library_views", { folders: { inbox: 0, shared: 0 } });
    respond("shared_with_me", { data: SHARED });
    window.localStorage.setItem("echoledger-sidebar", "collapsed");
    render(<MainApp />);
    await screen.findByRole("heading", { name: "Library" });
    await flushAsync();
    expect(screen.queryByRole("list", { name: "Smart folders" })).not.toBeInTheDocument();
    fireEvent.keyDown(window, { key: "k", ctrlKey: true });
    const input = within(await screen.findByRole("dialog", { name: "Command palette" })).getByRole("combobox");
    fireEvent.change(input, { target: { value: "shared with me" } });
    fireEvent.keyDown(input, { key: "Enter" });
    expect(await screen.findByRole("heading", { name: "Shared with me" })).toBeInTheDocument();
    expect(await screen.findByText("Northwind")).toBeInTheDocument();
  });

  it("lists none on a server that counts no folders", async () => {
    appBackend();
    respond("library_views", { views: { today: 1 }, labels: [] });
    render(<MainApp />);
    await screen.findByRole("heading", { name: "Library" });
    await flushAsync();
    expect(callsOf("library_views")).toHaveLength(1);
    expect(screen.queryByRole("list", { name: "Smart folders" })).not.toBeInTheDocument();
  });

  it("counts again shortly after the library changes", async () => {
    appBackend();
    respond("library_views", { folders: { inbox: 2 } });
    render(<MainApp />);
    await screen.findByRole("list", { name: "Smart folders" });
    respond("library_views", { folders: { inbox: 0, zoom: 3 } });
    await emitEvent("library:updated", 1);
    await waitFor(() => expect(within(screen.getByRole("list", { name: "Smart folders" })).getByRole("button", { name: "Zoom" })).toBeInTheDocument(), { timeout: 3000 });
  });
});
