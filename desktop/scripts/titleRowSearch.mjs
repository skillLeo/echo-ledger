// The title row's search at the right end (owner's request, Mac 2.0.15): the page's title and count never lose their
// words to the search field. In the screens fixture, a Mac window (740 px tall, the traffic lights drawn), the Library:
//   <prefix>-<width>-<lang>-<theme>.png   at 720, 1024 and 1440, English and Urdu, light and dark,
// saved under docs/design/screens/desktop/title-row/. It also measures, at 720, 900, 1280 and 1440 (and a long project
// name at 1280 and 1024), whether the title is cut, whether its count shows, and the search's form (field or ⌕ button); after
// the change the run fails when a title is cut at 720 px or wider, or a count is not shown.
//   node scripts/titleRowSearch.mjs before
//   node scripts/titleRowSearch.mjs after
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS as V2_FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = process.env.TITLE_ROW_OUT ?? join(desktop, "..", "docs", "design", "screens", "desktop", "title-row");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const prefix = process.argv[2] ?? "after";
const HEIGHT = 740;
const SHOTS = [720, 1024, 1440].flatMap((width) => ["en", "ur"].flatMap((lang) => ["light", "dark"].map((theme) => [width, lang, theme])));
const MEASURED = [720, 900, 1280, 1440];

const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5237, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });

const open = async (width, lang, theme, extra = "") => {
  const page = await browser.newPage({ viewport: { width, height: HEIGHT }, colorScheme: theme, reducedMotion: "reduce" });
  await page.goto(`${base}scripts/screens/index.html?screen=library&theme=${theme}&uiflags=${V2_FLAGS}&platform=macos&lights=1${lang === "ur" ? "&lang=ur" : ""}${extra}`);
  await page.getByRole("button", { name: "Library", exact: true }).last().click();
  await page.locator("button[data-row-id]").first().waitFor();
  // Below 1100 px the panel is a flyout: closed, so the title row shows as the person sees it at rest.
  if (width < 1100 && await page.getByTestId("nav-panel").count()) await page.keyboard.press("Escape");
  return page;
};
const rest = async (page) => {
  await page.mouse.move(0, 0);
  await page.evaluate(() => (document.activeElement instanceof HTMLElement ? document.activeElement.blur() : undefined));
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(400);
};
/** The row as drawn: the title's words whole or cut, the count, the search's form. */
const measure = (page) => page.evaluate(() => {
  const row = document.querySelector('[data-testid="title-row"]');
  const name = row?.querySelector(".el-titlerow__name");
  const count = row?.querySelector(".el-titlerow__count");
  const field = row?.querySelector(".el-titlerow__search");
  const box = (el) => el?.getBoundingClientRect();
  return {
    title: name?.textContent ?? null,
    cut: name ? name.scrollWidth > name.clientWidth + 0.5 : null,
    count: count ? box(count).width > 0 && box(count).right <= box(row).right : false,
    search: field ? `field ${Math.round(box(field).width)} px` : row?.querySelector('[aria-label^="Search"]') ? "button" : "none",
  };
});

const problems = [];
try {
  for (const [width, lang, theme] of SHOTS) {
    const page = await open(width, lang, theme);
    await rest(page);
    const file = join(out, `${prefix}-${width}-${lang}-${theme}.png`);
    await page.screenshot({ path: file });
    console.log(file);
    await page.close();
  }
  for (const width of MEASURED) {
    const page = await open(width, "en", "light");
    await rest(page);
    const m = await measure(page);
    console.log(`${width}: "${m.title}" ${m.cut ? "CUT" : "whole"}, count ${m.count ? "shown" : "MISSING"}, search: ${m.search}`);
    if (prefix === "after" && (m.cut || !m.count)) problems.push(`${width}: the title is ${m.cut ? "cut" : "whole"}, its count ${m.count ? "shown" : "missing"}`);
    await page.close();
  }
  // A long name in the title row (a label's, as a long project's would be) at 1280 and 1024.
  for (const width of [1280, 1024]) {
    const page = await open(width, "en", "light", "&longtitle=1");
    // Below 1100 px the label is in the Library's flyout.
    if (width < 1100) await page.getByRole("navigation", { name: "Main" }).getByRole("button", { name: /^Library/ }).click();
    await page.getByRole("button", { name: /Follow up with Qureshi/ }).first().click();
    if (width < 1100 && await page.getByTestId("nav-panel").count()) await page.keyboard.press("Escape");
    await page.waitForTimeout(600);
    await rest(page);
    const m = await measure(page);
    console.log(`${width}, a long name: "${m.title}" ${m.cut ? "CUT" : "whole"}, count ${m.count ? "shown" : "none"}, search: ${m.search}`);
    if (prefix === "after" && m.cut) problems.push(`${width}: the long name is cut`);
    await page.screenshot({ path: join(out, `${prefix}-${width}-en-light-long-name.png`) });
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
