import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { AfterCallState, PendingItem, RecordingState } from "../../lib/ipc";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { RecordingPill, type PillActions, type PillModel } from "./RecordingPill";
import { AfterCallCard } from "./AfterCallCard";
import { AfterCallStack } from "./AfterCallStack";
import PillApp from "../../views/pill/PillApp";
import ReviewApp from "../../views/review/ReviewApp";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);
vi.mock("@tauri-apps/api/webviewWindow", async () => (await import("../../test/tauri")).windowModule);

/** The menu items' words, in order (menuitem and menuitemradio). */
const menuItems = (root: Element) => [...root.querySelectorAll("[role^=menuitem]")].map((i) => i.textContent);

beforeEach(() => resetTauriMock());

const MODEL: PillModel = { elapsed: "02:14", name: "Omar", paused: false, captions: false, language: "UR+EN", screens: { on: true, count: 3 } };
const actions = (): PillActions => ({
  onCaptions: vi.fn(), onPause: vi.fn(), onStop: vi.fn(), onScreens: vi.fn(), onCapture: vi.fn(), onLanguage: vi.fn(), onChoice: vi.fn(),
});

describe("a kept screen on the pill (screens:captured)", () => {
  it("marks the pill for a moment and says how many screens were kept", async () => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    try {
      render(<RecordingPill model={MODEL} actions={actions()} />);
      await act(async () => { await emitEvent("screens:captured", { count: 4 }); });
      const pill = screen.getByTestId("recording-pill-v2");
      expect(pill).toHaveClass("el-pill--captured");
      expect(within(pill).getByRole("status")).toHaveTextContent("Screen kept. 4 screens in this recording.");
      act(() => vi.advanceTimersByTime(1300));
      expect(pill).not.toHaveClass("el-pill--captured");
    } finally {
      vi.useRealTimers();
    }
  });

  it("under Reduce motion the mark is a still ring, never a flash (the stylesheet says so)", async () => {
    const { readFileSync } = await import("node:fs");
    const { dirname, join } = await import("node:path");
    const { fileURLToPath } = await import("node:url");
    const css = readFileSync(join(dirname(fileURLToPath(import.meta.url)), "..", "styles", "v2.css"), "utf8");
    expect(css).toMatch(/@media \(prefers-reduced-motion: reduce\) \{ \.el-pill--captured \{ animation: none; \} \}/);
    expect(css).toMatch(/\.el-pill--captured \{ box-shadow: [^}]*var\(--el-role-accent-default\); animation: el-captured [^;]* 2; \}/);
  });
});

describe("the recording pill", () => {
  it("reads '● 02:14 · Omar' and opens its controls and the call choice's menu on a press", () => {
    render(<RecordingPill model={MODEL} actions={actions()} />);
    const summary = screen.getByRole("button", { name: "Recording 02:14, Omar. Show the controls" });
    expect(summary).toHaveAttribute("aria-expanded", "false");
    expect(summary).toHaveTextContent("02:14·Omar");
    expect(screen.queryByRole("button", { name: "Captions" })).toBeNull();
    expect(screen.queryByRole("menu")).toBeNull();
    fireEvent.click(summary);
    expect(summary).toHaveAttribute("aria-expanded", "true");
    for (const name of ["Captions", "Pause", "Stop and save", "Capture the screen now", "Language: UR+EN. Change"]) {
      expect(screen.getByRole("button", { name })).toBeInTheDocument();
    }
    expect(screen.getByRole("button", { name: "Captions" })).toHaveAttribute("aria-pressed", "false");
    expect(screen.getByRole("button", { name: /^Screens on, 3 kept/ })).toHaveAttribute("aria-pressed", "true");
    const menu = screen.getByRole("menu", { name: "After each call" });
    expect(menuItems(menu)).toEqual(["Transcribe this call", "Save audio only", "Stop and delete"]);
    expect(menu.textContent).not.toMatch(/\b(Keep|Skip|Discard)\b/);
  });

  it("each control runs its action; the menu's choice goes to the core and closes the menu", () => {
    const a = actions();
    render(<RecordingPill model={MODEL} actions={a} defaultOpen />);
    fireEvent.click(screen.getByRole("button", { name: "Captions" }));
    fireEvent.click(screen.getByRole("button", { name: "Pause" }));
    fireEvent.click(screen.getByRole("button", { name: "Stop and save" }));
    fireEvent.click(screen.getByRole("button", { name: /^Screens on/ }));
    fireEvent.click(screen.getByRole("button", { name: "Capture the screen now" }));
    fireEvent.click(screen.getByRole("button", { name: "Language: UR+EN. Change" }));
    expect([a.onCaptions, a.onPause, a.onStop, a.onScreens, a.onCapture, a.onLanguage].map((f) => (f as ReturnType<typeof vi.fn>).mock.calls.length)).toEqual([1, 1, 1, 1, 1, 1]);
    fireEvent.click(screen.getByRole("menuitemradio", { name: "Transcribe this call" }));
    expect(screen.queryByRole("menu")).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: /Show the controls$/ }));
    fireEvent.click(screen.getByRole("menuitemradio", { name: "Save audio only" }));
    fireEvent.click(screen.getByRole("button", { name: /Show the controls$/ }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Stop and delete" }));
    expect((a.onChoice as ReturnType<typeof vi.fn>).mock.calls).toEqual([["transcribe"], ["audio"], ["delete"]]);
  });

  it("ticks nothing before a choice is made", () => {
    render(<RecordingPill model={{ ...MODEL, choice: null }} actions={actions()} defaultOpen />);
    for (const name of ["Transcribe this call", "Save audio only"]) expect(screen.getByRole("menuitemradio", { name })).toHaveAttribute("aria-checked", "false");
    expect(document.querySelector(".el-pill-menu__mark svg[data-check], .el-pill-menu [aria-checked=true]")).toBeNull();
  });

  it("marks the choice made during the call; Escape closes the menu; a voice note offers only Stop and delete", () => {
    const { unmount } = render(<RecordingPill model={{ ...MODEL, choice: "audio" }} actions={actions()} defaultOpen />);
    expect(screen.getByRole("menuitemradio", { name: "Save audio only" })).toHaveAttribute("aria-checked", "true");
    expect(screen.getByRole("menuitemradio", { name: "Transcribe this call" })).toHaveAttribute("aria-checked", "false");
    fireEvent.keyDown(window, { key: "Escape" });
    expect(screen.queryByRole("menu")).toBeNull();
    unmount();
    render(<RecordingPill model={{ ...MODEL, call: false }} actions={actions()} defaultOpen />);
    expect(menuItems(document.body)).toEqual(["Stop and delete"]);
  });

  it("says when its menu opens and closes, so the window grows only while it shows", () => {
    const onMenu = vi.fn();
    render(<RecordingPill model={MODEL} actions={actions()} onMenu={onMenu} />);
    expect(onMenu.mock.calls).toEqual([[false]]);
    fireEvent.click(screen.getByRole("button", { name: /Show the controls$/ }));
    fireEvent.click(screen.getByRole("menuitemradio", { name: "Save audio only" }));
    expect(onMenu.mock.calls).toEqual([[false], [true], [false]]);
  });

  it("At the start of the call: Recording this call · Don't record beside the pill; doing nothing records", () => {
    const a = actions();
    render(<RecordingPill model={{ ...MODEL, banner: true }} actions={a} />);
    const banner = screen.getByTestId("start-banner");
    expect(banner).toHaveTextContent("Recording this call");
    expect(banner).toHaveAttribute("data-hit");
    fireEvent.click(within(banner).getByRole("button", { name: "Don't record" }));
    expect((a.onChoice as ReturnType<typeof vi.fn>).mock.calls).toEqual([["delete"]]);
  });

  it("paused says so; without screens there is no screens control; an error replaces the name", () => {
    render(<RecordingPill model={{ ...MODEL, paused: true, screens: null, error: "The microphone is busy" }} actions={actions()} defaultOpen />);
    expect(screen.getByRole("button", { name: /^Paused 02:14/ })).toHaveTextContent("The microphone is busy");
    expect(screen.getByRole("button", { name: "Resume" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Capture the screen now" })).toBeNull();
  });
});

const ITEM: PendingItem = {
  id: "p1", sourceApp: "whatsapp", auto: true, mode: "call", title: null, clientId: null, clientName: null,
  startedAt: "2026-11-17T10:00:00Z", endedAt: "2026-11-17T10:00:44Z", durationMs: 44_000, firstLine: null,
  deadlineAt: new Date(Date.parse("2026-11-17T10:04:20Z") + 8_000).toISOString(), callerName: "Omar Farooq",
};

describe("the after-call card (the call choice)", () => {
  const cardActions = () => ({ onTranscribe: vi.fn(), onSaveAudio: vi.fn(), onDelete: vi.fn(), onOpen: vi.fn() });

  it("the logo, the name and 0:44 · just now; Delete quiet, Transcribe the one filled button; the × saves the audio only", () => {
    const a = cardActions();
    render(<AfterCallCard item={ITEM} screens={3} actions={a} now={Date.parse("2026-11-17T10:04:20Z")} />);
    const card = screen.getByRole("region", { name: "Omar Farooq" });
    expect(card).toHaveTextContent("0:44 · just now · 3 screens");
    expect(card).toHaveTextContent("Close to keep audio only");
    expect(card).toHaveAccessibleDescription("Close to keep audio only");
    expect(card.querySelector(".el-aftercall__logo .source-logo"), "WhatsApp's own logo, as in the Library").not.toBeNull();
    const buttons = within(card).getAllByRole("button").map((b) => b.getAttribute("aria-label") ?? b.textContent);
    expect(buttons).toEqual(["Omar Farooq, 0:44 · just now, 3 screens. Rename or choose a project", "Save audio only", "Delete", "Transcribe"]);
    expect(within(card).getByRole("button", { name: "Transcribe" })).toHaveClass("el-button--primary");
    expect(within(card).getByRole("button", { name: "Delete" })).toHaveClass("el-button--quiet");
    expect(card.querySelectorAll(".el-button--primary")).toHaveLength(1);
    fireEvent.click(within(card).getByRole("button", { name: /Rename or choose a project$/ }));
    fireEvent.click(within(card).getByRole("button", { name: "Transcribe" }));
    fireEvent.click(within(card).getByRole("button", { name: "Delete" }));
    fireEvent.click(within(card).getByRole("button", { name: "Save audio only" }));
    expect([a.onOpen, a.onTranscribe, a.onDelete, a.onSaveAudio].map((f) => f.mock.calls.length)).toEqual([1, 1, 1, 1]);
    expect(card.textContent).not.toMatch(/\b(Keep|Skip|Discard)\b/);
  });

  it("Esc and a swipe away save the audio only; a short drag goes back", () => {
    const a = cardActions();
    render(<AfterCallCard item={ITEM} actions={a} />);
    fireEvent.keyDown(window, { key: "Escape" });
    const card = screen.getByTestId("after-call-card");
    fireEvent.pointerDown(card, { button: 0, clientX: 100, pointerId: 1 });
    fireEvent.pointerMove(card, { clientX: 140, pointerId: 1 });
    expect(card.style.transform).toBe("translateX(40px)");
    fireEvent.pointerUp(card, { clientX: 140, pointerId: 1 });
    expect(a.onSaveAudio).toHaveBeenCalledTimes(1);
    expect(card.style.transform).toBe("");
    fireEvent.pointerDown(card, { button: 0, clientX: 300, pointerId: 1 });
    fireEvent.pointerMove(card, { clientX: 200, pointerId: 1 });
    fireEvent.pointerUp(card, { clientX: 200, pointerId: 1 });
    expect(a.onSaveAudio).toHaveBeenCalledTimes(2);
  });

  it("the 8 s line starts from the time left; the first card says where to change it; the name or Call", () => {
    const { container, rerender } = render(<AfterCallCard item={ITEM} hint actions={cardActions()} now={Date.parse("2026-11-17T10:04:24Z")} />);
    const line = container.querySelector<HTMLElement>(".el-aftercall__line > span")!;
    expect(line.style.animationDuration).toBe("4s");
    expect(line.style.getPropertyValue("--from")).toBe("0.5");
    expect(screen.getByTestId("call-hint")).toHaveTextContent("You can change this in Settings › After each call.");
    rerender(<AfterCallCard item={{ ...ITEM, callerName: null }} actions={cardActions()} />);
    expect(screen.getByRole("region", { name: "Call" })).toBeInTheDocument();
    expect(screen.queryByTestId("call-hint")).toBeNull();
  });
});

describe("the after-call stack", () => {
  const STATE: AfterCallState = { ask: ITEM, hint: false, held: [], done: null, group: null };
  const done = (kind: "transcribe" | "audio" | "delete", offerAlways: "always_audio" | null = null) => ({
    kind, id: "p1", title: null, callerName: "Omar Farooq", sourceApp: "whatsapp", mode: "call" as const, durationMs: 44_000, until: "2026-11-17T10:05:00Z", offerAlways,
  });

  it("each answer goes to the core as the card's choice: Transcribe, Save audio only (×) and Delete", async () => {
    respond("call_card_answer", null);
    render(<AfterCallStack state={STATE} onGone={() => undefined} />);
    for (const name of ["Transcribe", "Save audio only", "Delete"]) {
      fireEvent.click(screen.getByRole("button", { name }));
      await act(flushAsync);
    }
    expect(callsOf("call_card_answer")).toEqual([{ id: "p1", choice: "transcribe" }, { id: "p1", choice: "audio" }, { id: "p1", choice: "delete" }]);
    expect(callsOf("pending_keep")).toEqual([]);
  });

  it("says why when an answer fails, and the card stays", async () => {
    fail("call_card_answer", "This recording is no longer waiting.");
    render(<AfterCallStack state={STATE} onGone={() => undefined} />);
    fireEvent.click(screen.getByRole("button", { name: "Transcribe" }));
    await act(flushAsync);
    expect(screen.getByRole("alert")).toHaveTextContent("This recording is no longer waiting.");
    expect(screen.getByTestId("after-call-card")).toBeInTheDocument();
  });

  it.each([["transcribe", "Transcribing…"], ["audio", "Saved as audio"], ["delete", "Deleted"]] as const)("after %s: %s with Undo, which goes to the core", async (kind, words) => {
    respond("call_undo", null);
    respond("call_done_transcribe", null);
    render(<AfterCallStack state={{ ...STATE, ask: null, done: done(kind) }} onGone={() => undefined} />);
    const line = screen.getByTestId("call-done");
    expect(line).toHaveTextContent(words);
    expect(line).toHaveTextContent("Omar Farooq");
    expect(within(line).queryByRole("button", { name: "Transcribe" }) !== null).toBe(kind === "audio");
    if (kind === "audio") {
      fireEvent.click(within(line).getByRole("button", { name: "Transcribe" }));
      await act(flushAsync);
      expect(callsOf("call_done_transcribe")).toEqual([{ id: "p1" }]);
    }
    fireEvent.click(within(line).getByRole("button", { name: "Undo" }));
    await act(flushAsync);
    expect(callsOf("call_undo")).toEqual([{ id: "p1" }]);
  });

  it("Always do this? [Yes] once, under the confirmation, naming the setting it sets", async () => {
    respond("call_always", null);
    const { rerender } = render(<AfterCallStack state={{ ...STATE, ask: null, done: { ...done("transcribe"), offerAlways: "always_transcribe" } }} onGone={() => undefined} />);
    expect(screen.getByTestId("call-always")).toHaveTextContent("Always do this? Transcribe every call");
    rerender(<AfterCallStack state={{ ...STATE, ask: null, done: done("delete") }} onGone={() => undefined} />);
    expect(screen.queryByTestId("call-always")).toBeNull();
    rerender(<AfterCallStack state={{ ...STATE, ask: null, done: done("audio", "always_audio") }} onGone={() => undefined} />);
    expect(screen.getByTestId("call-always")).toHaveTextContent("Always do this? Save audio only");
    fireEvent.click(screen.getByRole("button", { name: "Yes" }));
    await act(flushAsync);
    expect(callsOf("call_always")).toEqual([{ id: "p1", value: "always_audio" }]);
  });

  it("a new call inside the 5 s keeps the earlier Undo: each call in the grouped card has its own, and Stop and delete's too", async () => {
    respond("call_undo", null);
    respond("call_done_transcribe", null);
    const calls = [{ ...done("audio"), id: "a", callerName: "Omar Khan" }, { ...done("delete"), id: "b", callerName: "A very long name that would not fit beside its buttons at all" }];
    render(<AfterCallStack state={{ ask: null, hint: false, held: [], done: null, group: { count: 2, until: "2026-11-17T10:05:00Z", calls } }} onGone={() => undefined} />);
    const rows = screen.getAllByTestId("call-group-row");
    expect(rows.map((r) => r.textContent)).toEqual(["Saved as audioOmar KhanTranscribeUndo", "DeletedA very long name that would not fit beside its buttons at allUndo"]);
    expect(screen.getByTestId("call-group")).not.toHaveTextContent("calls saved as audio");
    fireEvent.click(within(rows[1]).getByRole("button", { name: "Undo: A very long name that would not fit beside its buttons at all" }));
    await act(flushAsync);
    fireEvent.click(within(rows[0]).getByRole("button", { name: "Transcribe" }));
    await act(flushAsync);
    expect(callsOf("call_undo")).toEqual([{ id: "b" }]);
    expect(callsOf("call_done_transcribe")).toEqual([{ id: "a" }]);
    // The name has a line of its own under what was done, never squeezed beside the buttons.
    expect(rows[1].querySelector(".el-aftercall__done-text")!.children).toHaveLength(2);
  });

  it("the reopened card keeps the rest of the group under it, every Delete with its Undo; 5 calls show the oldest Delete", async () => {
    respond("call_undo", null);
    const del = { ...done("delete"), id: "del", callerName: "Muneeb" };
    render(<AfterCallStack state={{ ask: ITEM, hint: false, held: [del], done: null, group: null }} onGone={() => undefined} />);
    expect(screen.getByTestId("call-held")).toHaveTextContent("DeletedMuneeb");
    fireEvent.click(screen.getByRole("button", { name: "Undo: Muneeb" }));
    await act(flushAsync);
    expect(callsOf("call_undo")).toEqual([{ id: "del" }]);
  });

  it("every Delete row shows; only kept rows fold into +N more; a mixed group says how many calls", () => {
    const calls = [{ ...done("delete"), id: "d1", callerName: "Oldest" }, ...["a1", "a2", "a3", "a4"].map((id) => ({ ...done("audio"), id, callerName: id }))];
    render(<AfterCallStack state={{ ask: null, hint: false, held: [], done: null, group: { count: 5, until: "2026-11-17T10:05:00Z", calls } }} onGone={() => undefined} />);
    expect(screen.getAllByTestId("call-group-row").map((r) => r.querySelector(".el-aftercall__done-name")!.textContent)).toEqual(["Oldest", "a3", "a4"]);
    expect(screen.getByTestId("call-more")).toHaveTextContent("+2 more");
    expect(screen.getByTestId("call-group").querySelector(".el-aftercall__headline")).toHaveTextContent(/^5 calls$/);
  });

  it("back to back: one grouped card, never a stack; Review opens the Library", async () => {
    respond("call_group_close", null);
    const onGone = vi.fn();
    const all = ["g1", "g2", "g3"].map((id) => ({ ...done("audio"), id }));
    render(<AfterCallStack state={{ ask: null, hint: false, held: [], done: done("audio"), group: { count: 3, until: "2026-11-17T10:05:00Z", calls: all } }} onGone={onGone} />);
    expect(screen.getByTestId("call-group")).toHaveTextContent("3 calls saved as audio");
    expect(screen.getAllByTestId("call-group-row")).toHaveLength(3);
    expect(screen.queryByTestId("call-done")).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Review" }));
    await act(flushAsync);
    expect(callsOf("call_group_close")).toEqual([{ review: true }]);
    expect(onGone).toHaveBeenCalledWith("group");
  });
});

describe("the windows with the flags", () => {
  const REC: RecordingState = {
    active: true, paused: false, elapsedMs: 134_000, sourceApp: "whatsapp", mode: "call", captions: false, levels: [0, 0],
    auto: true, hidden: false, language: null, bluetoothMic: false, screens: null,
  };

  it("ui_v2.recording_controls draws the new pill in the pill window", async () => {
    respond("ui_flags", { ui_flags: { "ui_v2.recording_controls": true } });
    respond("recording_state", REC);
    respond("set_hit_regions", null);
    respond("window_ready", null);
    render(<PillApp />);
    await act(flushAsync);
    await act(() => emitEvent("pill:visibility", true));
    await act(flushAsync);
    expect(await screen.findByTestId("recording-pill-v2")).toBeInTheDocument();
    expect(screen.queryByTestId("recording-pill")).toBeNull();
  });

  it("ui_v2.after_call draws the new card in the after-call window", async () => {
    respond("ui_flags", { ui_flags: { "ui_v2.after_call": true } });
    respond("after_call_state", { ask: ITEM, hint: false, held: [], done: null, group: null });
    respond("set_hit_regions", null);
    respond("window_ready", null);
    render(<ReviewApp />);
    await act(flushAsync);
    await act(flushAsync);
    expect(await screen.findByTestId("after-call-card")).toBeInTheDocument();
  });
});

describe("flags reach the overlay windows on their next show", () => {
  const REC_ON: RecordingState = {
    active: true, paused: false, elapsedMs: 10_000, sourceApp: "zoom", mode: "call", captions: false, levels: [0, 0],
    auto: true, hidden: false, language: null, bluetoothMic: false, screens: null,
  };

  it("the pill: a flag turned on between two recordings shows the new pill on the next one, never mid-recording", async () => {
    respond("ui_flags", { ui_flags: {} });
    respond("recording_state", { ...REC_ON, active: false });
    respond("set_hit_regions", null);
    respond("window_ready", null);
    render(<PillApp />);
    await act(flushAsync);
    await act(() => emitEvent("recording:state", REC_ON));
    await act(flushAsync);
    expect(screen.getByTestId("recording-pill")).toBeInTheDocument();
    respond("ui_flags", { ui_flags: { "ui_v2.recording_controls": true } });
    await act(() => emitEvent("recording:state", { ...REC_ON, elapsedMs: 11_000 }));
    await act(flushAsync);
    expect(screen.getByTestId("recording-pill")).toBeInTheDocument();
    await act(() => emitEvent("recording:state", { ...REC_ON, active: false }));
    await act(flushAsync);
    await act(() => emitEvent("recording:state", REC_ON));
    await act(flushAsync);
    expect(await screen.findByTestId("recording-pill-v2")).toBeInTheDocument();
    expect(callsOf("ui_flags")).toHaveLength(3);
  });

  it("the after-call window: switching back to classic between two shows draws the classic card next time", async () => {
    respond("ui_flags", { ui_flags: { "ui_v2.after_call": true } });
    respond("after_call_state", { ask: null, hint: false, held: [], done: null, group: null });
    respond("set_hit_regions", null);
    respond("window_ready", null);
    render(<ReviewApp />);
    await act(flushAsync);
    await act(() => emitEvent("aftercall:state", { ask: ITEM, hint: false, held: [], done: null, group: null }));
    await act(flushAsync);
    expect(await screen.findByTestId("after-call-card")).toBeInTheDocument();
    respond("ui_flags", { ui_flags: {}, ui_classic: true });
    await act(() => emitEvent("aftercall:state", { ask: null, hint: false, held: [], done: null, group: null }));
    await act(flushAsync);
    await act(() => emitEvent("aftercall:state", { ask: { ...ITEM, id: "p9" }, hint: false, held: [], done: null, group: null }));
    await act(flushAsync);
    expect(screen.queryByTestId("after-call-card")).toBeNull();
    expect(document.querySelector(".review-root .call-card")!.textContent).toMatch(/Omar Farooq/);
  });
});
