// Sharing (docs/API.md "Share links", "Sending to a teammate", "Request links", since v1.5): the share dialog of a
// recording and of a project (audience, downloads, expiry, the link shown once, the list with views and Revoke), Send to
// a teammate, the v1.1 dialog on an older server, and Request files.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { useState } from "react";
import { ShareDialog } from "./ShareDialog";
import { RequestLinksSheet } from "./RequestLinks";
import { ProjectsView } from "./ProjectsView";
import { LibraryView } from "./LibraryView";
import type { ClientFolder, ShareLink } from "../../lib/ipc";
import { callsOf, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeTranscript } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const LINK: ShareLink = {
  id: "sh1", session_id: "s1", has_password: false, include_audio: true, expires_at: "2026-11-11T10:00:00Z", revoked_at: null, active: true,
  view_count: 3, last_viewed_at: "2026-11-02T10:00:00Z", created_by: "Hassam", created_at: "2026-11-01T10:00:00Z",
  kind: "session", client_id: null, audience: "people", people: ["ana@example.com"], allow_download: true,
};
const OLD_LINK: ShareLink = { ...LINK, id: "sh0", active: false, revoked_at: "2026-11-01T12:00:00Z", audience: "public", people: [], view_count: 0, last_viewed_at: null, allow_download: false, include_audio: false };
const PROJECTS: ClientFolder[] = [{ id: "c1", name: "Acme", parent_id: null }, { id: "c3", name: "Invoices", parent_id: "c1" }];
const writeText = vi.fn((_text: string) => Promise.resolve());
const session = { kind: "session" as const, id: "s1", title: "Kick-off", hasAudio: true };

beforeEach(() => {
  resetTauriMock();
  writeText.mockClear();
  Object.defineProperty(navigator, "clipboard", { configurable: true, value: { writeText } });
});
afterEach(async () => {
  await flushAsync();
  vi.restoreAllMocks();
});

async function renderShare(target: Parameters<typeof ShareDialog>[0]["target"] = session, links: unknown = { data: [LINK, OLD_LINK] }, start: "link" | "send" = "link") {
  respond("shares_of", links);
  const props = { notify: vi.fn(), onClose: vi.fn() };
  render(<ShareDialog target={target} start={start} {...props} />);
  await flushAsync();
  return props;
}

describe("the share dialog", () => {
  it("lists the links with who may open them, their views and Revoke", async () => {
    respond("share_revoke", undefined);
    await renderShare();
    expect(screen.getByRole("dialog", { name: "Share Kick-off" })).toBeInTheDocument();
    expect(callsOf("shares_of")).toEqual([{ sessionId: "s1", clientId: null }]);
    const rows = within(screen.getByRole("list", { name: "Share links" })).getAllByRole("listitem");
    expect(rows[0]).toHaveTextContent("Only ana@example.com");
    expect(rows[0]).toHaveTextContent("Downloads allowed");
    expect(rows[0]).toHaveTextContent(/3 views · last viewed/);
    expect(rows[1]).toHaveTextContent("Revoked");
    expect(rows[1]).toHaveTextContent("Not viewed yet");
    expect(within(rows[1]).queryByRole("button", { name: "Revoke" })).not.toBeInTheDocument();
    fireEvent.click(within(rows[0]).getByRole("button", { name: "Revoke" }));
    await waitFor(() => expect(callsOf("share_revoke")).toEqual([{ id: "sh1" }]));
    await waitFor(() => expect(callsOf("shares_of")).toHaveLength(2));
  });

  it("makes a link anyone can open, with downloads and an expiry, and shows it once to copy", async () => {
    respond("share_link_create", { ...LINK, id: "sh2", url: "https://ledger.example.test/s/tok" });
    const props = await renderShare(session, { data: [] });
    expect(screen.getByText("No links yet.")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("switch", { name: "Allow downloads" }));
    fireEvent.change(screen.getByRole("combobox", { name: "Stops working" }), { target: { value: "day" } });
    const before = Date.now();
    fireEvent.click(screen.getByRole("button", { name: "Make link" }));
    await waitFor(() => expect(callsOf("share_link_create")).toHaveLength(1));
    const sent = callsOf("share_link_create")[0] as { kind: string; id: string; options: Record<string, unknown> };
    expect(sent.kind).toBe("session");
    expect(sent.id).toBe("s1");
    expect(sent.options).toMatchObject({ audience: "public", password: null, people: [], allowDownload: true, view: null });
    const expires = Date.parse(String(sent.options.expiresAt));
    expect(expires - before).toBeGreaterThanOrEqual(86_400_000 - 1000);
    expect(expires - before).toBeLessThan(86_400_000 + 60_000);
    const made = await screen.findByRole("status");
    expect(made).toHaveTextContent("Copy it now. It is shown only once.");
    fireEvent.click(within(made).getByRole("button", { name: "Copy link" }));
    await waitFor(() => expect(writeText).toHaveBeenCalledWith("https://ledger.example.test/s/tok"));
    expect(props.notify).toHaveBeenCalledWith("Link copied");
  });

  it("needs a password of six characters for a password link, and never sends it as a person", async () => {
    respond("share_link_create", { ...LINK, url: "https://ledger.example.test/s/p" });
    await renderShare(session, { data: [] });
    fireEvent.click(screen.getByRole("radio", { name: "Anyone with the link and a password" }));
    fireEvent.change(screen.getByLabelText("Password"), { target: { value: "12345" } });
    expect(screen.getByRole("button", { name: "Make link" })).toBeDisabled();
    fireEvent.change(screen.getByLabelText("Password"), { target: { value: "sesame-42" } });
    fireEvent.change(screen.getByRole("combobox", { name: "Stops working" }), { target: { value: "never" } });
    fireEvent.click(screen.getByRole("button", { name: "Make link" }));
    await waitFor(() => expect(callsOf("share_link_create")).toHaveLength(1));
    expect((callsOf("share_link_create")[0] as { options: unknown }).options).toEqual({ audience: "password", password: "sesame-42", people: [], allowDownload: false, expiresAt: null, view: null });
  });

  it("names the people who may open it and says which typed text is not an email", async () => {
    respond("share_link_create", { ...LINK, url: "https://ledger.example.test/s/q" });
    await renderShare(session, { data: [] });
    fireEvent.click(screen.getByRole("radio", { name: "Only people I choose" }));
    expect(screen.getByRole("button", { name: "Make link" })).toBeDisabled();
    fireEvent.change(screen.getByLabelText("Their emails"), { target: { value: "ana@example.com, bo at x" } });
    expect(screen.getByText("Not an email: bo, at, x")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Make link" })).toBeDisabled();
    fireEvent.change(screen.getByLabelText("Their emails"), { target: { value: "ana@example.com, bo@x.org" } });
    fireEvent.click(screen.getByRole("button", { name: "Make link" }));
    await waitFor(() => expect(callsOf("share_link_create")).toHaveLength(1));
    expect((callsOf("share_link_create")[0] as { options: { people: string[]; audience: string } }).options).toMatchObject({ audience: "people", people: ["ana@example.com", "bo@x.org"] });
  });

  it("needs a day for an expiry on a date", async () => {
    await renderShare(session, { data: [] });
    fireEvent.change(screen.getByRole("combobox", { name: "Stops working" }), { target: { value: "date" } });
    expect(screen.getByRole("button", { name: "Make link" })).toBeDisabled();
    fireEvent.change(screen.getByLabelText("Day"), { target: { value: "2026-12-01" } });
    expect(screen.getByRole("button", { name: "Make link" })).toBeEnabled();
  });

  it("shows the core's refusal in the dialog", async () => {
    fail("share_link_create", "Pick a time in the future for the link to stop working.");
    await renderShare(session, { data: [] });
    fireEvent.click(screen.getByRole("button", { name: "Make link" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("Pick a time in the future for the link to stop working.");
  });

  it("offers the v1.1 link of a recording on an older server", async () => {
    respond("share_list", { data: [] });
    await renderShare(session, null);
    expect(screen.getByText("Share a view-only link")).toBeInTheDocument();
    expect(screen.queryByRole("radio", { name: "Only people I choose" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Send to a teammate" })).not.toBeInTheDocument();
    expect(callsOf("share_list")).toEqual([{ sessionId: "s1" }]);
  });

  it("shares a project with its own links", async () => {
    respond("share_link_create", { ...LINK, kind: "project", url: "https://ledger.example.test/p/tok" });
    await renderShare({ kind: "project", id: "c1", name: "Acme" }, { data: [] });
    expect(callsOf("shares_of")).toEqual([{ sessionId: null, clientId: "c1" }]);
    fireEvent.click(screen.getByRole("button", { name: "Make link" }));
    await waitFor(() => expect((callsOf("share_link_create")[0] as { kind: string; id: string }).kind).toBe("project"));
    expect(await screen.findByText("https://ledger.example.test/p/tok")).toBeInTheDocument();
  });
});

describe("send to a teammate", () => {
  it("sends with a note and closes", async () => {
    respond("send_to_teammate", 1);
    const props = await renderShare(session, { data: [] }, "send");
    expect(screen.getByRole("button", { name: "Send to a teammate" })).toHaveAttribute("aria-pressed", "true");
    fireEvent.change(screen.getByLabelText("Their emails"), { target: { value: "ana@example.com" } });
    fireEvent.change(screen.getByLabelText("Note (optional)"), { target: { value: " for tomorrow " } });
    fireEvent.click(screen.getByRole("button", { name: "Send" }));
    await waitFor(() => expect(callsOf("send_to_teammate")).toEqual([{ kind: "session", id: "s1", to: ["ana@example.com"], note: "for tomorrow" }]));
    await waitFor(() => expect(props.notify).toHaveBeenCalledWith("Sent to ana@example.com"));
    expect(props.onClose).toHaveBeenCalled();
  });

  it("says in plain words that an address has no account, and sends nothing", async () => {
    fail("send_to_teammate", "There is no account for bo@x.org on this server, so nothing was sent. Check the address.");
    const props = await renderShare({ kind: "project", id: "c1", name: "Acme" }, { data: [] }, "send");
    fireEvent.change(screen.getByLabelText("Their emails"), { target: { value: "bo@x.org" } });
    fireEvent.click(screen.getByRole("button", { name: "Send" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("There is no account for bo@x.org on this server, so nothing was sent.");
    expect(props.onClose).not.toHaveBeenCalled();
  });
});

describe("request files", () => {
  const REQUEST = { id: "rq1", title: "Send me your voice notes", client_id: "c3", expires_at: null, max_files: 20, max_mb: 100, received: 4, revoked_at: null };

  it("makes a link into the Inbox with limits, shows it once and lists what each received", async () => {
    respondWith("request_links", () => ({ data: [REQUEST] }));
    respond("request_link_create", { data: { ...REQUEST, id: "rq2", url: "https://ledger.example.test/r/tok", client_id: null, received: 0 } });
    render(<RequestLinksSheet projects={PROJECTS} notify={vi.fn()} onClose={vi.fn()} />);
    const list = await screen.findByRole("list", { name: "Request links" });
    expect(within(list).getByRole("listitem")).toHaveTextContent("Acme › Invoices · 4 files received · Up to 20 files of 100 MB");
    expect(screen.getByLabelText("Files go to")).toHaveValue("");
    const before = Date.now();
    fireEvent.click(screen.getByRole("button", { name: "Make link" }));
    await waitFor(() => expect(callsOf("request_link_create")).toHaveLength(1));
    const options = (callsOf("request_link_create")[0] as { options: Record<string, unknown> }).options;
    expect(options).toMatchObject({ title: "Send me your voice notes", clientId: null, maxFiles: 20, maxMb: 100 });
    expect(Date.parse(String(options.expiresAt)) - before).toBeGreaterThanOrEqual(7 * 86_400_000 - 1000);
    expect(await screen.findByText("https://ledger.example.test/r/tok")).toBeInTheDocument();
  });

  it("sends into the project it was opened from, with no limits when the fields are empty", async () => {
    respond("request_links", { data: [] });
    respond("request_link_create", { data: { ...REQUEST, url: "https://ledger.example.test/r/x" } });
    render(<RequestLinksSheet projects={PROJECTS} projectId="c3" notify={vi.fn()} onClose={vi.fn()} />);
    await screen.findByText("No request links yet.");
    expect(screen.getByLabelText("Files go to")).toHaveValue("c3");
    fireEvent.change(screen.getByLabelText("At most"), { target: { value: "" } });
    fireEvent.change(screen.getByLabelText("Largest file in MB"), { target: { value: "" } });
    fireEvent.change(screen.getByRole("combobox", { name: "Stops working" }), { target: { value: "never" } });
    fireEvent.click(screen.getByRole("button", { name: "Make link" }));
    await waitFor(() => expect(callsOf("request_link_create")).toEqual([{ options: { title: "Send me your voice notes", clientId: "c3", expiresAt: null, maxFiles: null, maxMb: null } }]));
  });

  it("revokes a link", async () => {
    respond("request_links", { data: [REQUEST] });
    respond("request_link_revoke", undefined);
    const notify = vi.fn();
    render(<RequestLinksSheet projects={PROJECTS} notify={notify} onClose={vi.fn()} />);
    fireEvent.click(await screen.findByRole("button", { name: "Revoke" }));
    await waitFor(() => expect(callsOf("request_link_revoke")).toEqual([{ id: "rq1" }]));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Request link revoked: it stops taking files at once"));
  });
});

describe("sharing from the pages", () => {
  function Harness({ initial }: { initial: string | null }) {
    const [projectId, setProjectId] = useState<string | null>(initial);
    return <ProjectsView notify={vi.fn()} projects={PROJECTS} onReload={vi.fn()} onChanged={vi.fn()} projectId={projectId} onOpenProject={setProjectId} onOpenRecording={vi.fn()} expanded={new Set()} onToggle={vi.fn()} />;
  }

  it("offers Request files on the Projects page only when the server has request links", async () => {
    respond("library_list", []);
    respond("request_links", null);
    const { unmount } = render(<Harness initial={null} />);
    await flushAsync();
    expect(screen.queryByRole("button", { name: "Request files" })).not.toBeInTheDocument();
    unmount();
    respond("request_links", { data: [] });
    render(<Harness initial={null} />);
    fireEvent.click(await screen.findByRole("button", { name: "Request files" }));
    expect(await screen.findByRole("dialog", { name: "Request files" })).toBeInTheDocument();
  });

  it("shares a project from its page and requests files into it from its menu", async () => {
    respond("library_list", []);
    respond("request_links", { data: [] });
    respond("shares_of", { data: [] });
    respond("client_workspace", { data: { client: { id: "c3", name: "Invoices", ai_notes: true }, ai: { configured: true, spent_usd: 0, budget_usd: 5, client_spent_usd: 0 }, brief: null, memory: [], notes: [], files: [] } });
    render(<Harness initial="c3" />);
    await flushAsync();
    fireEvent.click(screen.getByRole("button", { name: "Share" }));
    expect(await screen.findByRole("dialog", { name: "Share Invoices" })).toBeInTheDocument();
    expect(callsOf("shares_of")).toEqual([{ sessionId: null, clientId: "c3" }]);
    fireEvent.click(screen.getByRole("button", { name: "Close" }));
    fireEvent.click(screen.getByRole("button", { name: "Project menu" }));
    expect(screen.getAllByRole("menuitem").map((m) => m.textContent)).toEqual(["New folder inside", "Rename", "Move…", "Share link…", "Send to a teammate…", "Request files into it…", "Delete project…"]);
    fireEvent.click(screen.getByRole("menuitem", { name: "Request files into it…" }));
    expect(await screen.findByLabelText("Files go to")).toHaveValue("c3");
  });

  it("opens the share dialog from a recording's page", async () => {
    respond("library_list", [makeItem("s1", { title: "Kick-off" })]);
    respond("library_refresh", [makeItem("s1", { title: "Kick-off" })]);
    respond("pending_list", []);
    respond("upload_list", []);
    respond("unsure_markers", null);
    respond("list_clients", { data: [] });
    respond("transcript_detail", makeTranscript({ id: "tr-s1" }));
    respond("session_detail", { playback_url: null, markers: [], speaker_names: {} });
    respond("shares_of", { data: [] });
    render(<LibraryView notify={vi.fn()} initialSelected="s1" />);
    fireEvent.click(await screen.findByRole("button", { name: "Share" }));
    expect(await screen.findByRole("dialog", { name: "Share Kick-off" })).toBeInTheDocument();
    expect(callsOf("shares_of")).toEqual([{ sessionId: "s1", clientId: null }]);
  });
});
