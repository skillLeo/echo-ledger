import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { callsOf, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeSettings, makeStatus } from "../../test/fixtures";
import MainApp from "../../views/main/MainApp";
import { HelpV2, MESSAGE_MAX, helpError, stripTitle } from "./HelpV2";
import { Markdown, blocks } from "./markdown";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const GUIDES = [
  { id: "after-a-call", title: "After a call", excerpt: "What happens to a recording when the call ends." },
  { id: "record-automatically", title: "Record automatically", excerpt: "Calls in WhatsApp, Meet, Zoom and Teams." },
];
const GUIDE = {
  id: "after-a-call", title: "After a call",
  markdown: "## After a call\n\nWhen a call ends, **EchoLedger** asks what to keep.\nIt waits a few seconds.\n\n1. Choose **Transcribe**.\n2. Or choose `Save audio`,\n   which keeps only the sound.\n\n| Choice | What it does |\n|---|---|\n| Transcribe | Writes the words |\n\nSee [the settings](https://example.com/settings).",
};
const RELEASES = [
  { version: "2.0.1", platform: "windows", released_at: "2026-11-15T10:00:00Z", notes: "- Screens on the recording page\n- Faster sign-in" },
  { version: "2.0.0", platform: "windows", released_at: "2026-11-01T10:00:00Z", notes: "The new look." },
];

beforeEach(() => {
  resetTauriMock();
  respondWith("help_guides", ({ q }) => ({ data: q ? GUIDES.filter((g) => g.title.toLowerCase().includes(String(q).toLowerCase())) : GUIDES }));
  respond("help_guide", GUIDE);
  respond("help_whats_new", { data: RELEASES });
});

const show = async (platform = "windows") => {
  const onBack = vi.fn();
  const onMacCheck = vi.fn();
  render(<div className="el-root"><HelpV2 platform={platform} onBack={onBack} onMacCheck={onMacCheck} /></div>);
  await act(flushAsync);
  return { onBack, onMacCheck };
};
const typeQuery = async (text: string) => {
  fireEvent.change(screen.getByRole("searchbox", { name: "Search help" }), { target: { value: text } });
  await act(async () => { await new Promise((r) => setTimeout(r, 350)); });
  await act(flushAsync);
};

describe("the guide's Markdown", () => {
  it("reads headings, paragraphs, lists with their continued lines, tables and code", () => {
    expect(blocks(GUIDE.markdown).map((b) => b.kind)).toEqual(["heading", "paragraph", "list", "table", "paragraph"]);
    expect(blocks(GUIDE.markdown)[2]).toEqual({ kind: "list", ordered: true, items: ["Choose **Transcribe**.", "Or choose `Save audio`, which keeps only the sound."] });
  });

  it("renders as elements: bold, code, a link's words only, and markup in the text stays text", () => {
    render(<Markdown text={"Press **Send** or `Ctrl+Enter`. See [the guide](https://example.com).\n\n<img src=x onerror=alert(1)>"} />);
    expect(screen.getByText("Send").tagName).toBe("STRONG");
    expect(screen.getByText("Ctrl+Enter").tagName).toBe("CODE");
    expect(screen.queryByRole("link")).toBeNull();
    expect(screen.getByText(/See the guide\./)).toBeInTheDocument();
    expect(document.querySelector("img")).toBeNull();
    expect(screen.getByText("<img src=x onerror=alert(1)>")).toBeInTheDocument();
  });

  it("an app whose core has no help commands yet says so in words", () => {
    expect(helpError("command help_guides not found")).toBe("Help isn't available in this version of the app yet. Update EchoLedger to use it.");
    expect(helpError("The server is out of reach.")).toBe("The server is out of reach.");
  });

  it("drops the section's own heading line when it repeats the title", () => {
    expect(stripTitle("## After a call\n\nText", "After a call")).toBe("\nText");
    expect(stripTitle("Text only", "After a call")).toBe("Text only");
  });
});

describe("Help v2", () => {
  it("lists the guides, searches them on the server, and opens one to read", async () => {
    const { onBack } = await show();
    expect(screen.getByRole("heading", { level: 1, name: "Help" })).toBeInTheDocument();
    expect(callsOf("help_guides")).toEqual([{ q: null }]);
    const list = screen.getByRole("list", { name: "Guides" });
    expect(within(list).getByRole("button", { name: "After a call" })).toHaveAccessibleDescription("What happens to a recording when the call ends.");
    await typeQuery("record");
    expect(callsOf("help_guides").at(-1)).toEqual({ q: "record" });
    const matching = within(screen.getByRole("list", { name: "Matching guides" }));
    expect(matching.getAllByRole("button")).toHaveLength(1);
    expect(matching.getByRole("button", { name: "Record automatically" })).toBeInTheDocument();

    await typeQuery("");
    fireEvent.click(screen.getByRole("button", { name: "After a call" }));
    await act(flushAsync);
    expect(callsOf("help_guide")).toEqual([{ id: "after-a-call" }]);
    const title = screen.getByRole("heading", { level: 2, name: "After a call" });
    expect(title).toHaveFocus();
    expect(screen.getAllByRole("heading", { name: "After a call" })).toHaveLength(1);
    expect(screen.getByText(/asks what to keep\. It waits a few seconds\./)).toBeInTheDocument();
    expect(screen.getByRole("table")).toHaveTextContent("TranscribeWrites the words");
    fireEvent.click(screen.getByRole("button", { name: "All guides" }));
    await act(flushAsync);
    expect(screen.getByRole("list", { name: "Guides" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Settings" }));
    expect(onBack).toHaveBeenCalled();
  });

  it("no match says so with Clear search; a failed load has Try again", async () => {
    await show();
    await typeQuery("zzz");
    fireEvent.click(within(screen.getByRole("region", { name: "No guide matches" })).getByRole("button", { name: "Clear search" }));
    await act(flushAsync);
    expect(screen.getByRole("list", { name: "Guides" })).toBeInTheDocument();

    fail("help_guides", "The server is out of reach.");
    await typeQuery("after");
    const alert = screen.getByRole("alert");
    expect(alert).toHaveTextContent("The guides didn't loadThe server is out of reach.");
    respondWith("help_guides", () => ({ data: GUIDES }));
    fireEvent.click(within(alert).getByRole("button", { name: "Try again" }));
    await act(async () => { await new Promise((r) => setTimeout(r, 350)); });
    await act(flushAsync);
    expect(screen.getByRole("list", { name: "Matching guides" })).toBeInTheDocument();
  });

  it("the Mac check is there on a Mac only", async () => {
    const { onMacCheck } = await show("macos");
    fireEvent.click(screen.getByRole("button", { name: "Mac check" }));
    expect(onMacCheck).toHaveBeenCalled();
  });

  it("no Mac check on Windows", async () => {
    await show("windows");
    expect(screen.queryByRole("button", { name: "Mac check" })).toBeNull();
  });

  it("What's new: this platform's releases, newest first, each with its date and notes", async () => {
    await show();
    fireEvent.click(screen.getByRole("tab", { name: "What's new" }));
    await act(flushAsync);
    expect(callsOf("help_whats_new")).toHaveLength(1);
    const first = screen.getByRole("region", { name: "Version 2.0.1" });
    expect(within(first).getByText("15 Nov")).toBeInTheDocument();
    expect(within(first).getAllByRole("listitem").map((li) => li.textContent)).toEqual(["Screens on the recording page", "Faster sign-in"]);
    expect(screen.getByRole("region", { name: "Version 2.0.0" })).toHaveTextContent("The new look.");
  });

  it("What's new with none says so", async () => {
    respond("help_whats_new", { data: [] });
    await show();
    fireEvent.click(screen.getByRole("tab", { name: "What's new" }));
    await act(flushAsync);
    expect(screen.getByRole("region", { name: "No release notes yet" })).toBeInTheDocument();
  });

  it("Contact support sends the subject and message, with the device check only when ticked", async () => {
    respond("support_send", null);
    await show();
    fireEvent.click(screen.getByRole("tab", { name: "Contact support" }));
    const send = screen.getByRole("button", { name: "Send" });
    expect(send).toBeDisabled();
    fireEvent.change(screen.getByRole("textbox", { name: "Subject" }), { target: { value: "Screens missing " } });
    fireEvent.change(screen.getByRole("textbox", { name: "Message" }), { target: { value: "The strip is empty after a Zoom call." } });
    expect(screen.getByRole("textbox", { name: "Message" })).toHaveAttribute("maxlength", String(MESSAGE_MAX));
    const attach = screen.getByRole("checkbox", { name: "Attach this computer's last device check" });
    expect(attach).not.toBeChecked();
    expect(attach).toHaveAccessibleDescription("The app version, the system and what it allows EchoLedger. Never your recordings or transcripts.");
    fireEvent.click(send);
    await act(flushAsync);
    expect(callsOf("support_send")).toEqual([{ subject: "Screens missing", message: "The strip is empty after a Zoom call.", includeDeviceReport: false }]);
    expect(screen.getByRole("heading", { name: "Message sent" })).toHaveFocus();

    fireEvent.click(screen.getByRole("button", { name: "Write another" }));
    fireEvent.change(screen.getByRole("textbox", { name: "Subject" }), { target: { value: "Again" } });
    fireEvent.change(screen.getByRole("textbox", { name: "Message" }), { target: { value: "With the report." } });
    fireEvent.click(screen.getByRole("checkbox", { name: "Attach this computer's last device check" }));
    fail("support_send", "You've sent 5 messages this hour. Try again later.");
    fireEvent.click(screen.getByRole("button", { name: "Send" }));
    await act(flushAsync);
    expect(callsOf("support_send")[1]).toEqual({ subject: "Again", message: "With the report.", includeDeviceReport: true });
    expect(screen.getByRole("alert")).toHaveTextContent("You've sent 5 messages this hour. Try again later.");
    expect(screen.getByRole("textbox", { name: "Message" })).toHaveValue("With the report.");
  });
});

describe("the app with ui_v2.settings", () => {
  it("Settings › Help opens the new Help page, and its back link returns to Settings", async () => {
    respond("app_status", makeStatus({ platform: "windows" }));
    respond("library_list", [makeItem("a")]);
    respond("library_refresh", [makeItem("a")]);
    respond("get_settings", makeSettings());
    respond("list_clients", { data: [] });
    respond("mic_users", []);
    respond("account_get", null);
    respond("storage_info", null);
    respond("ui_flags", { ui_flags: { "ui_v2.shell": true, "ui_v2.settings": true } });
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(within(await screen.findByRole("navigation", { name: "Main" })).getByRole("button", { name: "Settings" }));
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(await screen.findByRole("button", { name: "Help" }));
    await act(flushAsync);
    await act(flushAsync);
    expect(await screen.findByRole("heading", { level: 1, name: "Help" })).toBeInTheDocument();
    expect(await screen.findByRole("list", { name: "Guides" })).toBeInTheDocument();
    fireEvent.click(document.querySelector("[data-v2=help] > .el-page__back")!);
    expect(await screen.findByRole("heading", { level: 1, name: "Settings" })).toBeInTheDocument();
  });
});
