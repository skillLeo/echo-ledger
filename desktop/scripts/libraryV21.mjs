// The v2.1 Library list on the Mac (docs/PLAN_V2_1_POLISH.md: section 1's names, UX-05's header, UX-06 + DES-04's
// rows), before and after, in the screens fixture, saved under docs/design/screens/desktop/library-v21/:
//   <prefix>-<width>-<lang>-<theme>.png    the list at rest: 1024 and 1280 light, 1280 dark, 1280 Urdu (right to left)
//   compare-<width>-<lang>-<theme>.png     (after only) before and after side by side, when the before shot exists
//   after-1280-en-<theme>-hover.png, after-1280-en-<theme>-selecting.png    a row's hover actions; the selection bar
//   ux25-1280-<lang>-<theme>-last-opened.png, ux25-1280-<lang>-<theme>-back-to-top.png    UX-25, in a short window
// It also counts the rows wholly on screen at 1470x956 (the 14-inch MacBook's window) and, after, fails the run below 12.
//   node scripts/libraryV21.mjs before
//   node scripts/libraryV21.mjs after
import { createServer } from "vite";
import { chromium } from "playwright";
import { existsSync } from "node:fs";
import { mkdir, readFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS as V2_FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = process.env.LIBRARY_V21_OUT ?? join(desktop, "..", "docs", "design", "screens", "desktop", "library-v21");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const prefix = process.argv[2] ?? "after";
const HEIGHT = 900;
const SHOTS = [[1024, "en", "light"], [1280, "en", "light"], [1280, "en", "dark"], [1280, "ur", "light"]];
/** The rows the list must hold at once at 1470x956 (UX-06). */
const ROWS_AT_14_INCH = 12;

/** The list at rest: the fixture opens a recording, its Back (the last "Library" in the window) shows the list. */
async function list(page) {
  await page.getByRole("button", { name: "Library", exact: true }).last().click();
  await page.locator("button[data-row-id]").first().waitFor();
  await page.evaluate(() => document.querySelectorAll("*").forEach((el) => { if (el.scrollTop) el.scrollTop = 0; }));
  await page.mouse.move(0, 0);
  await page.evaluate(() => (document.activeElement instanceof HTMLElement ? document.activeElement.blur() : undefined));
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(400);
}

/**
 * How many rows the window holds: the rows wholly on screen, and (the fixture has only a dozen recordings) how many fit
 * from the first row's top to the window's foot, with room left for three more section headings (a day's list has
 * Pinned, Today, Yesterday and This week). Also where the first row starts.
 */
const rowsOnScreen = (page) => page.evaluate(() => {
  const rows = [...document.querySelectorAll("button[data-row-id]")].map((b) => b.closest("li") ?? b);
  const shown = rows.filter((r) => {
    const box = r.getBoundingClientRect();
    return box.height > 0 && box.top >= 0 && box.bottom <= innerHeight + 0.5;
  }).length;
  const first = rows[0]?.getBoundingClientRect();
  const heading = document.querySelector(".el-lib-list .el-list-section")?.getBoundingClientRect().height ?? 0;
  const fit = first ? Math.floor((innerHeight - first.top - 3 * heading) / first.height) : 0;
  return { shown, fit, firstTop: first ? Math.round(first.top) : null, rowHeight: first ? Math.round(first.height) : null };
});

const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5233, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const shoot = async (page, name) => {
  const file = join(out, name);
  await page.screenshot({ path: file });
  console.log(file);
};
const open = async (width, height, lang, theme) => {
  const page = await browser.newPage({ viewport: { width, height }, colorScheme: theme, reducedMotion: "reduce" });
  await page.goto(`${base}scripts/screens/index.html?screen=library&theme=${theme}&uiflags=${V2_FLAGS}${lang === "ur" ? "&lang=ur" : ""}`);
  await list(page);
  return page;
};
try {
  for (const [width, lang, theme] of SHOTS) {
    const page = await open(width, HEIGHT, lang, theme);
    await shoot(page, `${prefix}-${width}-${lang}-${theme}.png`);
    await page.close();
    const before = join(out, `before-${width}-${lang}-${theme}.png`);
    const after = join(out, `after-${width}-${lang}-${theme}.png`);
    if (prefix === "after" && existsSync(before)) {
      const img = async (f) => `data:image/png;base64,${(await readFile(f)).toString("base64")}`;
      const pair = await browser.newPage({ viewport: { width: width * 2 + 48, height: HEIGHT + 64 }, colorScheme: theme });
      await pair.setContent(`<body style="margin:0;padding:16px;display:flex;gap:16px;background:${theme === "dark" ? "#000" : "#ddd"};font:600 14px system-ui;color:${theme === "dark" ? "#eee" : "#222"}">
        <figure style="margin:0"><figcaption style="margin-bottom:8px">Before (v1.1)</figcaption><img src="${await img(before)}" width="${width}"></figure>
        <figure style="margin:0"><figcaption style="margin-bottom:8px">After (v2.1)</figcaption><img src="${await img(after)}" width="${width}"></figure></body>`);
      await pair.waitForTimeout(200);
      await shoot(pair, `compare-${width}-${lang}-${theme}.png`);
      await pair.close();
    }
  }
  const page = await open(1470, 956, "en", "light");
  const rows = await rowsOnScreen(page);
  await shoot(page, `${prefix}-1470x956-en-light.png`);
  await page.close();
  console.log(`1470x956: ${rows.shown} rows on screen (all the fixture has, or the window's), room for ${rows.fit}; rows ${rows.rowHeight} px, the first at ${rows.firstTop} px`);
  if (prefix === "after" && rows.fit < ROWS_AT_14_INCH) throw new Error(`Only ${rows.fit} rows fit at 1470x956; the list must hold ${ROWS_AT_14_INCH}`);
  const at1280 = await open(1280, 800, "en", "light");
  console.log(`1280: the first row starts at ${(await rowsOnScreen(at1280)).firstTop} px`);
  // Under the shell's 52 px title row (UX-20; the shell gives it, so this fixture still shows the page's own header):
  // where row 2, the first section heading and the first row would start, the header and the shell's top gap gone.
  const under = await at1280.evaluate(() => {
    const top = (sel) => document.querySelector(sel)?.getBoundingClientRect().top ?? NaN;
    const row2 = top(".el-lib-row2");
    const header = document.querySelector(".el-lib-toolbar")?.getBoundingClientRect();
    return { header: header ? Math.round(header.bottom - header.top) : 0, row2: Math.round(row2), heading: Math.round(top(".el-lib-list .el-list-section") - row2), row: Math.round(top("button[data-row-id]") - row2) };
  });
  const TITLE_ROW = 52;
  console.log(`1280 here: the page's own header ${under.header} px, row 2 at ${under.row2} px. Under a ${TITLE_ROW} px title row: row 2 at ${TITLE_ROW} px, the first heading at ${TITLE_ROW + under.heading} px, the first row at ${TITLE_ROW + under.row} px`);
  await at1280.close();
  if (prefix === "after") {
    // Two states at 1280, light and dark: a row's hover actions in place of when and how long, and selection mode, where
    // row 2 becomes the selection bar.
    for (const theme of ["light", "dark"]) {
      const page = await open(1280, HEIGHT, "en", theme);
      await page.locator("li.el-rec").nth(2).hover();
      await page.waitForTimeout(200);
      await shoot(page, `after-1280-en-${theme}-hover.png`);
      await page.locator("li.el-rec").nth(1).hover();
      await page.locator("li.el-rec").nth(1).locator(".el-rec__pick").click();
      await page.locator("li.el-rec").nth(3).locator("button[data-row-id]").click();
      await page.mouse.move(0, 0);
      await page.waitForTimeout(200);
      await shoot(page, `after-1280-en-${theme}-selecting.png`);
      await page.close();
    }
    // UX-25 in a short window (so the list is more than a screen): back from a recording, its row keeps the grey tint
    // (beside a picked row's accent); scrolled down, Back to top at the list's bottom end corner. Light, dark and Urdu.
    for (const [lang, theme] of [["en", "light"], ["en", "dark"], ["ur", "light"]]) {
      const page = await open(1280, 400, lang, theme);
      await page.locator("button[data-row-id]").nth(5).click();
      await page.getByRole("button", { name: "Library", exact: true }).last().click();
      await page.locator(".el-rec--last").waitFor();
      // The next row picked (J, then X): the selection's accent beside the last-opened row's neutral grey.
      await page.keyboard.press("j");
      await page.keyboard.press("x");
      await page.mouse.move(0, 0);
      await page.waitForTimeout(200);
      await shoot(page, `ux25-1280-${lang}-${theme}-last-opened.png`);
      await page.keyboard.press("Escape");
      await page.evaluate(() => { const box = document.querySelector(".content"); if (box) { box.scrollTop = box.scrollHeight; box.dispatchEvent(new Event("scroll")); } });
      await page.waitForTimeout(400);
      await shoot(page, `ux25-1280-${lang}-${theme}-back-to-top.png`);
      await page.close();
    }
  }
} finally {
  await browser.close();
  await server.close();
}
