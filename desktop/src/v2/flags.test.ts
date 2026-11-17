import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, renderHook } from "@testing-library/react";
import { ALL_OFF, anyV2, parseUiState, readUiState, setClassic, useUiFlags } from "./flags";
import { callsOf, fail, flushAsync, resetTauriMock, respond } from "../test/tauri";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);

beforeEach(() => resetTauriMock());
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

  it("reads the core's cached answer", async () => {
    respond("ui_flags", { ui_flags: { "ui_v2.shell": true }, ui_classic: false });
    const state = await readUiState();
    expect(state.flags["ui_v2.shell"]).toBe(true);
    expect(anyV2(state)).toBe(true);
    expect(anyV2(parseUiState({ ui_flags: { live_assist: true } }))).toBe(false);
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
    respond("ui_flags", { ui_flags: { "ui_v2.library": true } });
    const { result, rerender } = renderHook(({ screen, recording }) => useUiFlags(true, screen, recording), {
      initialProps: { screen: "library", recording: false },
    });
    await act(flushAsync);
    expect(result.current[0].flags["ui_v2.library"]).toBe(true);
    expect(callsOf("ui_flags")).toHaveLength(1);

    respond("ui_flags", { ui_flags: { "ui_v2.library": false } });
    rerender({ screen: "settings", recording: true });
    await act(flushAsync);
    expect(callsOf("ui_flags")).toHaveLength(1);
    expect(result.current[0].flags["ui_v2.library"]).toBe(true);

    rerender({ screen: "library", recording: false });
    await act(flushAsync);
    expect(callsOf("ui_flags")).toHaveLength(2);
    expect(result.current[0].flags["ui_v2.library"]).toBe(false);
  });

  it("asks nothing before the computer is signed in", async () => {
    renderHook(() => useUiFlags(false, "library", false));
    await act(flushAsync);
    expect(callsOf("ui_flags")).toHaveLength(0);
  });
});
