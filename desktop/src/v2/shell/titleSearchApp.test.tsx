// UX-20 in the app: ⌘K opens the search of everything under the title row's field; "Show all in the list" turns the
// Library into the results; Ask about "…" opens Ask with the question.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import MainApp from "../../views/main/MainApp";
import { flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeEntitlements, makeItem, makeSettings, makeStatus, makeTranscript } from "../../test/fixtures";
import { resetEntitlementsForTests } from "../../lib/entitlements";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const ITEMS = [makeItem("a", { title: "Bilal Qureshi" }), makeItem("b", { title: "Weekly Zoom", preview: "the invoice is due" })];
const settle = async () => {
  await act(flushAsync);
  await act(flushAsync);
};

async function app() {
  respond("app_status", makeStatus({ platform: "macos" }));
  respond("get_settings", makeSettings());
  respond("library_list", ITEMS);
  respond("library_refresh", ITEMS);
  respond("list_clients", { data: [{ id: "p1", name: "Qureshi Textiles" }] });
  respond("projects_list", { data: [] });
  respond("mic_users", []);
  respond("session_detail", {});
  respond("transcript_detail", makeTranscript({ id: "tr-a" }));
  respond("bin_list", { data: [] });
  respond("search_library", { data: [{ session: ITEMS[1], hit_count: 1, hits: [] }], next_page: null });
  respond("ui_flags", { ui_flags: { "ui_v2.shell": true, "ui_v2.library": true, "ui_v2.ask": true }, ui_classic: false });
  render(<MainApp />);
  await settle();
  await screen.findByRole("navigation", { name: "Main" });
}

beforeEach(() => {
  Object.defineProperty(window, "innerWidth", { value: 1440, configurable: true, writable: true });
  resetTauriMock();
  resetEntitlementsForTests(makeEntitlements());
  localStorage.clear();
});

describe("the title row's search in the app (UX-20)", () => {
  it("⌘K opens it in its groups (the Library's row field searches the Library); \"Show all in the list\" makes the Library the results", async () => {
    await app();
    // The Library's title row holds its own search ("Search recordings"); ⌘K opens the search of everything.
    fireEvent.keyDown(window, { key: "k", metaKey: true });
    await settle();
    const input = screen.getByRole("combobox");
    fireEvent.change(input, { target: { value: "invoice" } });
    const all = await screen.findByRole("option", { name: "Show all in the list" });
    fireEvent.mouseDown(all);
    await settle();
    expect(screen.queryByRole("combobox")).toBeNull();
    expect(within(screen.getByRole("navigation", { name: "Main" })).getByRole("button", { name: /^Library/ })).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("heading", { level: 1, name: /^All recordings/ })).toBeInTheDocument();
    expect(screen.getByRole("searchbox", { name: "Search recordings" })).toHaveValue("invoice");
  });

  it("from the Library's title row: ⌘F, typed words, then Search everything instead opens the grouped search with them", async () => {
    await app();
    fireEvent.keyDown(document.body, { key: "f", metaKey: true });
    await settle();
    const field = within(screen.getByTestId("title-row")).getByRole("searchbox", { name: "Search recordings" });
    expect(field).toHaveFocus();
    fireEvent.change(field, { target: { value: "invoice" } });
    fireEvent.click(within(screen.getByTestId("title-row")).getByRole("button", { name: "Search everything instead" }));
    await settle();
    expect(screen.getByRole("combobox")).toHaveValue("invoice");
    expect(await screen.findByRole("option", { name: "Show all in the list" })).toBeInTheDocument();
  });

  it("/ from a row of the list goes to the title row's search, as ⌘F does (FIX-06: no Tabs back up)", async () => {
    await app();
    const first = await screen.findByRole("button", { name: /^Bilal Qureshi/ });
    act(() => first.focus());
    fireEvent.keyDown(first, { key: "/" });
    await settle();
    expect(within(screen.getByTestId("title-row")).getByRole("searchbox", { name: "Search recordings" })).toHaveFocus();
  });

  it("Ask about \"…\" opens Ask with the question in it", async () => {
    await app();
    fireEvent.keyDown(window, { key: "k", metaKey: true });
    await settle();
    fireEvent.change(screen.getByRole("combobox"), { target: { value: "what did bilal agree" } });
    fireEvent.mouseDown(screen.getByRole("option", { name: "Ask about “what did bilal agree”" }));
    await settle();
    await settle();
    expect(within(screen.getByRole("navigation", { name: "Main" })).getByRole("button", { name: "Ask" })).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("textbox", { name: "Your question" })).toHaveValue("what did bilal agree");
  });
});
