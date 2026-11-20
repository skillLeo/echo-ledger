// Shared in the new design (SharedV2): the same page, rules and commands as today's Shared, and the Library's Shared
// with me (Open read-only, Save to my library, Remove).
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { useState } from "react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { callsOf, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { CALL_SEGMENTS, makeItem, makeTranscript } from "../../test/fixtures";
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
/** The row's own button: a press (or Enter on it, a native button) opens what the row stands for. */
const rowButton = (title: string) => within(row(title)).getByRole("button", { name: title });
const pick = async (title: string, action: string) => {
  fireEvent.click(within(await menuOf(title)).getByRole("menuitem", { name: action }));
  await act(flushAsync);
};
const menuOf = async (title: string) => {
  fireEvent.click(within(row(title)).getByRole("button", { name: `More for ${title}` }));
  return screen.getByRole("menu");
};

describe("the v2 Shared page", () => {
  it("has its title, the three tabs With me / My links / Request links with their counts, and switches between them", async () => {
    const { onTab } = await show();
    expect(screen.getByRole("heading", { level: 1, name: "Shared" })).toBeInTheDocument();
    // The description finds its own direction, so in Urdu its full stop stays at its end.
    expect(screen.getByText("What teammates sent you, the links you made, and links people use to send you files.")).toHaveAttribute("dir", "auto");
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
    expect(row("Call a")).not.toHaveTextContent("Saved");
    expect(row(URDU)).toHaveTextContent("Recording from sara@acme.pk");
    expect(row(URDU)).toHaveTextContent("Saved");
    expect(row("Acme")).toHaveTextContent("Project from Imran");
    expect(screen.getByText(URDU)).toHaveAttribute("dir", "auto");
    // The time is one left-to-right isolate (.el-ltr, words keep their face), so "4:05 PM" never turns round in Urdu.
    expect(within(row("Call a")).getByText("Yesterday · 4:05 PM")).toHaveClass("el-ltr");
    expect(within(row(URDU)).queryByRole("menuitem", { name: "Save to my library" })).toBeNull();
  });

  it("rows have no hover-only actions: the ⋮ is always there on every tab, and the row itself opens the item", async () => {
    const { onOpenRecording } = await show();
    // The ⋮ is in the row without hovering or focusing it, and the page's style keeps it on screen.
    for (const title of ["Call a", URDU, "Acme"]) expect(within(row(title)).getByRole("button", { name: `More for ${title}` })).toBeInTheDocument();
    expect(within(row("Call a")).queryByRole("button", { name: /^(Open|Save)/ })).toBeNull();
    const css = readFileSync(join(dirname(fileURLToPath(import.meta.url)), "shared.css"), "utf8");
    expect(css).toMatch(/\[data-v2=shared\] \.el-row__actions \{ opacity: 1;/);
    expect(document.querySelector("[data-v2=shared]")).not.toBeNull();
    // The row is one native button (Enter on it presses it): it opens the recording read-only.
    expect(rowButton("Call a").tagName).toBe("BUTTON");
    rowButton("Call a").focus();
    expect(rowButton("Call a")).toHaveFocus();
    fireEvent.click(rowButton("Call a"));
    await act(flushAsync);
    expect(screen.getByRole("heading", { level: 1, name: "Call a" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Shared" }));
    // A project sent to you has nothing to open until it is saved: its row opens its menu.
    fireEvent.click(rowButton("Acme"));
    expect(within(screen.getByRole("menu")).getAllByRole("menuitem").map((i) => i.textContent)).toEqual(["Save to my library", "Remove"]);
    fireEvent.keyDown(screen.getByRole("menu"), { key: "Escape" });

    fireEvent.click(screen.getByRole("tab", { name: "My links · 2" }));
    expect(within(row("Acme kickoff")).getByRole("button", { name: "More for Acme kickoff" })).toBeInTheDocument();
    fireEvent.click(rowButton("Acme kickoff"));
    expect(onOpenRecording).toHaveBeenCalledWith("rec-1");
    fireEvent.click(screen.getByRole("tab", { name: "Request links · 1" }));
    expect(within(screen.getAllByText("Send me your voice notes")[0].closest("li") as HTMLElement).getByRole("button", { name: "More for Send me your voice notes" })).toBeInTheDocument();
  });

  it("With me › Open shows the recording read-only: who sent it, the note, the player and the transcript, and Back", async () => {
    await show();
    await pick("Call a", "Open");
    expect(screen.getByRole("heading", { level: 1, name: "Call a" })).toHaveAttribute("dir", "auto");
    expect(screen.getByText("Read-only")).toBeInTheDocument();
    expect(screen.getByTestId("reader-sent")).toHaveTextContent("From Imran · Yesterday · 4:05 PM · “Please check the totals” You can play and read it. Save to my library makes a copy you can change.");
    expect(screen.getByText("You can play and read it. Save to my library makes a copy you can change.")).toHaveAttribute("dir", "auto");
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
    await pick("Call a", "Save to my library");
    expect(callsOf("shared_save")).toEqual([{ id: "a" }]);
    expect(row("Call a")).toHaveTextContent("Saved");
    expect(within(await menuOf("Call a")).queryByRole("menuitem", { name: "Save to my library" })).toBeNull();
    expect(notify).toHaveBeenCalledWith("Saved to your Library", false, { kind: "recording", id: "copy-1" });
    expect(onSaved).toHaveBeenCalledWith({ session_id: "copy-1" }, expect.objectContaining({ id: "a" }));
  });

  it("Save to my library also works from the read-only view, and a project saves to your projects", async () => {
    respond("shared_save", { client_id: "c9" });
    const { notify } = await show();
    await pick("Acme", "Save to my library");
    expect(notify).toHaveBeenCalledWith("Saved Acme to your projects", false, { kind: "project", id: "c9" });
    respond("shared_save", { session_id: "copy-1" });
    fireEvent.click(rowButton("Call a"));
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
    expect(within(row("Acme kickoff")).getByText("Today · 9:00 AM")).toHaveClass("el-ltr");
    fireEvent.click(rowButton("Acme kickoff"));
    expect(onOpenRecording).toHaveBeenCalledWith("rec-1");
    await pick("Project: Acme Ltd", "Open");
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

// The read-only view keeps every control classic's Library keeps for a recording a teammate sent (LibraryView with
// readOnly): each one below, one test each.
describe("a shared recording, open read-only", () => {
  /** Opens "Call a" (a 8:06 recording from Zoom on a computer, in project Acme Ltd) from With me. */
  async function openReader(session: Partial<SharedItem["session"] & object> = {}) {
    respond("shared_with_me", { data: [sent("a", {
      note: "Please check the totals",
      session: makeItem("s-a", { title: "Call a", duration_ms: 486_000, source_app_label: "Zoom", source_device_label: "Desktop",
        client: { id: "c1", name: "Acme Ltd" } as ClientFolder, ...session } as never),
    })] });
    const props = await show();
    fireEvent.click(rowButton("Call a"));
    await act(flushAsync);
    return props;
  }
  const player = () => screen.getByTestId("player") as HTMLAudioElement;

  it("head: the source and device, the title, when it was recorded with its length and project, and Transcribed in", async () => {
    await openReader({ transcript: { final_status: "completed", final_transcript_id: "tr-s-a", live_available: false, preview: null, final_language: "ur" } as never });
    expect(screen.getByTestId("source-line")).toHaveTextContent("Zoom · Desktop");
    expect(screen.getByTestId("source-line")).toHaveAttribute("dir", "auto");
    const recorded = screen.getByTestId("recorded");
    expect(recorded).toHaveTextContent(/^Oct 24 · .+ · 8:06 · Acme Ltd$/);
    expect(within(recorded).getByText("8:06")).toHaveClass("el-num");
    expect(screen.getByText("Transcribed in Urdu")).toHaveAttribute("dir", "auto");
  });

  it("head: the wrong-script note, and the retry line while it waits to be transcribed", async () => {
    respond("transcript_detail", makeTranscript({ status: "pending", segments: [], retry_at: new Date(2026, 9, 28, 16, 30).toISOString() }));
    await openReader({ transcript: { final_status: "pending", final_transcript_id: "tr-s-a", live_available: false, preview: null, script_warning: "latin" } as never });
    expect(screen.getByText("Waiting to transcribe — retrying at 4:30 pm")).toBeInTheDocument();
    expect(screen.getByText(/^Wrong script detected: Urdu written in English letters/)).toBeInTheDocument();
    expect(within(screen.getByRole("region", { name: "Transcribing" })).getByRole("heading", { name: "Transcribing" })).toBeInTheDocument();
  });

  it("player: the length is the recording's (8:06) when the audio file says 1 s or nothing yet", async () => {
    respond("transcript_detail", makeTranscript({ segments: CALL_SEGMENTS.slice(0, 2) }));
    await openReader();
    const slider = screen.getByRole("slider", { name: "Position" });
    expect(slider).toHaveAttribute("aria-valuetext", "0:00 of 8:06");
    Object.defineProperty(player(), "duration", { value: 1, configurable: true });
    fireEvent.loadedMetadata(player());
    expect(slider).toHaveAttribute("aria-valuetext", "0:00 of 8:06");
    Object.defineProperty(player(), "duration", { value: Number.NaN, configurable: true });
    fireEvent.durationChange(player());
    expect(slider).toHaveAttribute("aria-valuetext", "0:00 of 8:06");
  });

  it("player: play and pause, back and forward 10 s, speed, Skip silence, Follow and the keys", async () => {
    const play = vi.fn(() => Promise.resolve());
    const pause = vi.fn();
    Object.assign(HTMLMediaElement.prototype, { play, pause });
    await openReader();
    fireEvent.click(screen.getByRole("button", { name: "Play" }));
    expect(play).toHaveBeenCalled();
    fireEvent.click(screen.getByRole("button", { name: "Forward 10 seconds" }));
    expect(screen.getByRole("slider", { name: "Position" })).toHaveAttribute("aria-valuetext", expect.stringMatching(/^0:10 of/));
    fireEvent.click(screen.getByRole("button", { name: "Back 10 seconds" }));
    expect(screen.getByRole("slider", { name: "Position" })).toHaveAttribute("aria-valuetext", expect.stringMatching(/^0:00 of/));
    fireEvent.click(screen.getByRole("combobox", { name: /^Playback speed/ }));
    fireEvent.click(screen.getByRole("option", { name: "1.5×" }));
    expect(player().playbackRate).toBe(1.5);
    const skip = screen.getByRole("switch", { name: "Skip silence" });
    expect(skip).toHaveAttribute("aria-checked", "false");
    fireEvent.click(skip);
    expect(skip).toHaveAttribute("aria-checked", "true");
    const follow = screen.getByRole("switch", { name: "Follow" });
    expect(follow).toHaveAttribute("aria-checked", "true");
    fireEvent.click(follow);
    expect(follow).toHaveAttribute("aria-checked", "false");
    expect(screen.getByText(/plays · /)).toBeInTheDocument();
    // Space plays or pauses (the audio is paused in jsdom, so it plays).
    play.mockClear();
    fireEvent.keyDown(document.body, { key: " " });
    expect(play).toHaveBeenCalled();
  });

  it("player: the bookmarks play from their moment", async () => {
    respond("session_detail", { playback_url: "https://audio.test/a.m4a", speaker_names: {}, markers: [{ id: "m1", at_ms: 65_000, tag: "Invoice", note: "Totals" }] });
    Object.assign(HTMLMediaElement.prototype, { play: vi.fn(() => Promise.resolve()), pause: vi.fn() });
    await openReader();
    const marks = screen.getByRole("list", { name: "Bookmarks" });
    fireEvent.click(within(marks).getByRole("button", { name: "1:05 Invoice" }));
    expect(screen.getByRole("slider", { name: "Position" })).toHaveAttribute("aria-valuetext", expect.stringMatching(/^1:05 of/));
  });

  it("player: an audio link that ran out is asked for again once, then it says so", async () => {
    await openReader();
    respond("session_detail", { playback_url: "https://audio.test/fresh.m4a" });
    fireEvent.error(player());
    await act(flushAsync);
    expect(player()).toHaveAttribute("src", "https://audio.test/fresh.m4a");
    fireEvent.error(player());
    await act(flushAsync);
    expect(screen.getByText("Playback link expired. Open the recording again.")).toBeInTheDocument();
  });

  it("transcript: its kind line, and Copy ▾ with Copy text, Copy with timestamps and Copy for AI", async () => {
    const writeText = vi.fn(() => Promise.resolve());
    Object.assign(navigator, { clipboard: { writeText } });
    const { notify } = await openReader();
    const bar = screen.getByRole("toolbar", { name: "Find and copy" });
    expect(bar).toHaveTextContent("Final transcript");
    fireEvent.click(within(bar).getByRole("button", { name: "Copy" }));
    expect(within(screen.getByRole("menu")).getAllByRole("menuitem").map((i) => i.textContent)).toEqual(["Copy text", "Copy with timestamps", "Copy for AI"]);
    fireEvent.click(screen.getByRole("menuitem", { name: "Copy text" }));
    await act(flushAsync);
    expect(writeText).toHaveBeenLastCalledWith(expect.stringContaining("Hello, this is Sam."));
    expect(notify).toHaveBeenLastCalledWith("Copied", false, undefined);
    fireEvent.click(within(bar).getByRole("button", { name: "Copy" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Copy with timestamps" }));
    await act(flushAsync);
    expect(writeText).toHaveBeenLastCalledWith(expect.stringContaining("[0:12] Imran: Hi Sam, thanks for calling back."));
    expect(notify).toHaveBeenLastCalledWith("Copied with timestamps", false, undefined);
    fireEvent.click(within(bar).getByRole("button", { name: "Copy" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Copy for AI" }));
    await act(flushAsync);
    expect(writeText).toHaveBeenLastCalledWith(expect.stringMatching(/^# Call a\n/));
    expect(notify).toHaveBeenLastCalledWith("Copied for AI, with the note on unsure words", false, undefined);
  });

  it("transcript: an edited final transcript says so, and live captions say the final one is pending", async () => {
    respond("transcript_detail", makeTranscript({ edited_at: NOW.toISOString() }));
    await openReader();
    expect(screen.getByRole("toolbar", { name: "Find and copy" })).toHaveTextContent("Final transcript · edited");
    fireEvent.click(screen.getByRole("button", { name: "Shared" }));
    respond("transcript_detail", makeTranscript({ kind: "live" }));
    fireEvent.click(rowButton("Call a"));
    await act(flushAsync);
    expect(screen.getByRole("toolbar", { name: "Find and copy" })).toHaveTextContent("Live captions (final transcript pending)");
  });

  it("transcript: Find (the button and Cmd/Ctrl+F) marks every match and steps through them", async () => {
    await openReader();
    fireEvent.keyDown(document.body, { key: "f", ctrlKey: true });
    const find = screen.getByRole("search", { name: "Find in this transcript" });
    fireEvent.change(within(find).getByRole("searchbox"), { target: { value: "sam" } });
    expect(within(find).getByRole("status")).toHaveTextContent("1 of 2");
    expect(document.querySelectorAll("mark.el-find")).toHaveLength(2);
    fireEvent.click(within(find).getByRole("button", { name: "Next match" }));
    expect(within(find).getByRole("status")).toHaveTextContent("2 of 2");
    fireEvent.click(within(find).getByRole("button", { name: "Close find" }));
    expect(screen.queryByRole("search")).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Find" }));
    expect(screen.getByRole("search", { name: "Find in this transcript" })).toBeInTheDocument();
  });

  it("transcript: Show as shows the owner's ready views (never makes one), and only when they are ready", async () => {
    respond("transcript_detail", makeTranscript({ views: { available: ["roman"], status: "ready" } }));
    respond("transcript_views", { status: "ready", available: ["roman"], roman: ["Salam, main Sam hoon.", "Hi Sam, thanks for calling back.", null, null], english: null, model: null, made_at: null, error: null, kept: { roman: [1] } });
    await openReader();
    const showAs = screen.getByRole("radiogroup", { name: "Show as" });
    expect(within(showAs).getAllByRole("radio").map((r) => r.textContent)).toEqual(["Roman Urdu", "Original"]);
    fireEvent.click(within(showAs).getByRole("radio", { name: "Roman Urdu" }));
    await act(flushAsync);
    expect(screen.getByText("Salam, main Sam hoon.")).toBeInTheDocument();
    expect(screen.getByText("Roman Urdu view. The transcript itself is unchanged.")).toBeInTheDocument();
    expect(screen.getByText("· original words")).toBeInTheDocument();
    expect(callsOf("transcript_views_make")).toHaveLength(0);

    fireEvent.click(screen.getByRole("button", { name: "Shared" }));
    respond("transcript_detail", makeTranscript({ views: { available: ["roman"], status: "none" } }));
    fireEvent.click(rowButton("Call a"));
    await act(flushAsync);
    expect(screen.queryByRole("radiogroup", { name: "Show as" })).toBeNull();
  });

  it("transcript: what a second transcription heard shows under its line", async () => {
    respond("transcript_detail", makeTranscript({ segments: [{ ...CALL_SEGMENTS[0], check: { provider: "groq", label: "Groq Whisper", text: "Hello, this is Tam." } }] }));
    await openReader();
    expect(screen.getByText("A second transcription heard: Hello, this is Tam.")).toBeInTheDocument();
    expect(screen.queryByText(/Groq/)).toBeNull();
  });

  it("transcript: failed for want of a Groq key offers Add my Groq key (Check my Groq key when refused), as classic", async () => {
    const opened: string[] = [];
    const listen = (e: Event) => opened.push((e as CustomEvent<string>).detail);
    window.addEventListener("echoledger:open-settings-section", listen);
    try {
      respond("transcript_detail", makeTranscript({ status: "failed", error_code: "needs_transcription_key", segments: [] }));
      await openReader();
      fireEvent.click(screen.getByRole("button", { name: "Add my Groq key" }));
      expect(opened).toEqual(["transcription-key"]);
      fireEvent.click(screen.getByRole("button", { name: "Shared" }));
      respond("transcript_detail", makeTranscript({ status: "failed", error_code: "own_key_rejected", segments: [] }));
      fireEvent.click(rowButton("Call a"));
      await act(flushAsync);
      expect(screen.getByRole("button", { name: "Check my Groq key" })).toBeInTheDocument();
    } finally {
      window.removeEventListener("echoledger:open-settings-section", listen);
    }
  });

  it("transcript: failed, empty, and not transcribed yet, in classic's words", async () => {
    respond("transcript_detail", makeTranscript({ status: "failed", error_code: "no_audio", segments: [] }));
    await openReader();
    expect(screen.getByText("Transcription failed.")).toBeInTheDocument();
    expect(screen.getByText("No audio was captured in this recording. Check your microphone and the audio device, then try again.")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Try again|Re-transcribe/ })).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Shared" }));
    respond("transcript_detail", makeTranscript({ segments: [] }));
    fireEvent.click(rowButton("Call a"));
    await act(flushAsync);
    expect(screen.getByText("No speech was detected in this recording.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Copy" })).toBeDisabled();
  });
});
