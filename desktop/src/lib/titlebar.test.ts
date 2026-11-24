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

  it("the v2 styles: the strip and the shell below it apply only with data-titlebar, and only to the classic sidebar", () => {
    const css = read("..", "v2", "styles", "v2.css");
    const rules = [...css.matchAll(/([^{}]+)\{([^{}]*)\}/g)].map((m) => ({ selector: m[1].trim().split("\n").pop()!.trim(), body: m[2] }));
    const strip = rules.filter((r) => r.body.includes("--el-layout-titlebar-macos"));
    expect(strip.length).toBeGreaterThan(0);
    for (const r of strip) expect(r.selector, r.selector).toMatch(/^\[data-titlebar="strip"\]/);
    // The shell below the strip is the classic sidebar's only: the three levels have their title row (UX-20).
    const shellBelow = strip.filter((r) => /\.el-shell\b/.test(r.selector));
    expect(shellBelow.map((r) => r.selector)).toEqual(['[data-titlebar="strip"] .el-shell:not(.el-shell--nav)']);
    // The older sidebar gives back the 40 px it kept for the buttons.
    expect(rules.some((r) => r.selector === '[data-titlebar="strip"] .el-shell > .el-sidebar' && /padding-block-start/.test(r.body))).toBe(true);
    expect(css).toContain('[data-platform="macos"] .el-shell > .el-sidebar { padding-block-start: var(--el-space-40); }');
    // The rail runs to the top: its top cell is the title row's height in a window, 8 px in full screen (no data-titlebar).
    expect(css).toContain('[data-platform="macos"] .el-rail__top { display: block; block-size: var(--el-desktop-titlerow-height); }');
    expect(css).toContain('[data-platform="macos"]:not([data-titlebar]) .el-rail__top { block-size: var(--el-desktop-titlerow-rail-top-fullscreen); }');
    expect(read("..", "tokens.css")).toContain("--el-desktop-titlerow-rail-top-fullscreen: 8px;");
  });

  it("the lights are centred in the 52 px title row, inside the rail's top cell, and their spot is kept free", () => {
    const conf = JSON.parse(read("..", "..", "src-tauri", "tauri.conf.json"));
    const { x, y } = conf.app.windows.find((w: { label: string }) => w.label === "main").trafficLightPosition;
    const tokens = read("..", "tokens.css");
    const px = (name: string) => Number(tokens.match(new RegExp(`--el-desktop-${name}:\\s*(\\d+)px;`))?.[1]);
    // Each 12 px light is centred 2 px below y (tao: a 16 + y title bar, the buttons 6 above its bottom edge).
    expect(y + 2).toBe(px("titlerow-height") / 2);
    // Three lights, 12 px and 8 px apart, from x + 1: inside the 72 px rail, as far from its border as from the window's edge.
    const [from, to] = [x + 1, x + 1 + 3 * 12 + 2 * 8];
    expect(to).toBeLessThanOrEqual(px("rail-width"));
    expect(from).toBe(px("rail-width") - to);
    // Their spot and 4 px round it: no drag region there (.el-rail__lights).
    expect([px("titlerow-lights-left"), px("titlerow-lights-top"), px("titlerow-lights-width"), px("titlerow-lights-height")]).toEqual([from - 4, y - 4 - 4, to - from + 8, 12 + 8]);
  });

  it("MainApp draws the D-214 strip for the classic sidebar only", () => {
    const app = read("..", "views", "main", "MainApp.tsx");
    expect(app).toContain('{!ui.flags["ui_v2.library"] && <div className="el-titlebar" data-tauri-drag-region aria-hidden="true" data-testid="titlebar-strip" />}');
    expect(app).toContain('{!ui.flags["ui_v2.library"] && <div className="titlebar-drag" data-tauri-drag-region aria-hidden="true" />}');
  });
});
