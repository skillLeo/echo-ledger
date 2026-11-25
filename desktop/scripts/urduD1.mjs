// Desktop Urdu, package D1 (Library, its search and Trash), in the screens fixture with every v2 flag and v21_urdu on
// and the account in Urdu ("&applang=ur"): the Library, a search with matches in transcripts ("invoice", &found=1&hits=1)
// and Trash (&bin=1), on a Mac and on Windows, light and dark, at 1024 and 1280 × 800. Each picture is
// docs/design/screens/desktop/urdu-d1/<set>/<platform>-<scene>-<width>-<theme>.png, <set> "before" (taken before the
// package's words were converted) or "after". Nothing is found by its English name, so the same run works in either.
// The run fails when the window isn't right to left.
//   node scripts/urduD1.mjs --set=before|after [--only=library,search,trash]
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const arg = (name) => process.argv.find((a) => a.startsWith(`--${name}=`))?.split("=")[1];
const set = arg("set");
if (set !== "before" && set !== "after") throw new Error("--set=before or --set=after");
const only = arg("only")?.split(",") ?? ["library", "search", "trash"];
const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "urdu-d1", set);
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5266, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];

/** Narrow, the rail opens the panel over the list (v21_panel_flyout): Esc closes it, so the list shows. */
const closeFlyout = async (page) => {
  await page.keyboard.press("Escape");
  await page.waitForTimeout(300);
};
/** Each scene: what it adds to the address, and how it gets there from the Library (Trash from the panel's foot). */
const SCENES = {
  library: { extra: "", go: closeFlyout },
  search: {
    extra: "&found=1&hits=1",
    go: async (page) => {
      await closeFlyout(page);
      // Narrow, the search is the ⌕ button first (LEAD 18).
      if (!(await page.locator(".el-titlerow__field").count())) await page.locator('[data-testid="title-row"] button[aria-keyshortcuts="Meta+F Control+F"]').first().click();
      await page.locator(".el-titlerow__field").first().fill("invoice");
      // The transcripts are asked for a moment after typing stops.
      await page.locator(".el-hits").first().waitFor();
    },
  },
  trash: {
    extra: "&bin=1",
    go: async (page) => {
      await page.locator(".el-library-foot .el-nav-item").first().click();
      await page.locator(".el-row").first().waitFor();
    },
  },
};

try {
  for (const platform of ["macos", "windows"]) {
    for (const theme of ["light", "dark"]) {
      for (const width of [1024, 1280]) {
        for (const scene of only) {
          const { extra, go } = SCENES[scene];
          const where = `${platform === "windows" ? "&platform=windows" : "&platform=macos&lights=1"}&theme=${theme}`;
          const page = await browser.newPage({ viewport: { width, height: 800 }, reducedMotion: "reduce" });
          page.setDefaultTimeout(120_000);
          await page.goto(`${base}scripts/screens/index.html?screen=library&uiflags=${FLAGS},v21_urdu${where}&applang=ur${extra}`);
          await page.locator('nav[aria-label], [data-testid="title-row"]').first().waitFor();
          await page.waitForFunction(() => document.documentElement.dir === "rtl", null, { timeout: 30_000 }).catch(() => undefined);
          await page.waitForTimeout(1200);
          // The fixture opens a recording first: the Library's list, from the rail.
          await page.locator('.el-rail__item[data-place="library"]').first().click();
          await page.locator(".el-lib-list, .el-library").first().waitFor().catch(() => undefined);
          await page.waitForTimeout(600);
          await go(page);
          await page.waitForTimeout(800);
          await page.mouse.move(0, 0);
          const name = `${platform}-${scene}-${width}-${theme}`;
          await page.screenshot({ path: join(out, `${name}.png`) });
          const at = await page.evaluate(() => `${document.documentElement.lang || "-"}/${document.documentElement.dir || "-"}`);
          console.log(`${set} ${name}: <html> ${at}`);
          if (at !== "ur/rtl") problems.push(`${name}: the window is ${at}, not ur/rtl`);
          await page.close();
        }
      }
    }
  }
} finally {
  await browser.close();
  await server.close();
}
if (problems.length) {
  console.error(`\n${problems.join("\n")}`);
  process.exit(1);
}
