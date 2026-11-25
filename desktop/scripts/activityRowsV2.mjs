// D-233: on the new look's Activity page only a notification about a recording is a button. The pointer rests on the new
// device's row (nowhere to go: no hover, no pointer), not read yet, so it ends with a quiet Mark read; on a Mac and on
// Windows, light and dark, 1280 × 800. Each picture is docs/design/screens/desktop/d-233/<set>/<platform>-<theme>.png,
// <set> "before" (v1.1) or "after".
//   node scripts/activityRowsV2.mjs --set=before|after
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const set = process.argv.find((a) => a.startsWith("--set="))?.split("=")[1];
if (set !== "before" && set !== "after") throw new Error("--set=before or --set=after");
const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "d-233", set);
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5271, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
try {
  for (const platform of ["macos", "windows"]) {
    for (const theme of ["light", "dark"]) {
      const where = `${platform === "windows" ? "&platform=windows" : "&platform=macos&lights=1"}&theme=${theme}`;
      const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, reducedMotion: "reduce" });
      page.setDefaultTimeout(120_000);
      await page.goto(`${base}scripts/screens/index.html?screen=activity&deviceunread=1&uiflags=${FLAGS}${where}`);
      await page.locator('[data-v2="activity"]').first().waitFor();
      const device = page.locator('[data-v2="activity"] li.el-row', { hasText: "New device" }).first();
      await device.waitFor();
      await page.waitForTimeout(800);
      await device.hover({ position: { x: 300, y: 20 } });
      await page.waitForTimeout(400);
      await page.screenshot({ path: join(out, `${platform}-${theme}.png`) });
      console.log(`${set} ${platform}-${theme}`);
      await page.close();
    }
  }
} finally {
  await browser.close();
  await server.close();
}
