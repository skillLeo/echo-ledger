// Library as the start screen (PLAN_V2_PARITY 2): Home's get-started checklist as a card that can be hidden, until
// setup is done, and what needs action as one quiet banner that opens the right view.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeItem, makeStatus } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const ITEMS = [
  makeItem("a", { title: "Ali", status: "uploaded", transcript: { final_status: null, final_transcript_id: null, live_available: false, preview: null } }),
  makeItem("b", { title: "Bina" }),
];

describe("the top of the Library", () => {
  beforeEach(() => {
    resetTauriMock();
    window.localStorage.clear();
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("ready_last", { ready: false, checkedAt: "2026-11-17T10:00:00Z", checks: [] });
    respond("permissions_status", { microphone: "allowed", systemAudio: "unknown", screen: "allowed", browserAutomation: true, platform: "macos" });
    respond("skipped_list", []);
  });
  const show = async (status = makeStatus({ platform: "macos", pendingDecisions: 2 })) => {
    const top = { status, onSetup: vi.fn(), onReadyCheck: vi.fn(), onRecord: vi.fn(), onActivity: vi.fn() };
    render(<LibraryV2 top={top} />);
    await act(flushAsync);
    return top;
  };

  it("the get-started card until setup is done: each step runs its fix, and Hide keeps it hidden on this computer", async () => {
    const top = await show();
    const card = screen.getByRole("region", { name: "Get started" });
    expect(within(card).getAllByRole("listitem").map((li) => li.textContent)).toEqual([
      "1Allow the microphone and system audioTo doSet up", "2Pass the Ready checkTo doCheck", "Make a test recordingDone",
    ]);
    fireEvent.click(within(card).getByRole("button", { name: "Set up" }));
    expect(top.onSetup).toHaveBeenCalled();
    fireEvent.click(within(card).getByRole("button", { name: "Check" }));
    expect(top.onReadyCheck).toHaveBeenCalled();
    fireEvent.click(within(card).getByRole("button", { name: "Hide Get started" }));
    expect(screen.queryByRole("region", { name: "Get started" })).toBeNull();
    expect(window.localStorage.getItem("echoledger-library-setup-hidden")).toBe("1");
  });

  it("one quiet banner for what needs action; Review opens the view", async () => {
    await show();
    const banner = screen.getByTestId("needs-action");
    expect(banner).toHaveTextContent("2 calls wait for your decision · 1 more needs you");
    fireEvent.click(within(banner).getByRole("button", { name: "Review" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "1 recording isn't transcribed" }));
    expect([...document.querySelectorAll("button[data-row-id]")].map((b) => b.getAttribute("data-row-id"))).toEqual(["a"]);
  });

  it("nothing to do: no card, no banner; without `top` neither shows", async () => {
    respond("ready_last", { ready: true, checkedAt: "2026-11-17T10:00:00Z", checks: [] });
    respond("permissions_status", { microphone: "allowed", systemAudio: "allowed", screen: "allowed", browserAutomation: true, platform: "macos" });
    respond("library_list", [ITEMS[1]]);
    respond("library_refresh", [ITEMS[1]]);
    await show(makeStatus({ platform: "macos" }));
    expect(screen.queryByRole("region", { name: "Get started" })).toBeNull();
    expect(screen.queryByTestId("needs-action")).toBeNull();
  });
});
