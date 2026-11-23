// The Library filters redesign (PLAN_LIBRARY_FILTERS), before and after: the v2 Library list in the screens fixture at
// each window width, light and dark, English and right to left (lang=ur), saved under
// docs/design/screens/desktop/library-filters/:
//   <prefix>-<width>-<lang>-<theme>.png          the list at rest
//   after-filters-<width>-<lang>-<theme>.png     (after only) the filters open, Source: WhatsApp and Date: Last 7 days on
//   compare-<width>-<lang>-<theme>.png           (after only) before and after side by side, when the before shot exists
//   sidebar-<width>x<height>-<theme>.png        (sidebar) the sidebar's foot on screen at 1440x900 and 1280x720
//   node scripts/libraryFilters.mjs before [1024,1440]
//   node scripts/libraryFilters.mjs after [1024,1440]
//   node scripts/libraryFilters.mjs sidebar      (also checks 1024x640; a foot item cut off fails the run)
import { createServer } from "vite";
import { chromium } from "playwright";
import { existsSync } from "node:fs";
import { mkdir, readFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS as V2_FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "library-filters");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";

const prefix = process.argv[2] ?? "after";
/** The sidebar's foot (Trash, the sync line, Activity, Settings) is wholly inside the window, the middle scrolling. */
async function footOnScreen(page) {
  const problem = await page.evaluate(() => {
    const side = document.querySelector(".el-shell > .el-sidebar");
    if (!side) return "no sidebar";
    const out = [];
    const items = [
      ["Trash", side.querySelector(".el-shell__foot-start .el-nav-item")],
      ["the sync line", side.querySelector("[data-testid=sidebar-synced]")],
      ...[...side.querySelectorAll(".el-shell__foot > .el-nav-item")].slice(0, 2).map((el) => [el.textContent.trim(), el]),
    ];
    for (const [name, el] of items) {
      if (!el) { out.push(`${name} missing`); continue; }
      const r = el.getBoundingClientRect();
      if (r.top < 0 || r.bottom > innerHeight + 0.5 || r.height < 1) out.push(`${name} at ${Math.round(r.top)}-${Math.round(r.bottom)} of ${innerHeight}`);
    }
    const middle = side.querySelector(".el-sidebar__middle");
    if (!middle || getComputedStyle(middle).overflowY !== "auto") out.push("the middle doesn't scroll");
    return out.join("; ");
  });
  if (problem) throw new Error(`Sidebar foot: ${problem}`);
}
const widths = process.argv[3] ? process.argv[3].split(",").map(Number) : [1024, 1440, 800, 1280, 1920];
const HEIGHT = 900;

/** The list at rest: the fixture opens a recording, its Back (the last "Library" in the window) shows the list. */
async function list(page) {
  await page.getByRole("button", { name: "Library", exact: true }).last().click();
  await page.locator("button[data-row-id]").first().waitFor();
}
async function rest(page) {
  await page.evaluate(() => document.querySelectorAll("*").forEach((el) => { if (el.scrollTop) el.scrollTop = 0; }));
  await page.mouse.move(0, 0);
  // Nothing focused: the row the fixture's Back focused shows its hover checkbox and ⋮ otherwise.
  await page.evaluate(() => (document.activeElement instanceof HTMLElement ? document.activeElement.blur() : undefined));
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(400);
}

const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5231, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const shoot = async (page, name) => {
  const file = join(out, name);
  await page.screenshot({ path: file });
  console.log(file);
};
try {
  if (prefix === "sidebar") {
    for (const [width, height, shot] of [[1440, 900, true], [1280, 720, true], [1024, 640, false]]) for (const theme of ["light", "dark"]) {
      const page = await browser.newPage({ viewport: { width, height }, colorScheme: theme, reducedMotion: "reduce" });
      await page.goto(`${base}scripts/screens/index.html?screen=library&theme=${theme}&uiflags=${V2_FLAGS}`);
      await list(page);
      await rest(page);
      await footOnScreen(page);
      if (shot) await shoot(page, `sidebar-${width}x${height}-${theme}.png`);
      else console.log(`${width}x${height} ${theme}: the foot is on screen`);
      await page.close();
    }
  }
  for (const width of prefix === "sidebar" ? [] : widths) for (const lang of ["en", "ur"]) for (const theme of ["light", "dark"]) {
    const url = `${base}scripts/screens/index.html?screen=library&theme=${theme}&uiflags=${V2_FLAGS}${lang === "ur" ? "&lang=ur" : ""}`;
    const page = await browser.newPage({ viewport: { width, height: HEIGHT }, colorScheme: theme, reducedMotion: "reduce" });
    await page.goto(url);
    await list(page);
    await rest(page);
    await shoot(page, `${prefix}-${width}-${lang}-${theme}.png`);
    if (prefix === "after") {
      // The filters open (F), Source: WhatsApp and Date: Last 7 days ticked: the panel (a drawer under 1280 px) and the pills.
      await page.keyboard.press("f");
      const panel = page.getByTestId("filter-panel");
      await panel.waitFor();
      await panel.getByRole("checkbox", { name: /^WhatsApp/ }).first().check();
      await panel.getByRole("radio", { name: /^Last 7 days/ }).check();
      await page.waitForTimeout(300);
      await page.getByRole("group", { name: "Filters on" }).waitFor();
      await page.mouse.move(0, 0);
      await shoot(page, `after-filters-${width}-${lang}-${theme}.png`);
      const before = join(out, `before-${width}-${lang}-${theme}.png`);
      const after = join(out, `after-${width}-${lang}-${theme}.png`);
      if (existsSync(before)) {
        const img = async (f) => `data:image/png;base64,${(await readFile(f)).toString("base64")}`;
        const pair = await browser.newPage({ viewport: { width: width * 2 + 48, height: HEIGHT + 64 }, colorScheme: theme });
        await pair.setContent(`<body style="margin:0;padding:16px;display:flex;gap:16px;background:${theme === "dark" ? "#000" : "#ddd"};font:600 14px system-ui;color:${theme === "dark" ? "#eee" : "#222"}">
          <figure style="margin:0"><figcaption style="margin-bottom:8px">Before (v1.1)</figcaption><img src="${await img(before)}" width="${width}"></figure>
          <figure style="margin:0"><figcaption style="margin-bottom:8px">After</figcaption><img src="${await img(after)}" width="${width}"></figure></body>`);
        await pair.waitForTimeout(200);
        await shoot(pair, `compare-${width}-${lang}-${theme}.png`);
        await pair.close();
      }
    }
    await page.close();
  }
} finally {
  await browser.close();
  await server.close();
}
