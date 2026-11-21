// Library as the start screen (PLAN_V2_PARITY 2): Home's get-started checklist as a card that can be hidden, until
// setup is done, and what needs action as one quiet banner that opens the right view.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeItem, makePendingItem, makeStatus } from "../../test/fixtures";
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
    const { unmount } = render(<LibraryV2 top={top} />);
    await act(flushAsync);
    return Object.assign(top, { unmount });
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

  describe("the banner's × (Dismiss)", () => {
    const KEY = "echoledger-library-needs-action-dismissed";
    const dismiss = () => fireEvent.click(within(screen.getByTestId("needs-action")).getByRole("button", { name: "Dismiss" }));
    // Starts with 2 waiting calls and 1 untranscribed recording.
    const again = (status: Parameters<typeof makeStatus>[0]) => show(makeStatus({ platform: "macos", ...status }));

    it("is a button named Dismiss that the keyboard reaches; it hides the banner and remembers what it said", async () => {
      await show();
      const button = within(screen.getByTestId("needs-action")).getByRole("button", { name: "Dismiss" });
      expect(button.tagName).toBe("BUTTON");
      expect(button.tabIndex).toBe(0);
      button.focus();
      expect(document.activeElement).toBe(button);
      dismiss();
      expect(screen.queryByTestId("needs-action")).toBeNull();
      expect(JSON.parse(window.localStorage.getItem(KEY) ?? "null")).toEqual({ waiting: 2, untranscribed: 1 });
    });

    it("stays hidden after a restart with the same counts, and when a count falls", async () => {
      const first = await again({ pendingDecisions: 2 });
      dismiss();
      first.unmount();
      const same = await again({ pendingDecisions: 2 });
      expect(screen.queryByTestId("needs-action")).toBeNull();
      same.unmount();
      await again({ pendingDecisions: 1 });
      expect(screen.queryByTestId("needs-action")).toBeNull();
      expect(JSON.parse(window.localStorage.getItem(KEY) ?? "null")).toEqual({ waiting: 1, untranscribed: 1 });
    });

    it("comes back when a count goes up, also after it fell", async () => {
      const first = await again({ pendingDecisions: 2 });
      dismiss();
      first.unmount();
      const fewer = await again({ pendingDecisions: 1 });
      expect(screen.queryByTestId("needs-action")).toBeNull();
      fewer.unmount();
      await again({ pendingDecisions: 2 });
      expect(screen.getByTestId("needs-action")).toHaveTextContent("2 calls wait for your decision · 1 more needs you");
      expect(window.localStorage.getItem(KEY)).toBeNull();
    });

    it("comes back when a new kind of notice appears", async () => {
      const first = await again({ pendingDecisions: 2 });
      dismiss();
      first.unmount();
      await again({ pendingDecisions: 2, failedUploads: 1 });
      expect(screen.getByTestId("needs-action")).toHaveTextContent("1 recording didn't upload · 2 more need you");
    });

    it("stays hidden after a restart while the queue shows the waiting calls (it reports that once it has loaded)", async () => {
      respond("pending_list", [makePendingItem("p1"), makePendingItem("p2")]);
      const first = await again({ pendingDecisions: 2 });
      expect(screen.getByTestId("needs-action")).toHaveTextContent("1 recording isn't transcribed");
      dismiss();
      first.unmount();
      await again({ pendingDecisions: 2 });
      expect(screen.queryByTestId("needs-action")).toBeNull();
      expect(JSON.parse(window.localStorage.getItem(KEY) ?? "null")).toEqual({ waiting: 2, untranscribed: 1 });
    });

    it("shows when storage is unavailable", async () => {
      window.localStorage.setItem(KEY, JSON.stringify({ waiting: 5, untranscribed: 5 }));
      const get = vi.spyOn(Storage.prototype, "getItem").mockImplementation(() => {
        throw new Error("no storage");
      });
      try {
        await show();
        expect(screen.getByTestId("needs-action")).toBeInTheDocument();
      } finally {
        get.mockRestore();
      }
    });
  });
});
