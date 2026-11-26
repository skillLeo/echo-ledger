// D-245: in Urdu a row's meta line built of pieces (Home's recent recordings: a state's icon and its words) reads right to
// left, so its Urdu isn't cut at its start. Home's recent recordings in Urdu on a Mac, light and dark, at 1024 and
// 1280 × 800. Each picture (the section) is docs/design/screens/desktop/d-245/<set>/<width>-<theme>.png (D245_OUT: another folder). With --set=after the run
// fails when a row's meta line isn't right to left.
//   node scripts/piecedLines.mjs --set=before|after
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const set = process.argv.find((a) => a.startsWith("--set="))?.split("=")[1];
if (set !== "before" && set !== "after") throw new Error("--set=before or --set=after");
const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(process.env.D245_OUT ?? join(desktop, "..", "docs", "design", "screens", "desktop", "d-245"), set);
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5274, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];
try {
  for (const width of [1024, 1280]) {
    for (const theme of ["light", "dark"]) {
      const page = await browser.newPage({ viewport: { width, height: 800 }, reducedMotion: "reduce" });
      page.setDefaultTimeout(120_000);
      await page.goto(`${base}scripts/screens/index.html?screen=record&uiflags=${FLAGS},v21_urdu&applang=ur&platform=macos&lights=1&theme=${theme}`);
      await page.locator('nav[aria-label], [data-testid="title-row"]').first().waitFor();
      await page.waitForFunction(() => document.documentElement.dir === "rtl", null, { timeout: 30_000 }).catch(() => undefined);
      await page.waitForTimeout(1200);
      await page.locator(".el-row__meta-text").first().waitFor();
      await page.waitForTimeout(800);
      const name = `${width}-${theme}`;
      await page.mouse.move(0, 0);
      // The recent recordings' rows, where the pieced lines are.
      const recent = page.locator('section[aria-label="Recent recordings"], section.el-group').filter({ has: page.locator(".el-row__meta-text") }).last();
      await recent.scrollIntoViewIfNeeded();
      await page.waitForTimeout(300);
      await recent.screenshot({ path: join(out, `${name}.png`) });
      const dirs = await page.evaluate(() => [...document.querySelectorAll(".el-row__meta-text")].filter((m) => m.firstElementChild).map((m) => m.getAttribute("dir")));
      console.log(`${set} ${name}: pieced meta lines ${dirs.join(",") || "-"}`);
      if (set === "after" && (!dirs.length || dirs.some((d) => d !== "rtl"))) problems.push(`${name}: ${dirs.join(",")}`);
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
