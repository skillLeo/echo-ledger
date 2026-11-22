// Library as the start screen (PLAN_V2_PARITY 2): Home's get-started checklist as a card that can be hidden, until
// setup is done, and what needs action as one quiet banner that opens the right view.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeItem, makePendingItem, makeStatus } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";
import { dismissedKey } from "./LibraryTop";
import type { Account } from "../../lib/ipc";

const account = (email: string): Account => ({ name: "Ayesha Khan", email, email_verified: true, two_factor: false, google: false, created_at: null, workspace: null, deletes_at: null });

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
    respond("account_get", account("ayesha@example.com"));
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
    const KEY = dismissedKey("https://ledger.example.test", "ayesha@example.com");
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

    it("is kept per account: another account on this computer still sees the banner the first one dismissed", async () => {
      const first = await again({ pendingDecisions: 2 });
      dismiss();
      first.unmount();
      respond("account_get", account("bilal@example.com"));
      const other = await again({ pendingDecisions: 2 });
      expect(screen.getByTestId("needs-action")).toBeInTheDocument();
      other.unmount();
      respond("account_get", account("Ayesha@Example.com"));
      await again({ pendingDecisions: 2 });
      expect(screen.queryByTestId("needs-action")).toBeNull();
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

  describe("the header's button while the banner is dismissed", () => {
    const actions = () => document.querySelector(".el-page__head .el-page__actions") as HTMLElement;
    const needs = () => actions().firstElementChild?.matches(".el-menu-anchor") ? actions().querySelector("button")! : actions().firstElementChild as HTMLElement;
    const dismiss = () => fireEvent.click(within(screen.getByTestId("needs-action")).getByRole("button", { name: "Dismiss" }));
    afterEach(() => {
      document.documentElement.dir = "";
      vi.restoreAllMocks();
    });

    it("is not there while the banner shows, and never beside it", async () => {
      await show();
      expect(screen.getByTestId("needs-action")).toBeInTheDocument();
      expect(within(actions()).queryByRole("button", { name: /need you|not transcribed/ })).toBeNull();
      expect(within(actions()).getAllByRole("button")[0]).toHaveAccessibleName("Trash");
    });

    it("after ×, several kinds: \"3 need you\" before Trash, a menu of each kind like Review's", async () => {
      await show();
      dismiss();
      expect(screen.queryByTestId("needs-action")).toBeNull();
      const button = within(actions()).getByRole("button", { name: "3 need you" });
      expect(button).toBe(needs());
      expect(button).toHaveTextContent("3 need you");
      expect(button).toHaveAttribute("aria-haspopup", "menu");
      expect(button).toHaveAttribute("title", "2 calls wait for your decision · 1 recording isn't transcribed");
      fireEvent.click(button);
      expect(screen.getAllByRole("menuitem").map((m) => m.textContent)).toEqual(["2 calls wait for your decision", "1 recording isn't transcribed"]);
      fireEvent.click(screen.getByRole("menuitem", { name: "1 recording isn't transcribed" }));
      expect([...document.querySelectorAll("button[data-row-id]")].map((b) => b.getAttribute("data-row-id"))).toEqual(["a"]);
    });

    it("after ×, one kind: \"1 not transcribed\" opens what Review opens, without a menu", async () => {
      await show(makeStatus({ platform: "macos" }));
      expect(screen.getByTestId("needs-action")).toHaveTextContent("1 recording isn't transcribed");
      dismiss();
      const button = within(actions()).getByRole("button", { name: "1 not transcribed" });
      expect(button).toBe(needs());
      expect(button).not.toHaveAttribute("aria-haspopup");
      expect(button).toHaveAttribute("title", "1 recording isn't transcribed");
      fireEvent.click(button);
      expect([...document.querySelectorAll("button[data-row-id]")].map((b) => b.getAttribute("data-row-id"))).toEqual(["a"]);
    });

    it("stays after a restart while dismissed, goes when the banner comes back, and when nothing needs action", async () => {
      const first = await show(makeStatus({ platform: "macos", pendingDecisions: 2 }));
      dismiss();
      first.unmount();
      const same = await show(makeStatus({ platform: "macos", pendingDecisions: 2 }));
      expect(within(actions()).getByRole("button", { name: "3 need you" })).toBeInTheDocument();
      same.unmount();
      const more = await show(makeStatus({ platform: "macos", pendingDecisions: 3 }));
      expect(screen.getByTestId("needs-action")).toBeInTheDocument();
      expect(within(actions()).queryByRole("button", { name: /need you|not transcribed|waiting/ })).toBeNull();
      more.unmount();
      respond("library_list", [ITEMS[1]]);
      respond("library_refresh", [ITEMS[1]]);
      await show(makeStatus({ platform: "macos" }));
      expect(screen.queryByTestId("needs-action")).toBeNull();
      expect(within(actions()).getAllByRole("button")[0]).toHaveAccessibleName("Trash");
    });

    it("in a right-to-left (Urdu) window: first on the start side, its count and words one left-to-right piece", async () => {
      document.documentElement.dir = "rtl";
      await show(makeStatus({ platform: "macos" }));
      dismiss();
      const button = needs();
      expect(button).toHaveAccessibleName("1 not transcribed");
      const words = button.querySelector("[dir=auto]");
      expect(words?.textContent).toBe("1 not transcribed");
      expect(actions().firstElementChild).toBe(button);
    });

    it("where its words would put the header on another line: the icon and count, its name and tooltip keep the words", async () => {
      // The header 400 wide; Trash, Sync and Select 100 each; the button 180 with its words (90 of them).
      vi.spyOn(HTMLElement.prototype, "clientWidth", "get").mockImplementation(function (this: HTMLElement) {
        return this.classList.contains("el-page__head") ? 400 : 0;
      });
      vi.spyOn(HTMLElement.prototype, "scrollWidth", "get").mockImplementation(() => 90);
      vi.spyOn(Element.prototype, "getBoundingClientRect").mockImplementation(function (this: Element) {
        const width = this.parentElement?.classList.contains("el-page__actions") ? (this.querySelector("[dir=auto]") ? 180 : 100)
          : this.parentElement?.matches("[dir=auto]") ? 90 : 0;
        return { width, height: 0, top: 0, left: 0, right: width, bottom: 0, x: 0, y: 0, toJSON: () => ({}) } as DOMRect;
      });
      await show(makeStatus({ platform: "macos" }));
      dismiss();
      const button = within(actions()).getByRole("button", { name: "1 not transcribed" });
      expect(button.querySelector(".el-visually-hidden")).toHaveTextContent("not transcribed");
      expect(button).toHaveAttribute("title", "1 recording isn't transcribed");
    });

    it("keeps its words where they fit", async () => {
      await show(makeStatus({ platform: "macos" }));
      dismiss();
      expect(within(actions()).getByRole("button", { name: "1 not transcribed" }).querySelector(".el-visually-hidden")).toBeNull();
    });
  });
});
