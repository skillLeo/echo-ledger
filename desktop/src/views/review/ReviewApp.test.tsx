import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import ReviewApp from "./ReviewApp";
import { callsOf, emitEvent, fail, flushAsync, listenerCount, resetTauriMock, respond, respondAll, setWindowLabel } from "../../test/tauri";
import { makeAskPayload, makePendingItem, makeReviewPayload, makeSettings } from "../../test/fixtures";
import type { ReviewPayload } from "../../lib/ipc";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);
vi.mock("@tauri-apps/api/webviewWindow", async () => (await import("../../test/tauri")).windowModule);

class NoopResizeObserver {
  observe() {}
  unobserve() {}
  disconnect() {}
}

const CLIENTS = {
  data: [
    { id: "cl-1", name: "Acme Ltd", keyterm_count: 0 },
    { id: "cl-2", name: "Globex", keyterm_count: 3 },
  ],
};

async function renderReview(initial: ReviewPayload | null = makeReviewPayload()) {
  respond("review_state", initial);
  const view = render(<ReviewApp />);
  await waitFor(() => expect(callsOf("review_state").length).toBeGreaterThan(0));
  await flushAsync();
  return view;
}

const card = () => screen.queryByTestId("review-card");
const chip = () => screen.queryByTestId("ask-chip");
const button = (name: string) => screen.getByRole("button", { name });

describe("review card", () => {
  beforeEach(() => {
    resetTauriMock();
    setWindowLabel("review");
    vi.stubGlobal("ResizeObserver", NoopResizeObserver);
    respondAll({
      overlay_set_hit_regions: undefined,
      pending_keep: undefined,
      pending_discard: undefined,
      pending_open: undefined,
      list_clients: CLIENTS,
      ask_answer: undefined,
    });
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it("asks the core what to show on mount and renders the item", async () => {
    await renderReview(makeReviewPayload({ item: makePendingItem("pend-1", { durationMs: 750_000, clientName: null }) }));
    expect(card()).toBeInTheDocument();
    expect(screen.getByText("Zoom call")).toBeInTheDocument();
    expect(screen.getByText("12:30")).toBeInTheDocument();
    expect(button("No client")).toBeInTheDocument();
    expect(screen.getByText("Thanks for joining, shall we start with the invoice?")).toBeInTheDocument();
    expect(button("Keep")).toBeInTheDocument();
    expect(button("Discard")).toBeInTheDocument();
    expect(button("More actions")).toBeInTheDocument();
    expect(screen.queryByText(/of \d/)).not.toBeInTheDocument();
  });

  it("shows the title, client and 'No captions' when there is no first line", async () => {
    await renderReview(makeReviewPayload({ item: makePendingItem("pend-1", { title: "Budget review", clientId: "cl-2", clientName: "Globex", firstLine: null }) }));
    expect(screen.getByText("Budget review")).toBeInTheDocument();
    expect(button("Globex")).toBeInTheDocument();
    expect(screen.getByText("No captions")).toBeInTheDocument();
  });

  it("titles voice notes 'Voice note' and shows '1 of N' for a queue", async () => {
    await renderReview(makeReviewPayload({ item: makePendingItem("pend-1", { mode: "mic_note", sourceApp: "voice_note" }), queueLength: 3 }));
    expect(screen.getByText("Voice note")).toBeInTheDocument();
    expect(screen.getByText("1 of 3")).toBeInTheDocument();
  });

  it("renders nothing until review:show and goes away on review:hide", async () => {
    await renderReview(null);
    expect(card()).not.toBeInTheDocument();
    await emitEvent("review:show", makeReviewPayload({ item: makePendingItem("pend-9", { title: "Late call" }) }));
    expect(screen.getByText("Late call")).toBeInTheDocument();
    await emitEvent("review:hide", null);
    expect(card()).not.toBeInTheDocument();
  });

  it("Keep calls pendingKeep with the item id and shows what the core says next", async () => {
    await renderReview();
    respond("review_state", null);
    fireEvent.click(button("Keep"));
    await waitFor(() => expect(callsOf("pending_keep")).toEqual([{ id: "pend-1" }]));
    await flushAsync();
    expect(card()).not.toBeInTheDocument();
    expect(callsOf("pending_discard")).toHaveLength(0);
  });

  it("Discard calls pendingDiscard with the item id and the next item follows", async () => {
    await renderReview(makeReviewPayload({ queueLength: 2 }));
    respond("review_state", makeReviewPayload({ item: makePendingItem("pend-2", { title: "Second call" }), queueLength: 1 }));
    fireEvent.click(button("Discard"));
    await waitFor(() => expect(callsOf("pending_discard")).toEqual([{ id: "pend-1" }]));
    await flushAsync();
    expect(screen.getByText("Second call")).toBeInTheDocument();
    expect(callsOf("pending_keep")).toHaveLength(0);
  });

  it("More → Rename opens the item in the main window with its rename field", async () => {
    await renderReview();
    fireEvent.click(button("More actions"));
    expect(button("More actions")).toHaveAttribute("aria-expanded", "true");
    fireEvent.click(screen.getByRole("menuitem", { name: "Rename" }));
    await waitFor(() => expect(callsOf("pending_open")).toEqual([{ id: "pend-1", rename: true }]));
    expect(screen.queryByRole("menu")).not.toBeInTheDocument();
  });

  it("More → Open opens the item in the main window", async () => {
    await renderReview();
    fireEvent.click(button("More actions"));
    fireEvent.click(screen.getByRole("menuitem", { name: "Open" }));
    await waitFor(() => expect(callsOf("pending_open")).toEqual([{ id: "pend-1", rename: false }]));
  });

  it("More → Assign client lists clients with None first and assigns the picked one", async () => {
    await renderReview();
    respond("pending_assign_client", makePendingItem("pend-1", { clientId: "cl-1", clientName: "Acme Ltd" }));
    fireEvent.click(button("More actions"));
    fireEvent.click(screen.getByRole("menuitem", { name: "Assign client" }));
    const list = await screen.findByRole("menu", { name: "Clients" });
    await waitFor(() => expect(within(list).getAllByRole("menuitemradio").map((o) => o.textContent)).toEqual(["None", "Acme Ltd", "Globex"]));
    expect(within(list).getByRole("menuitemradio", { name: "None" })).toHaveAttribute("aria-checked", "true");
    fireEvent.click(within(list).getByRole("menuitemradio", { name: "Acme Ltd" }));
    await waitFor(() => expect(callsOf("pending_assign_client")).toEqual([{ id: "pend-1", clientId: "cl-1" }]));
    await flushAsync();
    expect(screen.queryByTestId("client-list")).not.toBeInTheDocument();
    expect(button("Acme Ltd")).toBeInTheDocument();
  });

  it("the client chip opens the same list and None clears the client", async () => {
    await renderReview(makeReviewPayload({ item: makePendingItem("pend-1", { clientId: "cl-2", clientName: "Globex" }) }));
    respond("pending_assign_client", makePendingItem("pend-1", { clientId: null, clientName: null }));
    fireEvent.click(button("Globex"));
    const list = await screen.findByRole("menu", { name: "Clients" });
    await waitFor(() => expect(within(list).getByRole("menuitemradio", { name: "Globex" })).toHaveAttribute("aria-checked", "true"));
    fireEvent.click(within(list).getByRole("menuitemradio", { name: "None" }));
    await waitFor(() => expect(callsOf("pending_assign_client")).toEqual([{ id: "pend-1", clientId: null }]));
    await flushAsync();
    expect(button("No client")).toBeInTheDocument();
  });

  it("the language chip changes the language Keep uses and offers to remember it for the client", async () => {
    respond("list_clients", { data: [{ id: "cl-1", name: "Acme Ltd", language: "en" }] });
    respond("client_update", { data: { id: "cl-1", name: "Acme Ltd", language: "ur" } });
    await renderReview(makeReviewPayload({ item: makePendingItem("pend-1", { clientId: "cl-1", clientName: "Acme Ltd", language: null }) }));
    respond("pending_set_language", makePendingItem("pend-1", { clientId: "cl-1", clientName: "Acme Ltd", language: "ur" }));
    expect(button("Language: Auto")).toHaveTextContent("Auto");
    fireEvent.click(button("Language: Auto"));
    const list = await screen.findByRole("menu", { name: "Languages" });
    expect(within(list).getAllByRole("menuitemradio").map((o) => o.textContent)).toEqual(["Auto (English / Urdu / Arabic)", "English only", "Urdu (with English words)", "Arabic (with English words)"]);
    // The More languages are one row away.
    fireEvent.click(within(list).getByRole("menuitem", { name: "More languages…" }));
    expect(within(list).getAllByRole("menuitemradio")).toHaveLength(12);
    fireEvent.click(within(list).getByRole("menuitemradio", { name: "Urdu (with English words)" }));
    await waitFor(() => expect(callsOf("pending_set_language")).toEqual([{ id: "pend-1", language: "ur" }]));
    await flushAsync();
    expect(button("Language: Urdu")).toHaveTextContent("UR");
    expect(await screen.findByText("Use Urdu for Acme Ltd from now on?")).toBeInTheDocument();
    fireEvent.click(button("Yes"));
    await waitFor(() => expect(callsOf("client_update")).toEqual([{ id: "cl-1", patch: { language: "ur" } }]));
    expect(screen.queryByTestId("review-learn")).not.toBeInTheDocument();
    fireEvent.click(button("Keep"));
    await waitFor(() => expect(callsOf("pending_keep")).toEqual([{ id: "pend-1" }]));
  });

  it("warns once when English only is picked for a call whose captions show Urdu", async () => {
    await renderReview(makeReviewPayload({ item: makePendingItem("pend-1", { firstLine: "Acha yaar, meeting kab hai?", language: null }) }));
    respond("pending_set_language", makePendingItem("pend-1", { firstLine: "Acha yaar, meeting kab hai?", language: "en" }));
    fireEvent.click(button("Language: Auto"));
    fireEvent.click(within(await screen.findByRole("menu", { name: "Languages" })).getByRole("menuitemradio", { name: "English only" }));

    expect(await screen.findByTestId("english-only-warning")).toHaveTextContent("This call has Urdu in it: English only would translate it.");
    await waitFor(() => expect(callsOf("pending_set_language")).toEqual([{ id: "pend-1", language: "en" }]));
  });

  it("does not warn about English only when the call is in English", async () => {
    await renderReview(makeReviewPayload({ item: makePendingItem("pend-1", { firstLine: "Thanks for joining, shall we start?", language: null }) }));
    respond("pending_set_language", makePendingItem("pend-1", { language: "en" }));
    fireEvent.click(button("Language: Auto"));
    fireEvent.click(within(await screen.findByRole("menu", { name: "Languages" })).getByRole("menuitemradio", { name: "English only" }));

    await waitFor(() => expect(callsOf("pending_set_language")).toHaveLength(1));
    await flushAsync();
    expect(screen.queryByTestId("english-only-warning")).not.toBeInTheDocument();
  });

  it("does not ask to remember a language the client already uses, or for a recording without a client", async () => {
    respond("list_clients", { data: [{ id: "cl-1", name: "Acme Ltd", language: "ur" }] });
    await renderReview(makeReviewPayload({ item: makePendingItem("pend-1", { clientId: "cl-1", clientName: "Acme Ltd", language: "en" }) }));
    respond("pending_set_language", makePendingItem("pend-1", { clientId: "cl-1", clientName: "Acme Ltd", language: "ur" }));
    fireEvent.click(button("Language: English"));
    fireEvent.click(within(await screen.findByRole("menu", { name: "Languages" })).getByRole("menuitemradio", { name: "Urdu (with English words)" }));
    await waitFor(() => expect(callsOf("pending_set_language")).toHaveLength(1));
    await flushAsync();
    expect(screen.queryByTestId("review-learn")).not.toBeInTheDocument();
  });

  it("Back closes the client list without assigning", async () => {
    await renderReview();
    fireEvent.click(button("No client"));
    await screen.findByRole("menu", { name: "Clients" });
    fireEvent.click(button("Back"));
    expect(screen.queryByTestId("client-list")).not.toBeInTheDocument();
    expect(callsOf("pending_assign_client")).toHaveLength(0);
  });

  it("shows why the client list could not load", async () => {
    fail("list_clients", "Not paired");
    await renderReview();
    fireEvent.click(button("No client"));
    expect(await screen.findByRole("alert")).toHaveTextContent("Not paired");
    expect(screen.getByRole("menuitemradio", { name: "None" })).toBeInTheDocument();
  });

  it("shows a failed decision's message and resyncs with the core", async () => {
    fail("pending_keep", "This recording is no longer waiting.");
    await renderReview();
    respond("review_state", null);
    fireEvent.click(button("Keep"));
    await waitFor(() => expect(callsOf("review_state").length).toBeGreaterThan(1));
    await flushAsync();
    expect(card()).not.toBeInTheDocument();
  });

  it("closes the menu on a click elsewhere on the card", async () => {
    await renderReview();
    fireEvent.click(button("More actions"));
    expect(screen.getByRole("menu", { name: "More actions" })).toBeInTheDocument();
    fireEvent.mouseDown(screen.getByText("Zoom call"));
    expect(screen.queryByRole("menu", { name: "More actions" })).not.toBeInTheDocument();
  });

  it("follows renames and client changes made in the main window", async () => {
    await renderReview();
    await emitEvent("pending:changed", [makePendingItem("pend-1", { title: "Renamed elsewhere", clientName: "Globex", clientId: "cl-2" })]);
    expect(screen.getByText("Renamed elsewhere")).toBeInTheDocument();
    expect(button("Globex")).toBeInTheDocument();
  });

  it("asks the core again when the shown item leaves the pending list", async () => {
    await renderReview();
    respond("review_state", null);
    await emitEvent("pending:changed", [makePendingItem("pend-7")]);
    await flushAsync();
    expect(card()).not.toBeInTheDocument();
  });

  it("removes its event listeners on unmount", async () => {
    const { unmount } = await renderReview();
    const events = ["review:show", "review:hide", "ask:show", "ask:hide", "pending:changed", "settings:changed"];
    await waitFor(() => expect(events.map(listenerCount)).toEqual(events.map(() => 1)));
    unmount();
    await waitFor(() => expect(events.map(listenerCount)).toEqual(events.map(() => 0)));
  });
});

describe("review countdown", () => {
  const deadlineAt = "2026-10-24T09:13:30Z";

  beforeEach(() => {
    resetTauriMock();
    vi.stubGlobal("ResizeObserver", NoopResizeObserver);
    respond("overlay_set_hit_regions", undefined);
    vi.useFakeTimers({ toFake: ["Date", "setInterval", "clearInterval"] });
    vi.setSystemTime(new Date("2026-10-24T09:12:48Z"));
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  const countdown = () => screen.getByTestId("review-countdown");

  it("counts down to keeping the recording", async () => {
    await renderReview(makeReviewPayload({ timeout: "keep", item: makePendingItem("pend-1", { deadlineAt }) }));
    expect(countdown()).toHaveTextContent("Keeping in 42 s");
    await act(async () => {
      await vi.advanceTimersByTimeAsync(2_000);
    });
    expect(countdown()).toHaveTextContent("Keeping in 40 s");
  });

  it("counts down to discarding the recording", async () => {
    await renderReview(makeReviewPayload({ timeout: "discard", item: makePendingItem("pend-1", { deadlineAt }) }));
    expect(countdown()).toHaveTextContent("Discarding in 42 s");
  });

  it("shows no countdown when the policy is to keep waiting", async () => {
    await renderReview(makeReviewPayload({ timeout: "wait", item: makePendingItem("pend-1", { deadlineAt: null }) }));
    expect(countdown()).toBeEmptyDOMElement();
  });

  it("follows a policy change made while the card is shown", async () => {
    await renderReview(makeReviewPayload({ timeout: "keep", item: makePendingItem("pend-1", { deadlineAt }) }));
    await emitEvent("settings:changed", makeSettings({ decisionTimeout: "discard" }));
    expect(countdown()).toHaveTextContent("Discarding in 42 s");
    await emitEvent("settings:changed", makeSettings({ decisionTimeout: "wait" }));
    expect(countdown()).toBeEmptyDOMElement();
  });

  it("never counts below zero", async () => {
    await renderReview(makeReviewPayload({ timeout: "keep", item: makePendingItem("pend-1", { deadlineAt: "2026-10-24T09:12:00Z" }) }));
    expect(countdown()).toHaveTextContent("Keeping in 0 s");
  });
});

describe("ask chip", () => {
  beforeEach(() => {
    resetTauriMock();
    vi.stubGlobal("ResizeObserver", NoopResizeObserver);
    respondAll({ overlay_set_hit_regions: undefined, ask_answer: undefined });
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it("names the site for a browser and offers Record, Ignore, Always ignore this site", async () => {
    await renderReview(null);
    await emitEvent("ask:show", makeAskPayload({ name: "chrome.exe", site: "call.example.test" }));
    expect(chip()).toBeInTheDocument();
    expect(screen.getByTestId("ask-text")).toHaveTextContent("call.example.test is using the microphone");
    expect(button("Record")).toBeInTheDocument();
    expect(button("Ignore")).toBeInTheDocument();
    expect(button("Always ignore this site")).toBeInTheDocument();
  });

  it("names the app and offers Always ignore this app for a non-browser", async () => {
    await renderReview(null);
    await emitEvent("ask:show", makeAskPayload({ name: "Softphone.exe", site: null }));
    expect(screen.getByTestId("ask-text")).toHaveTextContent("Softphone is using the microphone");
    expect(button("Always ignore this app")).toBeInTheDocument();
  });

  it.each([
    ["Record", "record"],
    ["Ignore", "ignore"],
    ["Always ignore this site", "always_ignore"],
  ])("%s answers %s and closes the chip", async (name, answer) => {
    await renderReview(null);
    await emitEvent("ask:show", makeAskPayload({ id: "ask-7" }));
    fireEvent.click(button(name));
    await waitFor(() => expect(callsOf("ask_answer")).toEqual([{ id: "ask-7", answer }]));
    await flushAsync();
    expect(chip()).not.toBeInTheDocument();
  });

  it("keeps the chip and shows the message when the answer fails", async () => {
    fail("ask_answer", "Not paired");
    await renderReview(null);
    await emitEvent("ask:show", makeAskPayload());
    fireEvent.click(button("Record"));
    expect(await screen.findByRole("alert")).toHaveTextContent("Not paired");
    expect(chip()).toBeInTheDocument();
  });

  it("hides itself after the payload's seconds", async () => {
    await renderReview(null);
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    await emitEvent("ask:show", makeAskPayload({ seconds: 8 }));
    await act(async () => {
      await vi.advanceTimersByTimeAsync(7_900);
    });
    expect(chip()).toBeInTheDocument();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(200);
    });
    expect(chip()).not.toBeInTheDocument();
    expect(callsOf("ask_answer")).toHaveLength(0);
  });

  it("goes away on ask:hide", async () => {
    await renderReview(null);
    await emitEvent("ask:show", makeAskPayload());
    await emitEvent("ask:hide", null);
    expect(chip()).not.toBeInTheDocument();
  });

  it("shows in front of a waiting card, which comes back when the chip goes", async () => {
    await renderReview();
    await emitEvent("ask:show", makeAskPayload());
    expect(chip()).toBeInTheDocument();
    expect(card()).not.toBeInTheDocument();
    await emitEvent("ask:hide", null);
    expect(card()).toBeInTheDocument();
  });
});

describe("review hit regions", () => {
  const rect = (x: number, y: number, w: number, h: number) => ({ left: x, top: y, width: w, height: h, right: x + w, bottom: y + h, x, y, toJSON: () => ({}) }) as DOMRect;

  beforeEach(() => {
    resetTauriMock();
    vi.stubGlobal("ResizeObserver", NoopResizeObserver);
    vi.stubGlobal("devicePixelRatio", 2);
    vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(function (this: HTMLElement) {
      return this.hasAttribute("data-hit") ? rect(10, 10, 340, 148) : rect(0, 0, 0, 0);
    });
    respondAll({ overlay_set_hit_regions: undefined, ask_answer: undefined });
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  const lastRegions = () => {
    const calls = callsOf("overlay_set_hit_regions");
    return calls[calls.length - 1]?.regions;
  };
  const cardRegion = [{ x: (10 - 6) * 2, y: (10 - 6) * 2, w: (340 + 12) * 2, h: (148 + 12) * 2 }];

  it("reports no hit regions when there is nothing to show", async () => {
    await renderReview(null);
    await waitFor(() => expect(lastRegions()).toEqual([]));
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  it("reports the card while it is shown and nothing after it is hidden", async () => {
    await renderReview(null);
    await emitEvent("review:show", makeReviewPayload());
    await waitFor(() => expect(lastRegions()).toEqual(cardRegion));
    await emitEvent("review:hide", null);
    await waitFor(() => expect(lastRegions()).toEqual([]));
  });

  it("reports the ask chip while it is shown", async () => {
    await renderReview(null);
    await emitEvent("ask:show", makeAskPayload());
    await waitFor(() => expect(lastRegions()).toEqual(cardRegion));
    await emitEvent("ask:hide", null);
    await waitFor(() => expect(lastRegions()).toEqual([]));
  });
});
