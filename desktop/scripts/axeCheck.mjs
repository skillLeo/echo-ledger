// FIX-06: axe-core on the new design's main screens, as the person reaches them (the screens fixture, a Mac window
// 1280 × 800, every v2 flag on), light and dark, and the Library and a recording in Urdu too; and at 1024 (the panel a
// flyout over the page, open) and at 200% zoom (places in ⋯ More) for the Library and Settings: the rail and its menus,
// the title row, the Library and a recording, Projects and a project, Activity, Live, Ask, Settings, Setup & health.
// WCAG 2.0/2.1/2.2 A and AA and axe's best practices. It prints every finding by screen and fails on a serious or a
// critical one. Also: every rail item is in the Tab order once (one stop), and ↓ moves along it.
//   npm run check:a11y            (node scripts/axeCheck.mjs [--only=name,name] [--json=FILE])
import { createServer } from "vite";
import { chromium } from "playwright";
import { writeFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS as V2_FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const arg = (name) => process.argv.find((a) => a.startsWith(`--${name}=`))?.split("=")[1];
const only = arg("only")?.split(",");
const json = arg("json");
const axePath = join(desktop, "node_modules", "axe-core", "axe.min.js");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";

const rail = (page) => page.getByRole("navigation", { name: "Main" });
const place = (name) => async (page) => {
  await rail(page).getByRole("button", { name: new RegExp(`^${name}`) }).first().click();
  await page.waitForTimeout(1200);
};
const steps = (...all) => async (page) => {
  for (const s of all) await s(page);
};
/** [name, fixture screen, steps]; a "-win" screen in Windows' frame (a Mac draws its menus itself, outside the page). */
const SCREENS = [
  ["library", "library", place("Library")],
  ["recording", "library", async (page) => { await place("Library")(page); await page.locator("button[data-row-id]").first().click(); await page.waitForTimeout(1500); }],
  ["record-menu-win", "library", async (page) => { await rail(page).getByRole("button", { name: "More ways to record" }).click(); await page.getByRole("menu").waitFor(); }],
  ["avatar-menu", "library", async (page) => { await rail(page).getByRole("button", { name: /^Account/ }).click(); await page.getByRole("menu").waitFor(); }],
  ["title-search", "library", async (page) => { await place("Library")(page); await page.getByTestId("title-row").getByRole("searchbox").first().click(); await page.keyboard.type("invoice"); await page.waitForTimeout(800); }],
  ["projects", "projects", place("Projects")],
  ["project", "projects", steps(place("Projects"), async (page) => { await page.getByRole("button", { name: "Acme Holdings", exact: true }).first().click(); await page.waitForTimeout(1500); })],
  ["activity", "library", place("Activity")],
  ["live", "library", place("Live")],
  ["ask", "library", place("Ask")],
  ["settings", "settings", place("Settings")],
  ["settings-page", "settings", steps(place("Settings"), async (page) => { await page.getByTestId("nav-panel").getByRole("button", { name: "Recording", exact: true }).click(); await page.waitForTimeout(1200); })],
  ["setup", "settings-mac", place("Setup")],
];
const LANGS = { library: ["en", "ur"], recording: ["en", "ur"] };
/** The window each screen is checked in: 1280 for all; 1024 with the panel's flyout open, and 1280 at 200%, for these. */
const WINDOWS = [{ width: 1280, zoom: 100 }];
const MORE_WINDOWS = { library: [{ width: 1024, zoom: 100, flyout: true }, { width: 1280, zoom: 200 }], settings: [{ width: 1024, zoom: 100, flyout: true }, { width: 1280, zoom: 200 }] };

const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5251, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
const report = [];
const problems = [];

try {
  for (const [name, screen, step] of SCREENS) {
    if (only && !only.includes(name)) continue;
    for (const win of [...WINDOWS, ...(MORE_WINDOWS[name] ?? [])]) for (const lang of win.width === 1280 && win.zoom === 100 ? LANGS[name] ?? ["en"] : ["en"])
    for (const theme of win.width === 1280 && win.zoom === 100 ? ["light", "dark"] : ["light"]) {
      const scale = win.zoom / 100;
      const page = await browser.newPage({ viewport: { width: Math.round(win.width / scale), height: Math.round(800 / scale) }, deviceScaleFactor: scale, colorScheme: theme, reducedMotion: "reduce" });
      page.setDefaultTimeout(120_000);
      if (win.zoom !== 100) await page.addInitScript((kept) => { try { localStorage.setItem("echoledger-zoom", String(kept)); } catch { /* none */ } }, win.zoom);
      const where = name.endsWith("-win") ? "&platform=windows" : "&platform=macos&lights=1";
      await page.goto(`${base}scripts/screens/index.html?screen=${screen}&theme=${theme}&uiflags=${V2_FLAGS}${win.zoom !== 100 ? ",v21_zoom" : ""}${where}${win.zoom !== 100 ? `&zoom=${win.zoom}` : ""}${lang === "ur" ? "&lang=ur" : ""}`);
      await rail(page).waitFor();
      await page.waitForTimeout(1200);
      const label = `${name} ${win.width}${win.zoom !== 100 ? `@${win.zoom}%` : ""}${win.flyout ? " flyout" : ""} ${lang} ${theme}`;
      try {
        if (win.zoom !== 100 && name === "library") {
          // At 200% the Library is in ⋯ More or the avatar's menu: it is the page already, so nothing to press.
        } else await step(page);
        if (win.flyout) await page.evaluate(() => window.dispatchEvent(new Event("echoledger:show-panel")));
        if (win.flyout) await page.getByTestId("nav-panel").waitFor();
      } catch (e) {
        problems.push(`${label}: couldn't get there (${String(e.message).split("\n")[0]})`);
        await page.close();
        continue;
      }
      await page.mouse.move(0, 0);
      await page.addScriptTag({ path: axePath });
      const found = await page.evaluate(async () => {
        // eslint-disable-next-line no-undef
        const r = await axe.run(document, { runOnly: { type: "tag", values: ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa", "best-practice"] } });
        return r.violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.map((n) => n.target.join(" ")).slice(0, 6) }));
      });
      report.push({ screen: label, violations: found });
      for (const v of found) {
        console.log(`${label}: ${v.impact} ${v.id} (${v.help}): ${v.nodes.join(" | ")}`);
        if (v.impact === "serious" || v.impact === "critical") problems.push(`${label}: ${v.impact} ${v.id} at ${v.nodes.join(" | ")}`);
      }
      if (!found.length) console.log(`${label}: nothing found`);
      await page.close();
    }
  }
  if (!only) {
    // The rail by keyboard: one Tab stop, ↓ moves.
    const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, reducedMotion: "reduce" });
    page.setDefaultTimeout(120_000);
    await page.goto(`${base}scripts/screens/index.html?screen=library&uiflags=${V2_FLAGS}&platform=macos&lights=1`);
    await rail(page).waitFor();
    await page.waitForTimeout(1200);
    const stops = await page.evaluate(() => [...document.querySelectorAll(".el-rail button")].filter((b) => b.tabIndex >= 0).map((b) => b.getAttribute("aria-label") ?? b.textContent.trim()));
    console.log(`rail: Tab stops ${stops.join(" | ")}`);
    if (stops.length !== 1) problems.push(`rail: ${stops.length} Tab stops (${stops.join(", ")})`);
    await rail(page).getByRole("button", { name: stops[0] }).focus();
    await page.keyboard.press("ArrowDown");
    const moved = await page.evaluate(() => document.activeElement?.getAttribute("aria-label") ?? document.activeElement?.textContent?.trim());
    console.log(`rail: ↓ from ${stops[0]} to ${moved}`);
    if (!moved || moved === stops[0]) problems.push("rail: ↓ didn't move");
    // The ring: the item's outline, or (the rail's) a 2 px ring round its icon.
    const ring = await page.evaluate(() => {
      const el = document.activeElement;
      const s = getComputedStyle(el);
      if (s.outlineStyle !== "none" && parseFloat(s.outlineWidth) >= 2) return true;
      const icon = el.querySelector(".el-rail__icon");
      return !!icon && /0px 0px 0px 2px/.test(getComputedStyle(icon).boxShadow);
    });
    if (!ring) problems.push("rail: no 2 px focus ring on the item gone to");
    await page.close();
  }
} finally {
  await browser.close();
  await server.close();
}
if (json) await writeFile(json, JSON.stringify(report, null, 2));
if (problems.length) {
  console.error(`\n${problems.length} problem(s):\n${problems.join("\n")}`);
  process.exit(1);
}
