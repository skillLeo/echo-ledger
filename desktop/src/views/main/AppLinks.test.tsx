// Links in the apps (docs/API.md, since v1.5): an echoledger://open link reaches the main window as main:navigate with
// the server link; the window asks the core what it is (GET /api/links/resolve) and opens the recording, the project,
// the item of Shared with me or the request link, or the web page when the app cannot open it in place.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { screen, waitFor } from "@testing-library/react";
import { render } from "@testing-library/react";
import MainApp from "./MainApp";
import { callsOf, emitEvent, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeNavigate, makeSettings, makeStatus, makeTranscript } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const LINK = "https://ledger.example.test/s/AbC123";
const WORKSPACE = { client: { id: "c1", name: "Acme", ai_notes: true }, ai: { configured: true, spent_usd: 0, budget_usd: 5, client_spent_usd: 0 }, brief: null, memory: [], notes: [], files: [] };

function backend() {
  respond("app_status", makeStatus());
  respond("get_settings", makeSettings());
  respond("mic_users", []);
  respond("library_list", [makeItem("s1", { title: "Kick-off" })]);
  respond("library_refresh", [makeItem("s1", { title: "Kick-off" })]);
  respond("list_clients", { data: [{ id: "c1", name: "Acme", parent_id: null }] });
  respond("pending_list", []);
  respond("upload_list", []);
  respond("unsure_markers", null);
  respond("transcript_detail", makeTranscript({ id: "tr-s1" }));
  respond("session_detail", { playback_url: null, markers: [], speaker_names: {} });
  respond("client_workspace", { data: WORKSPACE });
}

async function renderApp() {
  render(<MainApp />);
  await screen.findByRole("heading", { name: "Library" });
  await flushAsync();
}
const open = (link = LINK) => emitEvent("main:navigate", makeNavigate({ link }));

beforeEach(() => {
  resetTauriMock();
  window.localStorage.clear();
  backend();
});
afterEach(async () => {
  await flushAsync();
  vi.restoreAllMocks();
});

describe("app links", () => {
  it("opens a recording of the workspace", async () => {
    respond("links_resolve", { kind: "session", session_id: "s1" });
    await renderApp();
    await open();
    await waitFor(() => expect(callsOf("links_resolve")).toEqual([{ url: LINK }]));
    expect(await screen.findByRole("heading", { name: "Kick-off" })).toBeInTheDocument();
  });

  it("opens a project", async () => {
    respond("links_resolve", { kind: "project", client_id: "c1" });
    await renderApp();
    await open("https://ledger.example.test/p/proj");
    expect(await screen.findByRole("heading", { name: "Acme" })).toBeInTheDocument();
  });

  it("opens an item of Shared with me", async () => {
    respond("links_resolve", { kind: "shared", shared_id: "01JDSHARE1" });
    respond("shared_with_me", { data: [{ id: "01JDSHARE1", kind: "session", from: { name: "Hassam" }, note: null, title: "Call with Omar", sent_at: null, saved_at: null, client: null,
      session: makeItem("other-1", { title: "Call with Omar" }) }] });
    await renderApp();
    await open();
    expect(await screen.findByRole("heading", { name: "Shared with me" })).toBeInTheDocument();
    expect(await screen.findByRole("heading", { name: "Call with Omar" })).toBeInTheDocument();
  });

  it("opens a request link among the request links", async () => {
    respond("links_resolve", { kind: "request", request_link_id: "rq1" });
    respond("request_links", { data: [{ id: "rq1", title: "Voice notes for Acme", client_id: null, expires_at: null, max_files: null, max_mb: null, received: 2, revoked_at: null }] });
    await renderApp();
    await open("https://ledger.example.test/r/tok");
    expect(await screen.findByRole("dialog", { name: "Request files" })).toBeInTheDocument();
    expect(await screen.findByText("Voice notes for Acme")).toBeInTheDocument();
  });

  it("opens the web page when the app cannot open the link in place", async () => {
    respond("links_resolve", null);
    respond("link_open", undefined);
    await renderApp();
    await open();
    await waitFor(() => expect(callsOf("link_open")).toEqual([{ url: LINK }]));
    expect(await screen.findByRole("status")).toHaveTextContent("Opened in your browser");
  });

  it("waits until the app is unlocked", async () => {
    respond("lock_status", { enabled: true, locked: true, hasPin: true, osMethod: null, retryAfterSeconds: 0 });
    respond("links_resolve", { kind: "session", session_id: "s1" });
    render(<MainApp />);
    await flushAsync();
    await open();
    await flushAsync();
    expect(callsOf("links_resolve")).toHaveLength(0);
    await emitEvent("lock:changed", { enabled: true, locked: false, hasPin: true, osMethod: null, retryAfterSeconds: 0 });
    await waitFor(() => expect(callsOf("links_resolve")).toHaveLength(1));
  });

  it("says why a link could not be opened", async () => {
    respondWith("links_resolve", () => Promise.reject("That is not an EchoLedger link."));
    await renderApp();
    await open("https://ledger.example.test/admin");
    expect(await screen.findByRole("status")).toHaveTextContent("That is not an EchoLedger link.");
  });
});
