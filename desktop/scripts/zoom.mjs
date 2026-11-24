// UX-07, zoom like Chrome: the main window at 67% to 200% in windows 1024, 1280 and 1440 px wide (740 tall), English and
// Urdu, as the web view draws it: a page that many CSS pixels narrower, drawn that much bigger (deviceScaleFactor), with
// v21_zoom on and that zoom kept (so the title row has its chip). Saved under docs/design/screens/desktop/zoom/:
// <width>-<zoom>-<lang>.png, and the zoom's own parts in light and dark at 1280 and 125%: ui-avatar-row-<theme>.png (the
// avatar's Zoom row), ui-chip-menu-<theme>.png (the chip and its menu), ui-bubble-<theme>.png, ui-settings-<theme>.png
// (Settings › Appearance › Zoom). Measured on each: nothing wider than the window (no sideways scroll), the title row's
// title whole, the title row on screen, every rail item to its last (Account) inside the window, Settings and Account
// shown, the chip in the row unless at 100%, nothing of the page under the window's buttons. The run fails on any of that.
//   node scripts/zoom.mjs [67,100,150,200]
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS as V2_FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = process.env.ZOOM_OUT ?? join(desktop, "..", "docs", "design", "screens", "desktop", "zoom");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const ZOOMS = process.argv[2] ? process.argv[2].split(",").map(Number) : [67, 100, 150, 175, 200];
const FLAGS = `${V2_FLAGS},v21_zoom`;
const WIDTHS = [1024, 1280, 1440];
const HEIGHT = 740;

const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5245, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });

/** The Library at that window and zoom, with v21_zoom on and the zoom kept, as the app opens. */
async function open(width, zoom, lang, theme) {
  const scale = zoom / 100;
  const page = await browser.newPage({ viewport: { width: Math.round(width / scale), height: Math.round(HEIGHT / scale) }, deviceScaleFactor: scale, colorScheme: theme, reducedMotion: "reduce" });
  page.setDefaultTimeout(120_000);
  await page.addInitScript((kept) => { try { localStorage.setItem("echoledger-zoom", String(kept)); } catch { /* none */ } }, zoom);
  await page.goto(`${base}scripts/screens/index.html?screen=library&theme=${theme}&uiflags=${FLAGS}&platform=macos&lights=1&zoom=${zoom}${lang === "ur" ? "&lang=ur" : ""}`);
  await page.getByRole("navigation", { name: "Main" }).getByRole("button", { name: "Library", exact: true }).click();
  await page.locator("button[data-row-id]").first().waitFor();
  if (await page.getByTestId("nav-panel").count() && await page.evaluate(() => innerWidth < 1100)) await page.keyboard.press("Escape");
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(300);
  return page;
}

const problems = [];
try {
  for (const width of WIDTHS) for (const zoom of ZOOMS) for (const lang of ["en", "ur"]) {
    if (lang === "ur" && zoom !== 150) continue;
    const scale = zoom / 100;
    const page = await open(width, zoom, lang, "light");
    const m = await page.evaluate(() => {
      const doc = document.scrollingElement;
      const row = document.querySelector('[data-testid="title-row"]')?.getBoundingClientRect();
      const name = document.querySelector('[data-testid="title-row"] .el-titlerow__name');
      // Every item of the rail to its last (the avatar), not only its box (which the window cuts).
      const items = [...document.querySelectorAll(".el-rail .el-rail__item")];
      const last = items.at(-1)?.getBoundingClientRect();
      const named = items.map((el) => el.getAttribute("aria-label") ?? "");
      const chip = !!document.querySelector('[data-testid="title-row"] .el-zoom-chip');
      const panel = document.querySelector('[data-testid="nav-panel"]');
      // The system's buttons' spot (drawn by the fixture at their native size): nothing of ours that takes a press under it.
      const lights = document.querySelector("[data-traffic-lights]")?.getBoundingClientRect();
      const under = lights ? [...document.querySelectorAll("button, input, a, h1, h2, .el-titlerow__name")].filter((el) => {
        const r = el.getBoundingClientRect();
        return r.width > 0 && r.left < lights.right && r.right > lights.left && r.top < lights.bottom && r.bottom > lights.top;
      }).map((el) => el.getAttribute("aria-label") ?? el.textContent?.trim()) : [];
      return {
        css: innerWidth,
        sideways: doc.scrollWidth > doc.clientWidth + 1,
        cut: name ? name.scrollWidth > name.clientWidth + 0.5 : true,
        row: row ? row.right <= innerWidth + 1 && row.left >= -1 : false,
        rail: !!last && last.bottom <= innerHeight + 1 && named.includes("Settings") && named.some((n) => n.startsWith("Account")),
        chip,
        panel: panel ? (panel.classList.contains("el-panel--flyout") ? "flyout" : "docked") : "hidden",
        under,
      };
    });
    const name = `${width}-${zoom}-${lang}`;
    await page.screenshot({ path: join(out, `${name}.png`) });
    console.log(`${name}: page ${m.css} CSS px; ${m.sideways ? "SIDEWAYS SCROLL" : "fits"}; title ${m.cut ? "CUT" : "whole"}; row ${m.row ? "on screen" : "OFF"}; rail ${m.rail ? "to Account" : "CUT"}; chip ${m.chip ? "shown" : "none"}; panel ${m.panel}${m.under.length ? `; UNDER THE BUTTONS: ${m.under.join(", ")}` : ""}`);
    if (m.sideways) problems.push(`${name}: wider than the window`);
    if (m.cut) problems.push(`${name}: the title is cut`);
    if (!m.row) problems.push(`${name}: the title row is off the screen`);
    if (!m.rail) problems.push(`${name}: the rail is cut before Settings and Account`);
    if (m.chip !== (zoom !== 100)) problems.push(`${name}: the chip is ${m.chip ? "shown at 100%" : "missing"}`);
    if (m.under.length) problems.push(`${name}: under the window's buttons: ${m.under.join(", ")}`);
    await page.close();
  }
  // The zoom's own parts, light and dark, at 1280 and 125%.
  for (const theme of ["light", "dark"]) {
    let page = await open(1280, 125, "en", theme);
    await page.getByRole("navigation", { name: "Main" }).getByRole("button", { name: /^Account/ }).click();
    await page.getByRole("menuitem", { name: "Zoom, 125%" }).waitFor();
    await page.screenshot({ path: join(out, `ui-avatar-row-${theme}.png`) });
    await page.keyboard.press("Escape");
    await page.getByTestId("title-row").getByRole("button", { name: "Zoom, 125%" }).click();
    await page.getByRole("menu", { name: "Zoom, 125%" }).waitFor();
    await page.screenshot({ path: join(out, `ui-chip-menu-${theme}.png`) });
    await page.keyboard.press("Escape");
    // The fixture's computer answers as Windows (its app_status), so Ctrl's keys are its own.
    await page.keyboard.press("Control+=");
    await page.locator(".el-zoom-bubble[data-shown]").waitFor();
    await page.screenshot({ path: join(out, `ui-bubble-${theme}.png`) });
    const bubble = await page.locator(".el-zoom-bubble").textContent();
    if (!bubble?.includes("150%")) problems.push(`ui-bubble-${theme}: says ${bubble}`);
    await page.close();
    page = await open(1280, 125, "en", theme);
    await page.getByRole("navigation", { name: "Main" }).getByRole("button", { name: "Settings", exact: true }).click();
    await page.getByRole("button", { name: /^Appearance/ }).first().click();
    await page.getByRole("slider", { name: "Zoom" }).waitFor();
    await page.getByRole("slider", { name: "Zoom" }).scrollIntoViewIfNeeded();
    await page.screenshot({ path: join(out, `ui-settings-${theme}.png`) });
    console.log(`ui ${theme}: the avatar's row, the chip's menu, the bubble ("${bubble?.trim()}"), Settings › Appearance › Zoom`);
    await page.close();
  }
} finally {
  await browser.close();
  await server.close();
}
if (problems.length) {
  console.error(problems.join("\n"));
  process.exit(1);
}
