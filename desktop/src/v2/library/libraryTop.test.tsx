// Library as the start screen (PLAN_V2_PARITY 2): Home's get-started checklist as a card that can be hidden, until
// setup is done. What needs the person is the sidebar's Needs you (PLAN_LIBRARY_FILTERS; libraryNav.test.tsx): nothing in
// the Library's toolbar or top says it, and the old banner's stored dismissals are cleared.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeItem, makeStatus } from "../../test/fixtures";
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

  it("nothing in the Library's toolbar or top says what needs you: no banner, no button; the sidebar's Needs you does", async () => {
    await show(makeStatus({ platform: "macos", pendingDecisions: 2, failedUploads: 1 }));
    expect(screen.queryByTestId("needs-action")).toBeNull();
    const toolbar = document.querySelector(".el-lib-toolbar") as HTMLElement;
    expect(within(toolbar).queryByRole("button", { name: /need you|not transcribed|waiting|didn't upload/ })).toBeNull();
    // One toolbar row: Select all, the view's name, Search, Filters and Sort & display; nothing else.
    expect(within(toolbar).getAllByRole("button").map((b) => b.textContent)).toEqual(["Filters", "Sort & display"]);
  });

  it("an old needs-action dismissal is cleared once (every account's); other settings stay", async () => {
    const keys = [`${DISMISSED_PREFIX}:https://ledger.example.test:ayesha@example.com`, `${DISMISSED_PREFIX}:https://ledger.example.test:bilal@example.com`];
    keys.forEach((key) => window.localStorage.setItem(key, JSON.stringify({ waiting: 5, untranscribed: 5 })));
    window.localStorage.setItem("echoledger-library-setup-hidden", "1");
    await show();
    keys.forEach((key) => expect(window.localStorage.getItem(key)).toBeNull());
    expect(window.localStorage.getItem("echoledger-library-setup-hidden")).toBe("1");
  });
});
