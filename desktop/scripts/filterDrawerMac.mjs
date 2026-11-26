// D-244: in Urdu (right to left) the filters drawer opens from the window's left, where a Mac keeps its window buttons;
// its × must sit beside them, never under. The Library in Urdu at 1280 × 800 (below 1440 the filters are a drawer), the
// drawer open, on a Mac (window and full screen) and on Windows, light and dark; English on a Mac for comparison. Each
// picture is docs/design/screens/desktop/d-244/<set>/<lang>-<platform>-<theme>.png. With --set=after the run fails when
// the drawer's close button meets the Mac window buttons' area.
//   node scripts/filterDrawerMac.mjs --set=before|after
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const set = process.argv.find((a) => a.startsWith("--set="))?.split("=")[1];
if (set !== "before" && set !== "after") throw new Error("--set=before or --set=after");
const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "d-244", set);
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5273, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];
// The Mac's window buttons: 6 px from the left, 16 px from the top, 60 × 20 (tokens.css --el-desktop-titlerow-lights-*).
const LIGHTS = { left: 6, top: 16, right: 66, bottom: 36 };
const runs = [["ur", "macos"], ["ur", "macos-full"], ["ur", "windows"], ["en", "macos"]];
try {
  for (const [lang, platform] of runs) {
    for (const theme of ["light", "dark"]) {
      const where = platform === "windows" ? "&platform=windows" : platform === "macos" ? "&platform=macos&lights=1" : "&platform=macos&fullscreen=1";
      const urdu = lang === "ur" ? ",v21_urdu&applang=ur" : "";
      const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, reducedMotion: "reduce" });
      page.setDefaultTimeout(120_000);
      await page.goto(`${base}scripts/screens/index.html?screen=library&uiflags=${FLAGS}${urdu}${where}&theme=${theme}`);
      await page.locator('nav[aria-label], [data-testid="title-row"]').first().waitFor();
      if (lang === "ur") await page.waitForFunction(() => document.documentElement.dir === "rtl", null, { timeout: 30_000 }).catch(() => undefined);
      await page.waitForTimeout(1200);
      await page.locator('.el-rail__item[data-place="library"]').first().click();
      await page.locator("button.el-lib-toolbar__filters").first().waitFor();
      await page.waitForTimeout(500);
      await page.locator("button.el-lib-toolbar__filters").first().click();
      await page.locator(".el-filter--drawer").first().waitFor();
      await page.waitForTimeout(600);
      const name = `${lang}-${platform}-${theme}`;
      await page.screenshot({ path: join(out, `${name}.png`) });
      const close = await page.locator(".el-filter--drawer .el-filter__head button").last().boundingBox();
      const meets = !!close && close.x < LIGHTS.right && close.x + close.width > LIGHTS.left && close.y < LIGHTS.bottom && close.y + close.height > LIGHTS.top;
      const titlebar = await page.evaluate(() => document.documentElement.getAttribute("data-titlebar") ?? "-");
      console.log(`${set} ${name}: close at ${close ? `${Math.round(close.x)},${Math.round(close.y)}` : "-"}; meets the lights ${meets}; titlebar ${titlebar}`);
      if (set === "after" && platform === "macos" && meets) problems.push(`${name}: the close button is under the window buttons`);
      await page.close();
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
