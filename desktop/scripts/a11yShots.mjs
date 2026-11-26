// FIX-06's look: the Library's rows (a label's dot is a 24 px target, the dots set 24 px apart) and the rail with the
// keyboard on an item (its ring), at 1024 and 1280, light and dark, and Urdu, into
// docs/design/screens/desktop/fix-06/<prefix>-<width>-<lang>-<theme>.png.
//   node scripts/a11yShots.mjs before|after
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS as V2_FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = process.env.A11Y_OUT ?? join(desktop, "..", "docs", "design", "screens", "desktop", "fix-06");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const prefix = process.argv[2] ?? "after";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5255, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
try {
  for (const [width, lang, theme] of [[1024, "en", "light"], [1280, "en", "light"], [1280, "en", "dark"], [1280, "ur", "light"]]) {
    const page = await browser.newPage({ viewport: { width, height: 800 }, colorScheme: theme, reducedMotion: "reduce" });
    page.setDefaultTimeout(120_000);
    await page.goto(`${base}scripts/screens/index.html?screen=library&theme=${theme}&uiflags=${V2_FLAGS}&platform=macos&lights=1${lang === "ur" ? "&lang=ur" : ""}`);
    const rail = page.getByRole("navigation", { name: "Main" });
    await rail.waitFor();
    await rail.getByRole("button", { name: /^Library/ }).click();
    await page.locator("button[data-row-id]").first().waitFor();
    if (width < 1100 && await page.getByTestId("nav-panel").isVisible().catch(() => false)) await page.keyboard.press("Escape");
    // The keyboard on the rail: its Library item focused, then ↓ (a key press, so the ring shows on the next item).
    await rail.getByRole("button", { name: /^Library/ }).focus();
    await page.keyboard.press("ArrowDown");
    await page.mouse.move(width / 2, 700);
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(500);
    await page.screenshot({ path: join(out, `${prefix}-${width}-${lang}-${theme}.png`) });
    console.log(`${prefix}-${width}-${lang}-${theme}`);
    await page.close();
  }
} finally {
  await browser.close();
  await server.close();
}
