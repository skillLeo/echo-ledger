// Shared in the new design (SharedV2): the same page, rules and commands as today's Shared, and the Library's Shared
// with me (Open read-only, Save to my library, Remove).
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { useState } from "react";
import { callsOf, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeTranscript } from "../../test/fixtures";
import { answerConfirm } from "../../test/confirm";
import { answerConfirmForTests } from "../../lib/confirm";
import type { ClientFolder, RequestLink, ShareLink, SharedItem } from "../../lib/ipc";
import type { SharedTab } from "../../views/main/Sidebar";
import { SharedV2 } from "./SharedV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOW = new Date(2026, 9, 28, 15, 0);
const URDU = "اکمی کے ساتھ کال";

const sent = (id: string, overrides: Partial<SharedItem> = {}): SharedItem => ({
  id, kind: "session", from: { name: "Imran", email: "imran@acme.pk" }, note: null, title: null,
  sent_at: new Date(2026, 9, 27, 16, 5).toISOString(), saved_at: null, session: makeItem(`s-${id}`, { title: `Call ${id}` }), client: null, ...overrides,
});
const project = (id: string, name: string): ClientFolder => ({ id, name } as ClientFolder);
const shareLink = (id: string, overrides: Partial<ShareLink> = {}): ShareLink => ({
  id, session_id: "rec-1", has_password: false, include_audio: true, expires_at: null, revoked_at: null, active: true, view_count: 0,
  last_viewed_at: null, created_by: null, created_at: NOW.toISOString(), audience: "anyone", ...overrides,
});
const requestLink = (id: string, overrides: Partial<RequestLink> = {}): RequestLink => ({
  id, title: "Send me your voice notes", client_id: null, expires_at: null, max_files: 20, max_mb: 100, received: 0, revoked_at: null, ...overrides,
} as RequestLink);

const WITH_ME = [
  sent("a", { note: "Please check the totals" }),
  sent("b", { title: URDU, saved_at: NOW.toISOString(), from: { email: "sara@acme.pk" } }),
  sent("p", { kind: "project", session: null, title: "Acme", client: project("c9", "Acme") }),
];
const LINKS = [
  shareLink("l1", { view_count: 3, last_viewed_at: new Date(2026, 9, 28, 9, 0).toISOString(), expires_at: new Date(2026, 10, 4, 9, 0).toISOString() }),
  shareLink("l2", { session_id: null, client_id: "c1", kind: "project", audience: "people", people: ["omar@acme.pk"] }),
  shareLink("l3", { session_id: "gone", active: false, revoked_at: NOW.toISOString() }),
];
const REQUESTS = [
  requestLink("r1", { client_id: "c1", received: 4 }),
  requestLink("r2", { title: "Old drop", revoked_at: NOW.toISOString(), max_files: null, max_mb: null }),
];

beforeEach(() => {
  resetTauriMock();
  vi.useFakeTimers({ toFake: ["Date"], now: NOW });
  respond("shared_with_me", { data: WITH_ME });
  respond("shares_of", { data: LINKS });
  respond("request_links", { data: REQUESTS });
  respond("library_list", [makeItem("rec-1", { title: "Acme kickoff" })]);
  respond("shared_save", { session_id: "copy-1" });
  respond("shared_remove", undefined);
  respond("share_revoke", undefined);
  respond("request_link_revoke", undefined);
  respond("session_detail", { playback_url: "https://audio.test/a.m4a", speaker_names: { Client: "Imran" } });
  respond("transcript_detail", makeTranscript());
});
afterEach(() => {
  vi.useRealTimers();
  answerConfirmForTests(null);
});

function Page({ start = "with-me", focusId = null, props }: { start?: SharedTab; focusId?: string | null; props: ReturnType<typeof makeProps> }) {
  const [tab, setTab] = useState<SharedTab>(start);
  return <div className="el-root"><SharedV2 {...props} tab={tab} onTab={(t) => { setTab(t); props.onTab(t); }} focusId={focusId} projects={[project("c1", "Acme Ltd")]} /></div>;
}
const makeProps = () => ({ notify: vi.fn(), onOpenRecording: vi.fn(), onOpenProject: vi.fn(), onSaved: vi.fn(), onTab: vi.fn() });
async function show(start: SharedTab = "with-me", focusId: string | null = null) {
  const props = makeProps();
  render(<Page start={start} focusId={focusId} props={props} />);
  await act(flushAsync);
  return props;
}
const row = (title: string) => screen.getByText(title).closest("li") as HTMLElement;
const menuOf = async (title: string) => {
  fireEvent.click(within(row(title)).getByRole("button", { name: `More for ${title}` }));
  return screen.getByRole("menu");
};

describe("the v2 Shared page", () => {
  it("has its title, the three tabs With me / My links / Request links with their counts, and switches between them", async () => {
    const { onTab } = await show();
    expect(screen.getByRole("heading", { level: 1, name: "Shared" })).toBeInTheDocument();
    expect(screen.getByText("What teammates sent you, the links you made, and links people use to send you files.")).toBeInTheDocument();
    const tabs = within(screen.getByRole("tablist", { name: "Shared" })).getAllByRole("tab").map((t) => t.textContent);
    expect(tabs).toEqual(["With me · 3", "My links · 2", "Request links · 1"]);
    expect(screen.getByRole("tab", { name: "With me · 3" })).toHaveAttribute("aria-selected", "true");
    fireEvent.click(screen.getByRole("tab", { name: "My links · 2" }));
    expect(onTab).toHaveBeenCalledWith("links");
    expect(screen.getByRole("list", { name: "My links" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("tab", { name: "Request links · 1" }));
    expect(screen.getByRole("list", { name: "Request links" })).toBeInTheDocument();
  });

  it("With me: each row has its sender, date, note and the Saved badge; titles read right-to-left in Urdu", async () => {
    await show();
    const list = screen.getByRole("list", { name: "Shared with me" });
    expect(within(list).getAllByRole("listitem")).toHaveLength(3);
    expect(row("Call a")).toHaveTextContent("Recording from Imran · Yesterday · 4:05 PM");
    expect(row("Call a")).toHaveTextContent("“Please check the totals”");
    expect(row("Call a")).not.toHaveTextContent("Saved to my library");
    expect(row(URDU)).toHaveTextContent("Recording from sara@acme.pk");
    expect(row(URDU)).toHaveTextContent("Saved to my library");
    expect(row("Acme")).toHaveTextContent("Project from Imran");
    expect(screen.getByText(URDU)).toHaveAttribute("dir", "auto");
    expect(within(row(URDU)).queryByRole("button", { name: `Save ${URDU} to my library` })).toBeNull();
  });

  it("With me › Open shows the recording read-only: who sent it, the note, the player and the transcript, and Back", async () => {
    await show();
    fireEvent.click(within(row("Call a")).getByRole("button", { name: "Open Call a" }));
    await act(flushAsync);
    expect(screen.getByRole("heading", { level: 1, name: "Call a" })).toHaveAttribute("dir", "auto");
    expect(screen.getByText("Read-only")).toBeInTheDocument();
    expect(screen.getByRole("status")).toHaveTextContent("“Please check the totals” You can play and read it. Save to my library makes a copy you can change.");
    expect(screen.getByText("From Imran · Yesterday · 4:05 PM · 2:05")).toBeInTheDocument();
    expect(callsOf("session_detail")).toEqual([{ id: "s-a" }]);
    expect(callsOf("transcript_detail")).toEqual([{ id: "tr-s-a" }]);
    expect(screen.getByRole("button", { name: "Play" })).toBeInTheDocument();
    const transcript = screen.getByRole("list", { name: "Transcript" });
    expect(within(transcript).getAllByText("Imran").length).toBeGreaterThan(0);
    expect(within(transcript).getByText("Hello, this is Sam.")).toBeInTheDocument();
    // Nothing that changes it: no editing, no Share, no Rename.
    expect(screen.queryByRole("button", { name: /^(Edit|Share|Rename)$/ })).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Shared" }));
    expect(screen.getByRole("list", { name: "Shared with me" })).toBeInTheDocument();
  });

  it("Save to my library makes a copy, marks the row Saved and says where it went", async () => {
    const { notify, onSaved } = await show();
    fireEvent.click(within(row("Call a")).getByRole("button", { name: "Save Call a to my library" }));
    await act(flushAsync);
    expect(callsOf("shared_save")).toEqual([{ id: "a" }]);
    expect(row("Call a")).toHaveTextContent("Saved to my library");
    expect(within(row("Call a")).queryByRole("button", { name: "Save Call a to my library" })).toBeNull();
    expect(notify).toHaveBeenCalledWith("Saved to your Library", false, { kind: "recording", id: "copy-1" });
    expect(onSaved).toHaveBeenCalledWith({ session_id: "copy-1" }, expect.objectContaining({ id: "a" }));
  });

  it("Save to my library also works from the read-only view, and a project saves to your projects", async () => {
    respond("shared_save", { client_id: "c9" });
    const { notify } = await show();
    fireEvent.click(within(row("Acme")).getByRole("button", { name: "Save Acme to my library" }));
    await act(flushAsync);
    expect(notify).toHaveBeenCalledWith("Saved Acme to your projects", false, { kind: "project", id: "c9" });
    respond("shared_save", { session_id: "copy-1" });
    fireEvent.click(within(row("Call a")).getByRole("button", { name: "Open Call a" }));
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "Save to my library" }));
    await act(flushAsync);
    expect(callsOf("shared_save")).toEqual([{ id: "p" }, { id: "a" }]);
    expect(screen.getByText("Saved to my library")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Save to my library" })).toBeNull();
  });

  it("Remove (last in the ⋮, red) drops the row; the right-click menu is the same", async () => {
    const { notify } = await show();
    const menu = await menuOf("Call a");
    const items = within(menu).getAllByRole("menuitem");
    expect(items.map((i) => i.textContent)).toEqual(["Open", "Save to my library", "Remove"]);
    expect(items[2].className).toMatch(/danger/);
    fireEvent.click(items[2]);
    await act(flushAsync);
    expect(callsOf("shared_remove")).toEqual([{ id: "a" }]);
    expect(screen.queryByText("Call a")).toBeNull();
    expect(notify).toHaveBeenCalledWith("Removed from Shared with me", false, undefined);
    expect(screen.getByRole("tab", { name: "With me · 2" })).toBeInTheDocument();

    fireEvent.contextMenu(row(URDU));
    const context = screen.getByRole("menu");
    expect(within(context).getAllByRole("menuitem").map((i) => i.textContent)).toEqual(["Open", "Remove"]);
    fireEvent.click(within(context).getByRole("menuitem", { name: "Remove" }));
    await act(flushAsync);
    expect(callsOf("shared_remove")).toEqual([{ id: "a" }, { id: "b" }]);
    expect(screen.queryByText(URDU)).toBeNull();
  });

  it("With me: loading skeleton, then the empty state", async () => {
    respondWith("shared_with_me", () => new Promise(() => undefined));
    render(<Page props={makeProps()} />);
    expect(screen.getByRole("status")).toHaveAttribute("aria-busy", "true");
    expect(screen.getByRole("status")).toHaveTextContent("Loading what was shared with you…");
    cleanup();
    respond("shared_with_me", null);
    render(<Page props={makeProps()} />);
    await act(flushAsync);
    expect(screen.queryByRole("status")).toBeNull();
    expect(screen.getByText("Nothing sent to you yet")).toBeInTheDocument();
    expect(screen.getByText("When a teammate sends you a recording or a project, it shows here.")).toBeInTheDocument();
  });

  it("a link to a recording sent to you opens it read-only", async () => {
    await show("with-me", "a");
    expect(screen.getByRole("heading", { level: 1, name: "Call a" })).toBeInTheDocument();
  });

  it("a link to a project sent to you shows it selected in With me", async () => {
    await show("with-me", "p");
    expect(row("Acme").className).toMatch(/el-row--selected/);
  });

  it("My links: Open, state, audience, views and expiry; Revoke asks first and does nothing when declined", async () => {
    const { onOpenRecording, onOpenProject, notify } = await show("links");
    expect(row("Acme kickoff")).toHaveTextContent("Anyone with the link · 3 views · last viewed Today · 9:00 AM");
    expect(row("Acme kickoff")).toHaveTextContent("until Nov 4");
    expect(row("Acme kickoff")).toHaveTextContent("Active");
    expect(row("Project: Acme Ltd")).toHaveTextContent("Only omar@acme.pk · Not viewed yet");
    expect(screen.getByText("A recording").closest("li")).toHaveTextContent("Revoked");
    fireEvent.click(within(row("Acme kickoff")).getByRole("button", { name: "Open Acme kickoff" }));
    expect(onOpenRecording).toHaveBeenCalledWith("rec-1");
    fireEvent.click(within(row("Project: Acme Ltd")).getByRole("button", { name: "Open Project: Acme Ltd" }));
    expect(onOpenProject).toHaveBeenCalledWith("c1");

    const asked = answerConfirm();
    asked.mockReturnValue(false);
    let menu = await menuOf("Acme kickoff");
    const revoke = within(menu).getByRole("menuitem", { name: "Revoke" });
    expect(within(menu).getAllByRole("menuitem").at(-1)).toBe(revoke);
    expect(revoke.className).toMatch(/danger/);
    fireEvent.click(revoke);
    await act(flushAsync);
    expect(asked).toHaveBeenCalledWith("Revoke this link? It stops working for everyone who has it.");
    expect(callsOf("share_revoke")).toHaveLength(0);

    asked.mockReturnValue(true);
    menu = await menuOf("Acme kickoff");
    fireEvent.click(within(menu).getByRole("menuitem", { name: "Revoke" }));
    await act(flushAsync);
    expect(callsOf("share_revoke")).toEqual([{ id: "l1" }]);
    expect(notify).toHaveBeenCalledWith("Link revoked: it stops working at once", false, undefined);
    // A revoked link has nothing to revoke.
    menu = await menuOf("A recording");
    expect(within(menu).getAllByRole("menuitem").map((i) => i.textContent)).toEqual(["Open"]);
  });

  it("Request links: state, into, received, limits; Revoke asks first and does nothing when declined", async () => {
    const { notify } = await show("requests");
    const active = within(screen.getByRole("list", { name: "Request links" })).getAllByRole("listitem")[0];
    expect(active).toHaveTextContent("Send me your voice notes");
    expect(active).toHaveTextContent("Into Acme Ltd · 4 files received · Up to 20 files of 100 MB");
    expect(active).toHaveTextContent("Active");
    expect(active).toHaveTextContent("Never stops");
    expect(row("Old drop")).toHaveTextContent("Into The Inbox · Nothing received yet");
    expect(row("Old drop")).toHaveTextContent("Revoked");
    expect(screen.getByText("Old drop")).toHaveAttribute("dir", "auto");

    const asked = answerConfirm();
    asked.mockReturnValue(false);
    fireEvent.contextMenu(active);
    fireEvent.click(within(screen.getByRole("menu")).getByRole("menuitem", { name: "Revoke" }));
    await act(flushAsync);
    expect(asked).toHaveBeenCalledWith("Revoke this request link? It stops taking files from everyone who has it.");
    expect(callsOf("request_link_revoke")).toHaveLength(0);
    asked.mockReturnValue(true);
    fireEvent.click(within(active).getByRole("button", { name: "More for Send me your voice notes" }));
    fireEvent.click(within(screen.getByRole("menu")).getByRole("menuitem", { name: "Revoke" }));
    await act(flushAsync);
    expect(callsOf("request_link_revoke")).toEqual([{ id: "r1" }]);
    expect(notify).toHaveBeenCalledWith("Request link revoked", false, undefined);
  });

  it("New request link: title, files go to, stops working, limits, Make link, Copy, and Revoke in the sheet", async () => {
    const writeText = vi.fn(() => Promise.resolve());
    Object.assign(navigator, { clipboard: { writeText } });
    respond("request_link_create", { data: requestLink("r9", { url: "https://echo.test/r/abc" }) });
    const { notify } = await show();
    fireEvent.click(screen.getByRole("button", { name: "New request link" }));
    const sheet = screen.getByRole("dialog", { name: "Request files" });
    const title = within(sheet).getByLabelText("Title people see");
    expect(title).toHaveValue("Send me your voice notes");
    expect(title).toHaveAttribute("dir", "auto");
    fireEvent.change(title, { target: { value: "Voice notes for Acme" } });
    fireEvent.click(within(sheet).getByRole("combobox", { name: /^Files go to/ }));
    fireEvent.click(screen.getByRole("option", { name: "Acme Ltd" }));
    fireEvent.click(within(sheet).getByRole("combobox", { name: /^Stops working/ }));
    fireEvent.click(screen.getByRole("option", { name: "In 1 day" }));
    fireEvent.change(within(sheet).getByLabelText("At most"), { target: { value: "500" } });
    expect(within(sheet).getByRole("button", { name: "Make link" })).toBeDisabled();
    expect(sheet).toHaveTextContent("Choose from 1 to 100 files.");
    fireEvent.change(within(sheet).getByLabelText("At most"), { target: { value: "5" } });
    fireEvent.change(within(sheet).getByLabelText("Largest file in MB"), { target: { value: "50" } });
    fireEvent.click(within(sheet).getByRole("button", { name: "Make link" }));
    await act(flushAsync);
    expect(callsOf("request_link_create")).toEqual([{ options: {
      title: "Voice notes for Acme", clientId: "c1", expiresAt: new Date(NOW.getTime() + 86_400_000).toISOString(), maxFiles: 5, maxMb: 50,
    } }]);
    expect(within(sheet).getByText("Copy it now. It is shown only once.")).toBeInTheDocument();
    expect(within(sheet).getByText("https://echo.test/r/abc")).toBeInTheDocument();
    fireEvent.click(within(sheet).getByRole("button", { name: "Copy link" }));
    await act(flushAsync);
    expect(writeText).toHaveBeenCalledWith("https://echo.test/r/abc");
    expect(notify).toHaveBeenCalledWith("Link copied", false, { kind: "shared", tab: "requests" });

    const asked = answerConfirm();
    asked.mockReturnValue(true);
    const inSheet = within(sheet).getByRole("list", { name: "Request links" });
    fireEvent.click(within(inSheet).getAllByRole("button", { name: "More for Send me your voice notes" })[0]);
    fireEvent.click(within(screen.getByRole("menu")).getByRole("menuitem", { name: "Revoke" }));
    await act(flushAsync);
    expect(asked).toHaveBeenCalledWith("Revoke this request link? It stops taking files from everyone who has it.");
    expect(callsOf("request_link_revoke")).toEqual([{ id: "r1" }]);

    fireEvent.click(within(sheet).getAllByRole("button", { name: "Close" }).at(-1)!);
    expect(screen.queryByRole("dialog", { name: "Request files" })).toBeNull();
  });

  it("New request link: On a date needs a day, and a failed Make link says why", async () => {
    fail("request_link_create", "The server is unreachable.");
    await show();
    fireEvent.click(screen.getByRole("button", { name: "New request link" }));
    const sheet = screen.getByRole("dialog", { name: "Request files" });
    fireEvent.click(within(sheet).getByRole("combobox", { name: /^Stops working/ }));
    fireEvent.click(screen.getByRole("option", { name: "On a date…" }));
    expect(within(sheet).getByRole("button", { name: "Make link" })).toBeDisabled();
    fireEvent.change(within(sheet).getByLabelText("Day"), { target: { value: "2026-12-01" } });
    fireEvent.click(within(sheet).getByRole("button", { name: "Make link" }));
    await act(flushAsync);
    expect(within(sheet).getByRole("alert")).toHaveTextContent("The server is unreachable.");
  });

  it("a load error shows the banner with Retry, which reads everything again", async () => {
    fail("shares_of", "The server is unreachable.");
    await show("links");
    expect(screen.getByRole("alert")).toHaveTextContent("Couldn't load everything. The server is unreachable.");
    respond("shares_of", { data: [] });
    fireEvent.click(screen.getByRole("button", { name: "Retry" }));
    await act(flushAsync);
    expect(screen.queryByRole("alert")).toBeNull();
    expect(screen.getByText("No links yet")).toBeInTheDocument();
    expect(callsOf("shared_with_me")).toHaveLength(2);
  });

  it("empty states for My links and Request links", async () => {
    respond("shares_of", { data: [] });
    respond("request_links", null);
    await show("links");
    expect(screen.getByText("Share a recording or a project from its page; the link shows here with its views.")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("tab", { name: "Request links · 0" }));
    expect(screen.getByText("No request links yet")).toBeInTheDocument();
  });
});

