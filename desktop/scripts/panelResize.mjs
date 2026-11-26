// UX-03, resizable panels, in the screens fixture (a Mac window, 1440 × 900, the Library), saved under
// docs/design/screens/desktop/panel-resize/:
//   panel-<width>-<lang>-<theme>.png   the place's panel at the usual width (260 here: the fixture's flags have no
//                                      v21_panel_flyout; 240 with it), the most (360) and the least (200)
//   filters-<width>-en-light.png       the filters at the most (420) and the least (280)
// The widths are set as a person does from the keyboard (the edge focused: End, Home), so the edge's focus shows. The run
// fails when a width is not the one asked for, the page's title is cut, or right to left the panel is not on the right.
//   node scripts/panelResize.mjs
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS as V2_FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = process.env.PANEL_RESIZE_OUT ?? join(desktop, "..", "docs", "design", "screens", "desktop", "panel-resize");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const WIDTH = 1440;
const HEIGHT = 900;

const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5243, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });

const open = async (lang, theme) => {
  const page = await browser.newPage({ viewport: { width: WIDTH, height: HEIGHT }, colorScheme: theme, reducedMotion: "reduce" });
  page.setDefaultTimeout(120_000);
  await page.goto(`${base}scripts/screens/index.html?screen=library&theme=${theme}&uiflags=${V2_FLAGS}&platform=macos&lights=1${lang === "ur" ? "&lang=ur" : ""}`);
  await page.locator('nav[data-nav="main"]').getByRole("button", { name: "Library", exact: true }).click();
  await page.locator("button[data-row-id]").first().waitFor();
  await page.evaluate(() => document.fonts.ready);
  return page;
};
const measure = (page, selector) => page.evaluate((sel) => {
  const box = document.querySelector(sel)?.getBoundingClientRect();
  const name = document.querySelector('[data-testid="title-row"] .el-titlerow__name');
  return { width: box ? Math.round(box.width) : 0, left: box ? Math.round(box.left) : 0, cut: name ? name.scrollWidth > name.clientWidth + 0.5 : null };
}, selector);

const problems = [];
const shot = async (page, name, selector, want) => {
  await page.waitForTimeout(300);
  const m = await measure(page, selector);
  await page.screenshot({ path: join(out, `${name}.png`) });
  console.log(`${name}: ${m.width} px${m.cut ? ", the title CUT" : ""}`);
  if (m.width !== want) problems.push(`${name}: ${m.width} px, not ${want}`);
  if (m.cut) problems.push(`${name}: the title is cut`);
  return m;
};
try {
  for (const [lang, theme] of [["en", "light"], ["en", "dark"], ["ur", "light"]]) {
    const page = await open(lang, theme);
    const edge = page.getByRole("separator", { name: "Resize the Library panel" });
    const usual = Number(await edge.getAttribute("aria-valuenow"));
    await shot(page, `panel-${usual}-${lang}-${theme}`, '[data-testid="nav-panel"]', usual);
    await edge.focus();
    await page.keyboard.press("End");
    const wide = await shot(page, `panel-360-${lang}-${theme}`, '[data-testid="nav-panel"]', 360);
    if (lang === "ur" && wide.left < WIDTH / 2) problems.push("right to left the panel is not on the right");
    await page.keyboard.press("Home");
    await shot(page, `panel-200-${lang}-${theme}`, '[data-testid="nav-panel"]', 200);
    await page.keyboard.press("Enter");
    if (lang === "en" && theme === "light") {
      await page.getByRole("button", { name: /^Filters/ }).click();
      const filters = page.getByRole("separator", { name: "Resize the filters" });
      await filters.focus();
      await page.keyboard.press("End");
      await shot(page, "filters-420-en-light", '[data-testid="filter-panel"]', 420);
      await page.keyboard.press("Home");
      await shot(page, "filters-280-en-light", '[data-testid="filter-panel"]', 280);
    }
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
