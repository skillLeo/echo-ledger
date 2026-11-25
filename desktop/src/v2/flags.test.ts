import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, renderHook } from "@testing-library/react";
import { useLayoutEffect, useState } from "react";
import { ALL_OFF, DESIGN_KEY, DESKTOP_V2_FLAGS, READ_RETRY_MS, anyV2, applyDesign, chooseDesign, designOffer, parseUiState, readDesign, readUiState, setClassic, useUiFlags } from "./flags";
import { callsOf, emitEvent, fail, flushAsync, listen, listenerCount, resetTauriMock, respond, respondWith } from "../test/tauri";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../test/tauri")).eventModule);

beforeEach(() => {
  resetTauriMock();
  localStorage.clear();
});
/** Classic chosen on this computer. */
const CLASSIC = { ...ALL_OFF, classic: true };
afterEach(() => window.history.replaceState(null, "", "/"));

describe("reading the interface flags", () => {
  it("keeps the known flags, ignores unknown ones and treats missing or non-true as off", () => {
    const state = parseUiState({ ui_flags: { "ui_v2.shell": true, "ui_v2.library": "yes", "ui_v2.future": true }, ui_classic: false });
    expect(state.flags["ui_v2.shell"]).toBe(true);
    expect(state.flags["ui_v2.library"]).toBe(false);
    expect(state.flags["ui_v2.settings"]).toBe(false);
    expect(Object.keys(state.flags)).not.toContain("ui_v2.future");
    expect(parseUiState(null)).toEqual(ALL_OFF);
    expect(parseUiState({ ui_flags: "nope" })).toEqual(ALL_OFF);
  });

  it("call_rules (contract 2.0.0-w) is a known feature flag: read as sent, and the design chosen here never changes it", () => {
    const on = parseUiState({ ui_flags: { call_rules: true }, ui_classic: false });
    expect(on.flags.call_rules).toBe(true);
    expect(parseUiState({ ui_flags: { call_rules: "yes" } }).flags.call_rules).toBe(false);
    expect(applyDesign(on, "classic").flags.call_rules).toBe(true);
    expect(applyDesign(on, "v2").flags.call_rules).toBe(true);
    expect(applyDesign(parseUiState({ ui_flags: {} }), "v2").flags.call_rules).toBe(false);
  });

  it("an older core without the command means the classic screens", async () => {
    expect(await readUiState()).toEqual(ALL_OFF);
    fail("ui_flags", "unknown command");
    expect(await readUiState()).toEqual(ALL_OFF);
  });

  it("an older server (no ui_design), nothing chosen here: its flags stand; classic chosen here shows none of them; a feature flag stays the server's", async () => {
    respond("ui_flags", { ui_flags: { "ui_v2.shell": true, "ui_v2.library": true, live_assist: true }, ui_classic: false });
    expect(readDesign()).toBeNull();
    expect(anyV2(await readUiState())).toBe(true);
    await chooseDesign("classic");
    const state = await readUiState();
    expect(anyV2(state)).toBe(false);
    expect(state.classic).toBe(true);
    expect(state.flags.live_assist).toBe(true);
    expect(callsOf("ui_design_set")).toHaveLength(0);
    expect(anyV2(parseUiState({ ui_flags: { live_assist: true } }))).toBe(false);
  });

  it("a 2.0.0-g server: its flags already follow the person's design, and the choice is its PUT, never kept here", async () => {
    const design = (current: "v2" | "classic") => ({ choice: current, current, can_try: current === "classic", can_switch_back: current === "v2" });
    respond("ui_flags", { ui_flags: {}, ui_classic: false, ui_design: { choice: null, current: "classic", can_try: true, can_switch_back: false } });
    localStorage.setItem(DESIGN_KEY, "v2"); // an old local choice: the server's answer wins
    const before = await readUiState();
    expect(anyV2(before)).toBe(false);
    expect(designOffer(before)).toEqual({ canTry: true, canSwitchBack: false });
    respond("ui_design_set", { ui_flags: { "ui_v2.shell": true }, ui_classic: false, ui_design: design("v2") });
    await chooseDesign("v2");
    expect(callsOf("ui_design_set")).toEqual([{ design: "v2" }]);
    respond("ui_flags", { ui_flags: { "ui_v2.shell": true }, ui_classic: false, ui_design: design("v2") });
    const after = await readUiState();
    expect(after.flags["ui_v2.shell"]).toBe(true);
    expect(after.flags["ui_v2.library"]).toBe(false); // what the server says, not "everything"
    expect(designOffer(after)).toEqual({ canTry: false, canSwitchBack: true });
  });

  it("an older server: the new design chosen here turns on every desktop v2 screen, never the web's; back to classic turns them all off", async () => {
    respond("ui_flags", { ui_flags: {}, ui_classic: false });
    expect(designOffer(await readUiState())).toEqual({ canTry: true, canSwitchBack: false });
    await chooseDesign("v2");
    expect(localStorage.getItem(DESIGN_KEY)).toBe("v2");
    const v2 = await readUiState();
    for (const n of DESKTOP_V2_FLAGS) expect(v2.flags[n], n).toBe(true);
    expect(v2.flags["ui_v2.admin"]).toBe(false);
    expect(v2.flags["ui_v2.website"]).toBe(false);
    expect(v2.classic).toBe(false);
    expect(DESKTOP_V2_FLAGS).toEqual(expect.arrayContaining(["ui_v2.shell", "ui_v2.library", "ui_v2.settings", "ui_v2.recording_controls", "ui_v2.after_call"]));
    expect(designOffer(v2)).toEqual({ canTry: false, canSwitchBack: true });
    await chooseDesign("classic");
    expect(await readUiState()).toEqual(CLASSIC);
  });

  it("a development build may force flags from the address", async () => {
    window.history.replaceState(null, "", "/?uiflags=ui_v2.shell,ui_v2.library");
    const state = await readUiState();
    expect(state.flags["ui_v2.shell"]).toBe(true);
    expect(state.flags["ui_v2.library"]).toBe(true);
    expect(state.flags["ui_v2.settings"]).toBe(false);
    expect(callsOf("ui_flags")).toHaveLength(0);
  });

  it("Switch back to classic sends the choice and takes the server's new answer", async () => {
    respond("ui_classic_set", { ui_flags: { "ui_v2.shell": false }, ui_classic: true });
    const state = await setClassic(true);
    expect(callsOf("ui_classic_set")).toEqual([{ classic: true }]);
    expect(state.classic).toBe(true);
    expect(anyV2(state)).toBe(false);
  });
});

describe("useUiFlags", () => {
  it("reads at start and again on the next page, not while recording", async () => {
    respond("ui_flags", { ui_flags: { live_assist: true } });
    const { result, rerender } = renderHook(({ screen, recording }) => useUiFlags(true, screen, recording), {
      initialProps: { screen: "library", recording: false },
    });
    await act(flushAsync);
    expect(result.current[0].flags.live_assist).toBe(true);
    expect(callsOf("ui_flags")).toHaveLength(1);

    respond("ui_flags", { ui_flags: { live_assist: false } });
    rerender({ screen: "settings", recording: true });
    await act(flushAsync);
    expect(callsOf("ui_flags")).toHaveLength(1);
    expect(result.current[0].flags.live_assist).toBe(true);

    rerender({ screen: "library", recording: false });
    await act(flushAsync);
    expect(callsOf("ui_flags")).toHaveLength(2);
    expect(result.current[0].flags.live_assist).toBe(false);
  });

  it("follows a design chosen here at once, and one chosen in another window", async () => {
    respond("ui_flags", { ui_flags: {} });
    const { result } = renderHook(() => useUiFlags(true, "library", false));
    await act(flushAsync);
    expect(result.current[0].flags["ui_v2.library"]).toBe(false);
    await act(async () => { await chooseDesign("v2"); });
    await act(flushAsync);
    expect(result.current[0].flags["ui_v2.library"]).toBe(true);
    // Another window chose classic: this one hears the storage change.
    localStorage.setItem(DESIGN_KEY, "classic");
    await act(async () => { window.dispatchEvent(new StorageEvent("storage", { key: DESIGN_KEY, newValue: "classic" })); });
    await act(flushAsync);
    expect(result.current[0].flags["ui_v2.library"]).toBe(false);
  });

  it("asks nothing before the computer is signed in", async () => {
    renderHook(() => useUiFlags(false, "library", false));
    await act(flushAsync);
    expect(callsOf("ui_flags")).toHaveLength(0);
  });
});

describe("useUiFlags hears the core's first answer (ui:flags)", () => {
  /** What the core says before its first GET /api/config answer (ui_flags.rs `cached`): everything off, no design. */
  const BEFORE_CONFIG = { ui_flags: Object.fromEntries(Object.keys(ALL_OFF.flags).map((n) => [n, false])), ui_classic: false, ui_design: null };
  const design = (current: "v2" | "classic") => ({ choice: current, current, can_try: current === "classic", can_switch_back: current === "v2" });
  /** The server's answer for an account on the new design. */
  const V2 = { ui_flags: { "ui_v2.shell": true, "ui_v2.library": true }, ui_classic: false, ui_design: design("v2") };
  /** The same account after switching back to classic. */
  const CLASSIC = { ui_flags: {}, ui_classic: true, ui_design: design("classic") };

  it("a window that read before the core's first config answer takes ui:flags at once, with no screen change", async () => {
    respond("ui_flags", BEFORE_CONFIG);
    const { result } = renderHook(() => useUiFlags(true, "library", false));
    await act(flushAsync);
    expect(result.current[0]).toEqual(ALL_OFF);
    expect(result.current[2]).toBe("library");
    respond("ui_flags", V2);
    await emitEvent("ui:flags", V2);
    expect(result.current[0].flags["ui_v2.shell"]).toBe(true);
    expect(result.current[0].flags["ui_v2.library"]).toBe(true);
    expect(result.current[2]).toBe("library");
  });

  it("the same while recording: nothing changes until the recording ends, then the answer applies", async () => {
    respond("ui_flags", BEFORE_CONFIG);
    const { result, rerender } = renderHook(({ recording }) => useUiFlags(true, "library", recording), { initialProps: { recording: false } });
    await act(flushAsync);
    rerender({ recording: true });
    await act(flushAsync);
    respond("ui_flags", V2);
    await emitEvent("ui:flags", V2);
    await act(flushAsync);
    expect(result.current[0]).toEqual(ALL_OFF);
    expect(callsOf("ui_flags")).toHaveLength(1);
    rerender({ recording: false });
    await act(flushAsync);
    expect(result.current[0].flags["ui_v2.shell"]).toBe(true);
    expect(result.current[0].flags["ui_v2.library"]).toBe(true);
  });

  it("after the server's answer, a later ui:flags change waits for the next screen", async () => {
    respond("ui_flags", BEFORE_CONFIG);
    const { result, rerender } = renderHook(({ screen }) => useUiFlags(true, screen, false), { initialProps: { screen: "library" } });
    await act(flushAsync);
    respond("ui_flags", V2);
    await emitEvent("ui:flags", V2);
    expect(result.current[0].flags["ui_v2.shell"]).toBe(true);
    // The person switched back to classic on the web: this screen stays as it is.
    respond("ui_flags", CLASSIC);
    await emitEvent("ui:flags", CLASSIC);
    await act(flushAsync);
    expect(result.current[0].flags["ui_v2.shell"]).toBe(true);
    rerender({ screen: "settings" });
    await act(flushAsync);
    expect(result.current[0].flags["ui_v2.shell"]).toBe(false);
    expect(result.current[0].classic).toBe(true);
    expect(result.current[2]).toBe("settings");
  });

  it("after a first read that had the server's answer, ui:flags waits for the next screen too", async () => {
    respond("ui_flags", V2);
    const { result, rerender } = renderHook(({ screen }) => useUiFlags(true, screen, false), { initialProps: { screen: "library" } });
    await act(flushAsync);
    expect(result.current[0].flags["ui_v2.shell"]).toBe(true);
    respond("ui_flags", CLASSIC);
    await emitEvent("ui:flags", CLASSIC);
    expect(result.current[0].flags["ui_v2.shell"]).toBe(true);
    rerender({ screen: "settings" });
    await act(flushAsync);
    expect(result.current[0].flags["ui_v2.shell"]).toBe(false);
  });

  it("listens before it asks, and an answer heard while the first read is on its way wins over that read's all-off", async () => {
    let answer: (value: unknown) => void = () => undefined;
    respondWith("ui_flags", () => {
      // The core is asked only once the window hears ui:flags, so no announcement falls between the two.
      expect(listenerCount("ui:flags")).toBe(1);
      return new Promise((done) => { answer = done; });
    });
    const { result } = renderHook(() => useUiFlags(true, "library", false));
    await act(flushAsync);
    expect(callsOf("ui_flags")).toHaveLength(1);
    await emitEvent("ui:flags", V2);
    await act(async () => answer(BEFORE_CONFIG));
    await act(flushAsync);
    expect(result.current[0].flags["ui_v2.shell"]).toBe(true);
    expect(result.current[0].flags["ui_v2.library"]).toBe(true);
  });

  it("an older server (no ui_design): a design chosen here after a ui:flags still decides the screens", async () => {
    respond("ui_flags", BEFORE_CONFIG);
    const { result } = renderHook(() => useUiFlags(true, "library", false));
    await act(flushAsync);
    // The older server's answer: everything off for this person (the core announces a change to it).
    await emitEvent("ui:flags", { ui_flags: {}, ui_classic: false });
    expect(result.current[0]).toEqual(ALL_OFF);
    await act(async () => { await chooseDesign("v2"); });
    await act(flushAsync);
    for (const n of DESKTOP_V2_FLAGS) expect(result.current[0].flags[n], n).toBe(true);
  });

  it("a failed read counts as no answer: ui:flags then applies at once", async () => {
    fail("ui_flags", "the core is busy");
    const { result } = renderHook(() => useUiFlags(true, "library", false));
    await act(flushAsync);
    expect(result.current[0]).toEqual(ALL_OFF);
    await emitEvent("ui:flags", V2);
    expect(result.current[0].flags["ui_v2.shell"]).toBe(true);
  });

  it("before sign-in nothing applies; signed in, the read takes the core's answer", async () => {
    respond("ui_flags", BEFORE_CONFIG);
    const { result, rerender } = renderHook(({ enabled }) => useUiFlags(enabled, "library", false), { initialProps: { enabled: false } });
    await act(flushAsync);
    await emitEvent("ui:flags", V2);
    expect(result.current[0]).toEqual(ALL_OFF);
    expect(callsOf("ui_flags")).toHaveLength(0);
    respond("ui_flags", V2);
    rerender({ enabled: true });
    await act(flushAsync);
    expect(result.current[0].flags["ui_v2.shell"]).toBe(true);
  });

  it("the pill and the after-call card (atOnce: false) never change what they show; they read again before the next one", async () => {
    respond("ui_flags", BEFORE_CONFIG);
    const { result, rerender } = renderHook(({ run }) => useUiFlags(true, run, false, { atOnce: false }), { initialProps: { run: "run-1" } });
    await act(flushAsync);
    expect(result.current[2]).toBe("run-1");
    respond("ui_flags", V2);
    await emitEvent("ui:flags", V2);
    await act(flushAsync);
    expect(result.current[0]).toEqual(ALL_OFF);
    expect(listenerCount("ui:flags")).toBe(0);
    rerender({ run: "run-2" });
    await act(flushAsync);
    expect(result.current[0].flags["ui_v2.shell"]).toBe(true);
  });

  it("a development build's forced flags stand whatever the core announces", async () => {
    window.history.replaceState(null, "", "/?uiflags=ui_v2.shell");
    const { result } = renderHook(() => useUiFlags(true, "library", false));
    await act(flushAsync);
    await emitEvent("ui:flags", CLASSIC);
    expect(result.current[0].flags["ui_v2.shell"]).toBe(true);
    expect(result.current[0].classic).toBe(false);
  });

  /** The core's ui:flags delivered straight to the hook's listener, with no act of its own (inside the caller's). */
  const hear = (payload: unknown) => {
    const registered = listen.mock.calls.filter(([event]) => event === "ui:flags").at(-1);
    (registered?.[1] as (e: { event: string; id: number; payload: unknown }) => void)({ event: "ui:flags", id: 0, payload });
  };
  /** useUiFlags in a view whose recording state is set like MainApp's status: drawn on the next render. */
  function renderRecordingView() {
    let setRecording: (on: boolean) => void = () => undefined;
    const view = renderHook(() => {
      const [recording, set] = useState(false);
      setRecording = set;
      return useUiFlags(true, "library", recording);
    });
    return { ...view, setRecording: (on: boolean) => setRecording(on) };
  }

  it("ui:flags that comes as a recording starts, before that render, waits for the recording's end", async () => {
    respond("ui_flags", BEFORE_CONFIG);
    const { result, setRecording } = renderRecordingView();
    await act(flushAsync);
    respond("ui_flags", V2);
    // The status says recording (not drawn yet) and the core's first answer comes in the same turn.
    await act(async () => {
      setRecording(true);
      hear(V2);
    });
    await act(flushAsync);
    expect(result.current[0]).toEqual(ALL_OFF);
    await act(async () => setRecording(false));
    await act(flushAsync);
    expect(result.current[0].flags["ui_v2.shell"]).toBe(true);
    expect(result.current[0].flags["ui_v2.library"]).toBe(true);
  });

  it("a read that answers as a recording starts, before that render, waits for the recording's end too", async () => {
    let answer: (value: unknown) => void = () => undefined;
    respondWith("ui_flags", () => new Promise((done) => { answer = done; }));
    const { result, setRecording } = renderRecordingView();
    await act(flushAsync);
    expect(callsOf("ui_flags")).toHaveLength(1);
    await act(async () => {
      setRecording(true);
      answer(V2);
      for (let i = 0; i < 25; i++) await Promise.resolve();
    });
    await act(flushAsync);
    expect(result.current[0]).toEqual(ALL_OFF);
    respond("ui_flags", V2);
    await act(async () => setRecording(false));
    await act(flushAsync);
    expect(result.current[0].flags["ui_v2.shell"]).toBe(true);
  });

  describe("a failed first read", () => {
    afterEach(() => vi.useRealTimers());

    it("is tried again before the window waits for ui:flags: the answer it then has holds a later change for the next screen", async () => {
      vi.useFakeTimers();
      let calls = 0;
      respondWith("ui_flags", () => (++calls === 1 ? Promise.reject("the core is busy") : CLASSIC));
      const { result, rerender } = renderHook(({ screen }) => useUiFlags(true, screen, false), { initialProps: { screen: "library" } });
      await act(flushAsync);
      expect(result.current[0]).toEqual(ALL_OFF);
      await act(async () => void vi.advanceTimersByTime(READ_RETRY_MS[0]));
      await act(flushAsync);
      expect(callsOf("ui_flags")).toHaveLength(2);
      expect(result.current[0].classic).toBe(true);
      // The new design chosen on the web: the core announces it; this screen stays as it is.
      respond("ui_flags", V2);
      await emitEvent("ui:flags", V2);
      await act(flushAsync);
      expect(result.current[0].flags["ui_v2.shell"]).toBe(false);
      rerender({ screen: "settings" });
      await act(flushAsync);
      expect(result.current[0].flags["ui_v2.shell"]).toBe(true);
    });

    it("tried as often as it may and failing still, leaves the window to take ui:flags at once", async () => {
      vi.useFakeTimers();
      fail("ui_flags", "the core is busy");
      const { result } = renderHook(() => useUiFlags(true, "library", false));
      await act(flushAsync);
      for (const wait of READ_RETRY_MS) {
        await act(async () => void vi.advanceTimersByTime(wait));
        await act(flushAsync);
      }
      expect(callsOf("ui_flags")).toHaveLength(1 + READ_RETRY_MS.length);
      await act(async () => void vi.advanceTimersByTime(60_000));
      expect(callsOf("ui_flags")).toHaveLength(1 + READ_RETRY_MS.length);
      await emitEvent("ui:flags", V2);
      await act(flushAsync);
      expect(result.current[0].flags["ui_v2.shell"]).toBe(true);
    });
  });

  it("the pill and the after-call card (atOnce: false) are never kept waiting by a failed read: they draw classic at once", async () => {
    vi.useFakeTimers();
    try {
      fail("ui_flags", "the core is busy");
      const { result } = renderHook(() => useUiFlags(true, "run-1", false, { atOnce: false }));
      await act(flushAsync);
      expect(result.current[2]).toBe("run-1");
      expect(result.current[0]).toEqual(ALL_OFF);
      await act(async () => void vi.advanceTimersByTime(60_000));
      expect(callsOf("ui_flags")).toHaveLength(1);
    } finally {
      vi.useRealTimers();
    }
  });

  it("ui:flags that comes after a read's answer is drawn but before it is applied is applied after it, not lost", async () => {
    let resolve: (value: unknown) => void = () => undefined;
    respondWith("ui_flags", () => new Promise((done) => { resolve = done; }));
    // The first commit after `armed` (the one that holds the read's answer, before the effect that applies it) is when
    // the core's first ui:flags comes: from a layout effect, which runs between that commit and its passive effects.
    let armed = false;
    const { result } = renderHook(() => {
      const flags = useUiFlags(true, "library", false);
      useLayoutEffect(() => {
        if (!armed) return;
        armed = false;
        hear(V2);
      });
      return flags;
    });
    await act(flushAsync);
    expect(callsOf("ui_flags")).toHaveLength(1);
    await act(async () => {
      armed = true;
      resolve(BEFORE_CONFIG);
      for (let i = 0; i < 25; i++) await Promise.resolve();
    });
    await act(flushAsync);
    expect(armed).toBe(false);
    expect(result.current[0].flags["ui_v2.shell"]).toBe(true);
    expect(result.current[0].flags["ui_v2.library"]).toBe(true);
  });

  it("lets go of ui:flags when the view goes", async () => {
    respond("ui_flags", BEFORE_CONFIG);
    const { unmount } = renderHook(() => useUiFlags(true, "library", false));
    await act(flushAsync);
    expect(listenerCount("ui:flags")).toBe(1);
    unmount();
    await act(flushAsync);
    expect(listenerCount("ui:flags")).toBe(0);
  });
});
