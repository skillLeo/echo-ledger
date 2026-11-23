import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FULLSCREEN_SETTLE_MS, TITLEBAR_KEY, applyTitlebar, titlebarMode, watchTitlebar } from "./titlebar";

const here = dirname(fileURLToPath(import.meta.url));
const read = (...path: string[]) => readFileSync(join(here, ...path), "utf8");
const root = document.documentElement;
const flush = async () => { for (let i = 0; i < 5; i++) await Promise.resolve(); };

describe("the macOS title-bar strip (D-214)", () => {
  beforeEach(() => applyTitlebar("none"));
  afterEach(() => {
    vi.useRealTimers();
    applyTitlebar("none");
  });

  it("is on in a normal Mac window only: not in full screen, never on Windows", () => {
    expect(titlebarMode("macos", false)).toBe("strip");
    expect(titlebarMode("macos", true)).toBe("none");
    expect(titlebarMode("windows", false)).toBe("none");
    expect(titlebarMode("other", false)).toBe("none");
    expect(titlebarMode(undefined, false)).toBe("none");
  });

  it("follows the window into full screen and out again, live, without a reload", async () => {
    vi.useFakeTimers();
    let fullscreen = false;
    const stop = watchTitlebar("macos", async () => fullscreen);
    await flush();
    expect(root.dataset.titlebar).toBe("strip");
    // Into full screen: the page resizes; the strip goes (the buttons come only with the pointer at the top).
    fullscreen = true;
    window.dispatchEvent(new Event("resize"));
    await flush();
    expect(root.dataset.titlebar).toBeUndefined();
    // Out of it: back.
    fullscreen = false;
    window.dispatchEvent(new Event("resize"));
    await flush();
    expect(root.dataset.titlebar).toBe("strip");
    stop();
    fullscreen = true;
    window.dispatchEvent(new Event("resize"));
    await flush();
    expect(root.dataset.titlebar).toBe("strip");
  });

  it("asks again once the full-screen animation is over, so an early answer does not stick", async () => {
    vi.useFakeTimers();
    // macOS says "not yet" while the window grows, then "full screen".
    const answers = [false, false, true];
    const isFullscreen = vi.fn(async () => answers.shift() ?? true);
    const stop = watchTitlebar("macos", isFullscreen);
    await flush();
    window.dispatchEvent(new Event("resize"));
    await flush();
    expect(root.dataset.titlebar).toBe("strip");
    await vi.advanceTimersByTimeAsync(FULLSCREEN_SETTLE_MS);
    await flush();
    expect(isFullscreen).toHaveBeenCalledTimes(3);
    expect(root.dataset.titlebar).toBeUndefined();
    stop();
  });

  it("counts only the newest answer", async () => {
    let first: (v: boolean) => void = () => undefined;
    const calls: Promise<boolean>[] = [new Promise((done) => { first = done; }), Promise.resolve(false)];
    const stop = watchTitlebar("macos", () => calls.shift() ?? Promise.resolve(false));
    window.dispatchEvent(new Event("resize"));
    await flush();
    expect(root.dataset.titlebar).toBe("strip");
    // The first question answers late (full screen): the newer "normal window" stands.
    first(true);
    await flush();
    expect(root.dataset.titlebar).toBe("strip");
    stop();
  });

  it("asks nothing off a Mac, and a question that fails changes nothing", async () => {
    const isFullscreen = vi.fn(async () => false);
    watchTitlebar("windows", isFullscreen)();
    window.dispatchEvent(new Event("resize"));
    await flush();
    expect(isFullscreen).not.toHaveBeenCalled();
    expect(root.dataset.titlebar).toBeUndefined();
    const stop = watchTitlebar("macos", () => Promise.reject(new Error("no window")));
    await flush();
    expect(root.dataset.titlebar).toBeUndefined();
    stop();
  });

  it("the core's start script draws the strip from the first frame, and none after a reload in full screen", () => {
    const start = read("titlebarStart.js");
    const run = () => new Function(start)();
    delete root.dataset.platform;
    sessionStorage.clear();
    // A new main window: never full screen when it is built.
    run();
    expect(root.dataset.platform).toBe("macos");
    expect(root.dataset.titlebar).toBe("strip");
    // The page kept the window's answer: a reload in full screen starts without the strip.
    applyTitlebar("none");
    sessionStorage.setItem(TITLEBAR_KEY, "none");
    run();
    expect(root.dataset.titlebar).toBeUndefined();
    sessionStorage.setItem(TITLEBAR_KEY, "strip");
    run();
    expect(root.dataset.titlebar).toBe("strip");
    sessionStorage.clear();
    delete root.dataset.platform;
  });

  it("keeps the window's answer for the page after a reload", async () => {
    sessionStorage.clear();
    let fullscreen = true;
    const stop = watchTitlebar("macos", async () => fullscreen);
    await flush();
    expect(sessionStorage.getItem(TITLEBAR_KEY)).toBe("none");
    fullscreen = false;
    window.dispatchEvent(new Event("resize"));
    await flush();
    expect(sessionStorage.getItem(TITLEBAR_KEY)).toBe("strip");
    stop();
    sessionStorage.clear();
  });

  it("full screen frees the top: the classic drag strip goes when there is no data-titlebar", () => {
    expect(read("..", "styles.css")).toContain('html[data-platform="macos"]:not([data-titlebar]) .titlebar-drag { display: none; }');
  });

  it("is as tall as the buttons' native row: 16 px buttons + tauri.conf.json's trafficLightPosition.y", () => {
    const conf = JSON.parse(read("..", "..", "src-tauri", "tauri.conf.json"));
    const main = conf.app.windows.find((w: { label: string }) => w.label === "main");
    expect(main.titleBarStyle).toBe("Overlay");
    const height = Number(read("..", "tokens.css").match(/--el-layout-titlebar-macos:\s*(\d+)px;/)?.[1]);
    expect(height).toBe(16 + main.trafficLightPosition.y);
  });

  it("the v2 styles: the strip and the shell below it apply only with data-titlebar, so full screen keeps 2.0.12's rules", () => {
    const css = read("..", "v2", "styles", "v2.css");
    const rules = [...css.matchAll(/([^{}]+)\{([^{}]*)\}/g)].map((m) => ({ selector: m[1].trim().split("\n").pop()!.trim(), body: m[2] }));
    const strip = rules.filter((r) => r.body.includes("--el-layout-titlebar-macos"));
    expect(strip.length).toBeGreaterThan(0);
    for (const r of strip) expect(r.selector, r.selector).toMatch(/^\[data-titlebar="strip"\]/);
    // The rail and the older sidebar give back the 40 px they kept for the buttons, in a normal rail and a short one.
    for (const selector of ['[data-titlebar="strip"] .el-rail', '[data-titlebar="strip"] .el-shell--short-rail .el-rail', '[data-titlebar="strip"] .el-shell > .el-sidebar']) {
      expect(rules.some((r) => r.selector === selector && /padding-block-start/.test(r.body)), selector).toBe(true);
    }
    // 2.0.12's Mac rules are still there for full screen.
    expect(css).toContain('[data-platform="macos"] .el-rail { padding-block-start: var(--el-space-40); }');
    expect(css).toContain('[data-platform="macos"] .el-shell > .el-sidebar { padding-block-start: var(--el-space-40); }');
  });
});
