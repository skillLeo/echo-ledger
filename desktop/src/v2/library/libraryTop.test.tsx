// Library as the start screen (PLAN_V2_PARITY 2): Home's get-started checklist as a card that can be hidden, until
// setup is done. What needs action lives only in the Library's header: one quiet button, led by an attention dot, that
// shows whenever something needs action and opens the right view (there is no needs-action banner).
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makePendingItem, makeStatus, makeUploadItem } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";
import { DISMISSED_PREFIX } from "./LibraryTop";
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
    const { unmount, rerender } = render(<LibraryV2 top={top} />);
    await act(flushAsync);
    // The app's status moves on (a call waits, a decision is made): the same Library with the new counts.
    const update = async (next: Parameters<typeof makeStatus>[0]) => {
      rerender(<LibraryV2 top={{ ...top, status: makeStatus({ platform: "macos", ...next }) }} />);
      await act(flushAsync);
    };
    return Object.assign(top, { unmount, update });
  };
  const actions = () => document.querySelector(".el-page__head .el-page__actions") as HTMLElement;
  const needs = () => actions().firstElementChild?.matches(".el-menu-anchor") ? actions().querySelector("button")! : actions().firstElementChild as HTMLElement;
  const NEEDS = /need you|needs you|not transcribed|waiting|didn't upload|skipped|notification/;
  /**
   * Every change to the header's needs button once it is on screen: its words changed, or it went or was swapped for
   * another (a count it then corrected). Being drawn is not a change. Read from the DOM's own records, so a state that
   * a later render corrected before the test looks again is caught too.
   */
  const watchCorrections = () => {
    const changes: string[] = [];
    const host = (node: Node) => (node instanceof Element ? node : node.parentElement)?.closest(".el-needs");
    const observer = new MutationObserver((records) => {
      for (const r of records) {
        const inside = host(r.target);
        if (inside) changes.push(`changed: ${r.type === "characterData" ? `"${r.oldValue}" in ` : ""}"${inside.textContent}"`);
        r.removedNodes.forEach((n) => {
          const gone = n instanceof Element ? (n.matches(".el-needs") ? n : n.querySelector(".el-needs")) : null;
          if (gone) changes.push(`went: "${gone.textContent}"`);
        });
      }
    });
    observer.observe(document.body, { subtree: true, childList: true, characterData: true, characterDataOldValue: true });
    return { changes, stop: () => observer.disconnect() };
  };
  /** A command that answers when the test says (`answer`), once per call. */
  const later = (command: string) => {
    const waiting: ((value: unknown) => void)[] = [];
    respondWith(command, () => new Promise((resolve) => waiting.push(resolve)));
    return {
      calls: () => waiting.length,
      answer: async (value: unknown) => {
        await act(async () => waiting.splice(0).forEach((resolve) => resolve(value)));
        await act(flushAsync);
      },
    };
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

  it("no needs-action banner in the Library: the header's button is the only place it is said", async () => {
    await show();
    expect(screen.queryByTestId("needs-action")).toBeNull();
    expect(document.querySelector(".el-library-top .el-banner")).toBeNull();
    expect(screen.queryByText(/wait for your decision|isn't transcribed/, { selector: ".el-banner *" })).toBeNull();
    expect(within(actions()).getByRole("button", { name: "3 need you" })).toBe(needs());
    // One "Review" for each thing: the queue's (the waiting calls are in the button's menu, nothing else says Review).
    expect(screen.queryAllByRole("button", { name: /^Review/ })).toHaveLength(0);
  });

  describe("the header's needs button", () => {
    afterEach(() => {
      document.documentElement.dir = "";
      vi.restoreAllMocks();
    });

    it("several kinds: \"3 need you\" first, before Trash, a menu of each kind that opens its view", async () => {
      await show();
      const button = within(actions()).getByRole("button", { name: "3 need you" });
      expect(button).toBe(needs());
      expect(button).toHaveTextContent("3 need you");
      expect(button).toHaveAttribute("aria-haspopup", "menu");
      expect(button).toHaveAttribute("title", "2 calls wait for your decision · 1 recording isn't transcribed");
      expect(within(actions()).getAllByRole("button")[1]).toHaveAccessibleName("Trash");
      fireEvent.click(button);
      expect(screen.getAllByRole("menuitem").map((m) => m.textContent)).toEqual(["2 calls wait for your decision", "1 recording isn't transcribed"]);
      fireEvent.click(screen.getByRole("menuitem", { name: "1 recording isn't transcribed" }));
      expect([...document.querySelectorAll("button[data-row-id]")].map((b) => b.getAttribute("data-row-id"))).toEqual(["a"]);
    });

    it("one kind: \"1 not transcribed\" opens what Review opens, without a menu", async () => {
      await show(makeStatus({ platform: "macos" }));
      const button = within(actions()).getByRole("button", { name: "1 not transcribed" });
      expect(button).toBe(needs());
      expect(button).not.toHaveAttribute("aria-haspopup");
      expect(button).toHaveAttribute("title", "1 recording isn't transcribed");
      fireEvent.click(button);
      expect([...document.querySelectorAll("button[data-row-id]")].map((b) => b.getAttribute("data-row-id"))).toEqual(["a"]);
    });

    it("missed calls open Activity", async () => {
      respond("library_list", [ITEMS[1]]);
      respond("library_refresh", [ITEMS[1]]);
      respond("skipped_list", [{ id: "s1" }, { id: "s2" }]);
      const top = await show(makeStatus({ platform: "macos" }));
      fireEvent.click(within(actions()).getByRole("button", { name: "2 skipped" }));
      expect(top.onActivity).toHaveBeenCalled();
    });

    it("leads with a small attention dot, hidden from screen readers; the words stay its name", async () => {
      await show();
      const dot = needs().querySelector(".el-attention-dot");
      expect(dot).not.toBeNull();
      expect(dot?.closest("[aria-hidden=true]")).not.toBeNull();
      expect(needs().firstElementChild).toBe(dot?.parentElement);
      expect(needs()).toHaveAccessibleName("3 need you");
    });

    it("shows whenever something needs action and goes at 0, as the counts change, without a ×", async () => {
      respond("library_list", [ITEMS[1]]);
      respond("library_refresh", [ITEMS[1]]);
      const top = await show(makeStatus({ platform: "macos", pendingDecisions: 2 }));
      expect(within(actions()).getByRole("button", { name: "2 waiting" })).toBe(needs());
      expect(within(needs()).queryByRole("button", { name: /Dismiss|Close/ })).toBeNull();
      await top.update({ pendingDecisions: 0 });
      expect(within(actions()).queryByRole("button", { name: NEEDS })).toBeNull();
      expect(within(actions()).getAllByRole("button")[0]).toHaveAccessibleName("Trash");
      await top.update({ pendingDecisions: 1 });
      expect(within(actions()).getByRole("button", { name: "1 waiting" })).toBe(needs());
      await top.update({ pendingDecisions: 1, failedUploads: 2 });
      expect(within(actions()).getByRole("button", { name: "3 need you" })).toBe(needs());
    });

    it("nothing needs action: no button, Trash comes first; without `top` there is none either", async () => {
      respond("library_list", [ITEMS[1]]);
      respond("library_refresh", [ITEMS[1]]);
      const first = await show(makeStatus({ platform: "macos" }));
      expect(within(actions()).queryByRole("button", { name: NEEDS })).toBeNull();
      expect(within(actions()).getAllByRole("button")[0]).toHaveAccessibleName("Trash");
      first.unmount();
      render(<LibraryV2 />);
      await act(flushAsync);
      expect(within(actions()).queryByRole("button", { name: NEEDS })).toBeNull();
    });

    it("waits for the counts: no passing number while the skipped calls load", async () => {
      let answer: (value: unknown[]) => void = () => undefined;
      respondWith("skipped_list", () => new Promise((resolve) => (answer = resolve)));
      await show();
      expect(within(actions()).queryByRole("button", { name: NEEDS })).toBeNull();
      await act(async () => answer([{ id: "s1" }]));
      expect(within(actions()).getByRole("button", { name: "4 need you" })).toBe(needs());
    });

    it("waits for the queue's waiting calls: a slow list never shows a count it then corrects", async () => {
      const watch = watchCorrections();
      const pending = later("pending_list");
      await show();
      // The status says 2 calls wait, but the queue (with its own Review) may be about to show them: no count yet.
      expect(within(actions()).queryByRole("button", { name: NEEDS })).toBeNull();
      await pending.answer([makePendingItem("p1"), makePendingItem("p2")]);
      expect(screen.getByTestId("waiting-line")).toHaveTextContent("2 calls waiting");
      expect(within(actions()).getByRole("button", { name: "1 not transcribed" })).toBe(needs());
      await act(flushAsync);
      expect(watch.changes).toEqual([]);
      watch.stop();
    });

    it("waits for the queue's uploads too: a slow upload list never shows a count it then corrects", async () => {
      const watch = watchCorrections();
      const uploads = later("upload_list");
      respond("pending_list", [makePendingItem("p1"), makePendingItem("p2")]);
      await show(makeStatus({ platform: "macos", pendingDecisions: 2, failedUploads: 1 }));
      expect(within(actions()).queryByRole("button", { name: NEEDS })).toBeNull();
      await uploads.answer([makeUploadItem("f1", { state: "failed", title: "Board call" })]);
      expect(screen.getByRole("heading", { name: "Uploads that need you · 1" })).toBeInTheDocument();
      expect(within(actions()).getByRole("button", { name: "1 not transcribed" })).toBe(needs());
      expect(watch.changes).toEqual([]);
      watch.stop();
    });

    it("with waiting calls, Trash and back, and a selection cancelled, show the right count at once and never correct it", async () => {
      respond("pending_list", [makePendingItem("p1"), makePendingItem("p2")]);
      respond("upload_list", []);
      respond("bin_list", { data: [] });
      await show();
      expect(within(actions()).getByRole("button", { name: "1 not transcribed" })).toBe(needs());
      // From here on the lists answer only when the test says: what the header shows meanwhile is what it knew.
      const pending = later("pending_list");
      const uploads = later("upload_list");
      fireEvent.click(within(actions()).getByRole("button", { name: "Trash" }));
      await act(flushAsync);
      expect(within(actions()).queryByRole("button", { name: NEEDS })).toBeNull();
      const watch = watchCorrections();
      fireEvent.click(within(actions()).getByRole("button", { name: "Trash" }));
      expect(within(actions()).getByRole("button", { name: "1 not transcribed" })).toBe(needs());
      await act(flushAsync);
      expect(within(actions()).getByRole("button", { name: "1 not transcribed" })).toBe(needs());
      await pending.answer([makePendingItem("p1"), makePendingItem("p2")]);
      await uploads.answer([]);
      expect(within(actions()).getByRole("button", { name: "1 not transcribed" })).toBe(needs());
      expect(watch.changes).toEqual([]);
      watch.stop();

      fireEvent.click(within(actions()).getByRole("button", { name: "Select" }));
      await act(flushAsync);
      const again = watchCorrections();
      fireEvent.click(screen.getByRole("button", { name: "Cancel selection" }));
      expect(within(actions()).getByRole("button", { name: "1 not transcribed" })).toBe(needs());
      await pending.answer([makePendingItem("p1"), makePendingItem("p2")]);
      await uploads.answer([]);
      expect(within(actions()).getByRole("button", { name: "1 not transcribed" })).toBe(needs());
      expect(again.changes).toEqual([]);
      again.stop();
    });

    it("a dismissal the old banner stored never hides it, and is cleared (every account's); other settings stay", async () => {
      const keys = [`${DISMISSED_PREFIX}:https://ledger.example.test:ayesha@example.com`, `${DISMISSED_PREFIX}:https://ledger.example.test:bilal@example.com`];
      keys.forEach((key) => window.localStorage.setItem(key, JSON.stringify({ waiting: 5, untranscribed: 5 })));
      window.localStorage.setItem("echoledger-library-setup-hidden", "1");
      await show();
      expect(within(actions()).getByRole("button", { name: "3 need you" })).toBe(needs());
      keys.forEach((key) => expect(window.localStorage.getItem(key)).toBeNull());
      expect(window.localStorage.getItem("echoledger-library-setup-hidden")).toBe("1");
    });

    it("shows when storage is unavailable", async () => {
      const spies = (["getItem", "key", "removeItem"] as const).map((name) => vi.spyOn(Storage.prototype, name).mockImplementation(() => {
        throw new Error("no storage");
      }));
      const length = vi.spyOn(Storage.prototype, "length", "get").mockImplementation(() => {
        throw new Error("no storage");
      });
      try {
        await show();
        expect(within(actions()).getByRole("button", { name: "3 need you" })).toBe(needs());
      } finally {
        [...spies, length].forEach((spy) => spy.mockRestore());
      }
    });

    it("is away in Trash and while selecting, and back after", async () => {
      await show();
      fireEvent.click(within(actions()).getByRole("button", { name: "Trash" }));
      await act(flushAsync);
      expect(within(actions()).queryByRole("button", { name: NEEDS })).toBeNull();
      fireEvent.click(within(actions()).getByRole("button", { name: "Trash" }));
      await act(flushAsync);
      expect(within(actions()).getByRole("button", { name: "3 need you" })).toBe(needs());
      fireEvent.click(within(actions()).getByRole("button", { name: "Select" }));
      await act(flushAsync);
      expect(screen.queryByRole("button", { name: NEEDS })).toBeNull();
      fireEvent.click(screen.getByRole("button", { name: "Cancel selection" }));
      await act(flushAsync);
      expect(within(actions()).getByRole("button", { name: "3 need you" })).toBe(needs());
    });

    it("in a right-to-left (Urdu) window: first on the start side, its count and words one left-to-right piece", async () => {
      document.documentElement.dir = "rtl";
      await show(makeStatus({ platform: "macos" }));
      const button = needs();
      expect(button).toHaveAccessibleName("1 not transcribed");
      const words = button.querySelector("[dir=auto]");
      expect(words?.textContent).toBe("1 not transcribed");
      expect(actions().firstElementChild).toBe(button);
    });

    it("always keeps its words (narrow windows fold other actions into More instead: headerFold.test.tsx)", async () => {
      await show(makeStatus({ platform: "macos" }));
      expect(within(actions()).getByRole("button", { name: "1 not transcribed" })).toHaveTextContent("1 not transcribed");
    });
  });
});
