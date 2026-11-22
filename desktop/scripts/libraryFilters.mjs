// The Library filters redesign (PLAN_LIBRARY_FILTERS), before and after: the v2 Library list in the screens fixture at
// each window width, light and dark, English and right to left (lang=ur), saved under
// docs/design/screens/desktop/library-filters/<prefix>-<width>-<lang>-<theme>.png.
//   node scripts/libraryFilters.mjs before [1024,1440]
//   node scripts/libraryFilters.mjs after
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS as V2_FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "library-filters");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";

const prefix = process.argv[2] ?? "after";
const widths = process.argv[3] ? process.argv[3].split(",").map(Number) : [1024, 1440, 800, 1280, 1920];
const HEIGHT = 900;

const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5231, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
try {
  for (const width of widths) for (const lang of ["en", "ur"]) for (const theme of ["light", "dark"]) {
    const page = await browser.newPage({ viewport: { width, height: HEIGHT }, colorScheme: theme, reducedMotion: "reduce" });
    await page.goto(`${base}scripts/screens/index.html?screen=library&theme=${theme}&uiflags=${V2_FLAGS}${lang === "ur" ? "&lang=ur" : ""}`);
    // The fixture opens a recording: its Back (the last "Library" in the window) shows the list.
    await page.getByRole("button", { name: "Library", exact: true }).last().click();
    await page.locator("button[data-row-id]").first().waitFor();
    await page.evaluate(() => document.querySelectorAll("*").forEach((el) => { if (el.scrollTop) el.scrollTop = 0; }));
    await page.mouse.move(0, 0);
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(400);
    const file = join(out, `${prefix}-${width}-${lang}-${theme}.png`);
    await page.screenshot({ path: file });
    console.log(file);
    await page.close();
  }
} finally {
  await browser.close();
  await server.close();
}
