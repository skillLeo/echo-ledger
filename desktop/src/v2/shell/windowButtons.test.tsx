// Windows' own title row (UX-20, v21_win_titlebar): the app's mark in the rail's top cell and the window's buttons at the
// title row's end, only while the core says the window has no system title bar (<html data-titlebar="win">).
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { NavShell } from "./NavShell";
import { MAXIMIZE_ID } from "./WindowButtons";
import { applyTitlebar, applyWinTitlebar } from "../../lib/titlebar";
import { setZoom } from "../../lib/zoom";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);
const win = vi.hoisted(() => ({
  maximized: false,
  resized: [] as (() => void)[],
  minimize: vi.fn(async () => undefined),
  toggleMaximize: vi.fn(async () => undefined),
  close: vi.fn(async () => undefined),
}));
vi.mock("@tauri-apps/api/window", () => ({
  getCurrentWindow: () => ({
    minimize: win.minimize,
    toggleMaximize: win.toggleMaximize,
    close: win.close,
    isMaximized: async () => win.maximized,
    onResized: async (cb: () => void) => { win.resized.push(cb); return () => { win.resized = win.resized.filter((c) => c !== cb); }; },
  }),
}));

const here = dirname(fileURLToPath(import.meta.url));
const flush = async () => { for (let i = 0; i < 5; i++) await Promise.resolve(); };

function Shell() {
  return (
    <NavShell place="library" onPlace={() => undefined} onHome={() => undefined} status={{ kind: "ready", text: "Ready to record" }}
      onSetupHealth={() => undefined} panels={{ library: <p>Views</p> }} titleEnd={<span data-testid="status-item">Restart to update</span>}>
      <p>The page</p>
    </NavShell>
  );
}

describe("Windows' own title row (UX-20, v21_win_titlebar)", () => {
  beforeEach(() => {
    win.maximized = false;
    win.minimize.mockClear();
    win.toggleMaximize.mockClear();
    win.close.mockClear();
  });
  afterEach(() => applyTitlebar("none"));

  it("with Windows' title bar (the flag off) the page has neither the mark nor the window's buttons", () => {
    const { container } = render(<Shell />);
    expect(screen.queryByTestId("window-buttons")).toBeNull();
    expect(container.querySelector(".el-rail__top .el-rail__mark")).toBeNull();
  });

  it("with its own row: the mark in the rail's top cell; Minimize, Maximize and Close end the row after its status items", async () => {
    applyWinTitlebar(true);
    const { container } = render(<Shell />);
    await act(flush);
    expect(container.querySelector(".el-rail__top .el-rail__mark")).not.toBeNull();
    const buttons = within(screen.getByRole("group", { name: "Window" })).getAllByRole("button");
    expect(buttons.map((b) => b.getAttribute("aria-label"))).toEqual(["Minimize", "Maximize", "Close"]);
    expect(buttons.map((b) => b.getAttribute("title"))).toEqual(["Minimize", "Maximize", "Close"]);
    // As on Windows' own title bar: out of the Tab order (Alt+Space, Win+↑/↓ and Alt+F4 are the keyboard's), never a
    // drag region, and after the page's status items, at the row's very end.
    for (const b of buttons) expect(b).toHaveAttribute("tabindex", "-1");
    const group = screen.getByTestId("window-buttons");
    expect(group).toHaveAttribute("data-tauri-drag-region", "false");
    const end = screen.getByTestId("title-row").querySelector(".el-titlerow__end")!;
    expect(end.lastElementChild).toBe(group);
    expect(screen.getByTestId("status-item").compareDocumentPosition(group) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    // The snap layout plugin's button (wintitle.rs MAXIMIZE_ID).
    expect(buttons[1]).toHaveAttribute("id", MAXIMIZE_ID);
    expect(readFileSync(join(here, "..", "..", "..", "src-tauri", "src", "wintitle.rs"), "utf8")).toContain(`pub const MAXIMIZE_ID: &str = "${MAXIMIZE_ID}";`);
  });

  it("each does what the system's does; Maximize says Restore Down once maximized", async () => {
    applyWinTitlebar(true);
    render(<Shell />);
    await act(flush);
    fireEvent.click(screen.getByRole("button", { name: "Minimize" }));
    fireEvent.click(screen.getByRole("button", { name: "Maximize" }));
    fireEvent.click(screen.getByRole("button", { name: "Close" }));
    expect(win.minimize).toHaveBeenCalledTimes(1);
    expect(win.toggleMaximize).toHaveBeenCalledTimes(1);
    expect(win.close).toHaveBeenCalledTimes(1);
    win.maximized = true;
    await act(async () => { win.resized.forEach((cb) => cb()); await flush(); });
    expect(screen.getByRole("button", { name: "Restore Down" })).toHaveAttribute("title", "Restore Down");
  });

  it("comes and goes with the core's answer while the window is open", async () => {
    const { container } = render(<Shell />);
    expect(screen.queryByTestId("window-buttons")).toBeNull();
    act(() => applyWinTitlebar(true));
    await act(flush);
    expect(screen.getByTestId("window-buttons")).toBeInTheDocument();
    act(() => applyWinTitlebar(false));
    expect(screen.queryByTestId("window-buttons")).toBeNull();
    expect(container.querySelector(".el-rail__mark")).toBeNull();
  });

  it("is drawn with tokens: a 48 px row, 46 px buttons, Close red under the pointer, the plugin's hover for Maximize", () => {
    const css = readFileSync(join(here, "..", "styles", "v2.css"), "utf8");
    expect(css).toContain('[data-titlebar="win"] .el-titlerow, [data-titlebar="win"] .el-panel__head { block-size: var(--el-desktop-titlerow-height-windows); }');
    expect(css).toContain("inline-size: var(--el-desktop-window-button-width)");
    expect(css).toContain(".el-winbutton--close:hover { background: var(--el-role-danger-default); color: var(--el-role-text-on-accent); }");
    expect(css).toContain(".el-winbutton:hover, .el-winbutton.is-hovered {");
    const tokens = readFileSync(join(here, "..", "..", "tokens.css"), "utf8");
    expect(tokens).toContain("--el-desktop-titlerow-height-windows: 48px;");
    expect(tokens).toContain("--el-desktop-window-button-width: 46px;");
  });

  it("zoomed (not 100%), Snap Layouts' target leaves Maximize (the plugin places it in unzoomed pixels); back at 100% it returns", async () => {
    // The plugin's script (tauri-plugin-snap-layout) sends the button's place in CSS pixels and its native side scales
    // them by the screen's DPI only, so at 150% or 200% its window would sit across the title row: it is taken away.
    const snap = { attach: vi.fn(), detach: vi.fn(async () => undefined) };
    (window as unknown as { snapLayout?: typeof snap }).snapLayout = snap;
    const apply = async () => undefined;
    try {
      applyWinTitlebar(true);
      render(<Shell />);
      await act(flush);
      expect(snap.detach).not.toHaveBeenCalled();
      act(() => { setZoom(150, false, apply); });
      expect(snap.detach).toHaveBeenCalledTimes(1);
      act(() => { setZoom(200, false, apply); });
      expect(snap.attach).not.toHaveBeenCalled();
      act(() => { setZoom(100, false, apply); });
      expect(snap.attach).toHaveBeenCalledTimes(1);
    } finally {
      setZoom(100, false, apply);
      delete (window as unknown as { snapLayout?: typeof snap }).snapLayout;
    }
  });
});
