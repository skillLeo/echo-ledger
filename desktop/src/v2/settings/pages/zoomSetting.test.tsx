// UX-07: Settings › Appearance › Zoom: a stepped slider over Chrome's steps, with Reset and the keys' hint, only with
// v21_zoom; Reset to defaults puts 100% back.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import { SettingsPagesV2 } from "./SettingsPagesV2";
import { makeSettings, makeStatus } from "../../../test/fixtures";
import { flushAsync, resetTauriMock, respond } from "../../../test/tauri";
import { answerConfirm } from "../../../test/confirm";
import { setZoom, zoomNow } from "../../../lib/zoom";
import { findSetting } from "../../../lib/settingsRegistry";

vi.mock("@tauri-apps/api/core", async () => (await import("../../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../../test/tauri")).eventModule);
const webview = vi.hoisted(() => ({ setZoom: vi.fn(async (_factor: number) => undefined) }));
vi.mock("@tauri-apps/api/webview", () => ({ getCurrentWebview: () => webview }));

const show = async (zoom: boolean) => {
  render(<div className="el-root"><SettingsPagesV2 section={{ id: "appearance", seq: 1 }} onHome={vi.fn()} run={vi.fn() as never} status={makeStatus()} onUnpaired={vi.fn()} zoom={zoom} /></div>);
  await act(flushAsync);
};

describe("Settings › Appearance › Zoom (UX-07)", () => {
  beforeEach(() => {
    localStorage.clear();
    resetTauriMock();
    respond("get_settings", makeSettings());
    setZoom(100, false, async () => undefined);
  });
  afterEach(() => cleanup());

  it("only with v21_zoom, and found by search only then", async () => {
    await show(false);
    expect(screen.queryByRole("slider", { name: "Zoom" })).toBeNull();
    expect(findSetting("appearance.zoom")?.requires).toEqual({ zoom: true });
  });

  it("a stepped slider over Chrome's steps, the hint with the keys, Reset while it isn't 100%", async () => {
    await show(true);
    const slider = screen.getByRole("slider", { name: "Zoom" });
    expect(slider).toHaveAttribute("min", "0");
    expect(slider).toHaveAttribute("max", "9");
    expect(slider).toHaveAttribute("aria-valuetext", "100%");
    expect(screen.getByText(/Shortcut: Ctrl\+ and Ctrl−, Ctrl\+0 to reset, or Ctrl \+ the mouse wheel/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Reset to 100%" })).toBeNull();
    // Dragged: the row says the value, the page doesn't rescale under the pointer.
    fireEvent.input(slider, { target: { value: "7" } });
    expect(slider).toHaveAttribute("aria-valuetext", "150%");
    expect(zoomNow()).toBe(100);
    // Let go (or an arrow key): the native change applies it.
    fireEvent.change(slider, { target: { value: "6" } });
    expect(zoomNow()).toBe(125);
    expect(webview.setZoom).toHaveBeenLastCalledWith(1.25);
    expect(screen.getByRole("slider", { name: "Zoom" })).toHaveAttribute("aria-valuetext", "125%");
    fireEvent.click(screen.getByRole("button", { name: "Reset to 100%" }));
    expect(zoomNow()).toBe(100);
  });

  it("Reset to defaults puts 100% back too", async () => {
    await show(true);
    fireEvent.change(screen.getByRole("slider", { name: "Zoom" }), { target: { value: "8" } });
    expect(zoomNow()).toBe(175);
    answerConfirm().mockReturnValue(true);
    fireEvent.click(screen.getByRole("button", { name: "Reset to defaults" }));
    await act(flushAsync);
    expect(zoomNow()).toBe(100);
  });
});
