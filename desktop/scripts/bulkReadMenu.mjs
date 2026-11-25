// Contract 2.0.0-y: the selection bar's More actions with Mark as read and Mark as unread (a mix picked: one unread
// recording, one read), in English and in Urdu (right to left), on a Mac and on Windows, light and dark, at 1280 × 800.
// Each picture is docs/design/screens/desktop/bulk-read/<lang>-<platform>-<theme>.png. The run fails when the Urdu
// window isn't right to left or the menu doesn't open.
//   node scripts/bulkReadMenu.mjs
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "bulk-read");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5267, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];

try {
  for (const lang of ["en", "ur"]) {
    for (const platform of ["macos", "windows"]) {
      for (const theme of ["light", "dark"]) {
        const where = `${platform === "windows" ? "&platform=windows" : "&platform=macos&lights=1"}&theme=${theme}`;
        const flags = lang === "ur" ? `${FLAGS},v21_urdu` : FLAGS;
        const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, reducedMotion: "reduce" });
        page.setDefaultTimeout(120_000);
        await page.goto(`${base}scripts/screens/index.html?screen=library&uiflags=${flags}${where}${lang === "ur" ? "&applang=ur" : ""}`);
        await page.locator('nav[aria-label], [data-testid="title-row"]').first().waitFor();
        if (lang === "ur") await page.waitForFunction(() => document.documentElement.dir === "rtl", null, { timeout: 30_000 }).catch(() => undefined);
        await page.waitForTimeout(1200);
        // The fixture opens a recording first: the Library's list, from the rail.
        await page.locator('.el-rail__item[data-place="library"]').first().click();
        await page.locator('button[data-row-id="r1"]').first().waitFor();
        await page.waitForTimeout(500);
        // r1 is unread and r2 read: both items show. A row's checkbox starts the selection.
        const tick = (id) => page.locator("li").filter({ has: page.locator(`button[data-row-id="${id}"]`) }).first().locator('[role="checkbox"], input[type="checkbox"]').first();
        await page.locator('button[data-row-id="r1"]').first().hover();
        await tick("r1").click();
        await page.locator('button[data-row-id="r2"]').first().hover();
        await tick("r2").click();
        const bar = page.locator('[role="toolbar"]').first();
        await bar.waitFor();
        // More actions: the bar's menu button just before Cancel selection.
        await bar.locator("button").nth(-2).click();
        await page.locator('[role="menu"]').first().waitFor();
        await page.waitForTimeout(600);
        const name = `${lang}-${platform}-${theme}`;
        await page.screenshot({ path: join(out, `${name}.png`) });
        const seen = await page.evaluate(() => ({
          dir: document.documentElement.dir || "-",
          items: [...document.querySelectorAll('[role="menu"] [role^="menuitem"]')].map((m) => (m.querySelector(".el-menu__label")?.textContent ?? m.textContent ?? "").trim()),
        }));
        console.log(`${name}: <html dir> ${seen.dir}; ${seen.items.join(" | ")}`);
        if (lang === "ur" && seen.dir !== "rtl") problems.push(`${name}: not right to left`);
        if (seen.items.length < 2) problems.push(`${name}: the menu has ${seen.items.length} items`);
        await page.close();
      }
    }
  }
} finally {
  await browser.close();
  await server.close();
}
if (problems.length) {
  console.error(problems.join("\n"));
  process.exit(1);
}
