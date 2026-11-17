import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, renderHook } from "@testing-library/react";
import { ALL_OFF, DESIGN_KEY, DESKTOP_V2_FLAGS, anyV2, chooseDesign, designOffer, parseUiState, readDesign, readUiState, setClassic, useUiFlags } from "./flags";
import { callsOf, fail, flushAsync, resetTauriMock, respond } from "../test/tauri";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);

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
