// Desktop Urdu, package D3c (the words Settings shows that classic's and lib's helpers build), in the screens fixture
// with every v2 flag and v21_urdu on and the account in Urdu ("&applang=ur"), on Pro (&plan=pro): Calls & meetings
// (After each call; Why a call wasn't recorded, D-232), Screen-share privacy (with the main window hidden until 16:30,
// &mainhide=1), Plan & usage (the meters and what the plan includes) and the keys in the app, on a Mac and on Windows,
// light and dark, at 1024 and 1280 × 800. Each picture is
// docs/design/screens/desktop/urdu-d3c/<set>/<platform>-<scene>-<width>-<theme>.png, <set> "before" (D3b) or "after".
// A group is opened by its place in the panel and a part by its row's id, never by an English name, so the same run
// works in either; the run fails when the window isn't right to left.
//   node scripts/urduD3c.mjs --set=before|after [--only=calls,why,share,plan,keys]
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const arg = (name) => process.argv.find((a) => a.startsWith(`--${name}=`))?.split("=")[1];
const set = arg("set");
if (set !== "before" && set !== "after") throw new Error("--set=before or --set=after");
const only = arg("only")?.split(",") ?? ["calls", "why", "share", "plan", "keys"];
const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "urdu-d3c", set);
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5272, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];

/** Opens the panel's group at `index` (the groups' order, Setup & health being the rail's own) and scrolls to `row`. */
const group = (index, row) => async (page) => {
  // Narrow, the rail opens the panel over the page (v21_panel_flyout); wide, it stays beside it.
  const rows = page.locator(".el-panel .el-nav-list .el-nav-item--row");
  if (!(await rows.count())) await page.locator('.el-rail__item[data-place="settings"]').first().click();
  await rows.nth(index).click();
  await page.locator('[data-v2="settings-group"]').first().waitFor();
  await page.keyboard.press("Escape");
  await page.waitForTimeout(400);
  if (row) {
    await page.locator(row).first().waitFor();
    await page.evaluate((selector) => document.querySelector(selector)?.scrollIntoView({ block: "start" }), row);
  }
};
/** The panel's search: a word typed, its results shown. */
const search = (word) => async (page) => {
  const field = page.locator(".el-panel .el-panel__search input");
  if (!(await field.count())) await page.locator('.el-rail__item[data-place="settings"]').first().click();
  await field.first().fill(word);
  await page.locator(".el-panel .el-nav-list .el-nav-item--row").first().waitFor();
};
/** Each scene, how it gets there from Settings' home, and the group's title its page has. */
const SCENES = {
  calls: { go: group(0, '[data-setting="calls.after"]'), title: "ریکارڈنگ" },
  why: { go: group(0, '[data-testid="why-not-recorded"]'), title: "ریکارڈنگ" },
  share: { go: group(2, '[data-setting="share_privacy.main"]'), title: "لائیو اور اوورلے" },
  plan: { go: group(6, '[data-testid="meter-monthly_minutes"]'), title: "اکاؤنٹ اور پلان" },
  keys: { go: group(7, '[data-setting="shortcuts.app"]'), title: "شارٹ کٹس اور اسٹارٹ اپ" },
};

try {
  for (const platform of ["macos", "windows"]) {
    for (const theme of ["light", "dark"]) {
      for (const width of [1024, 1280]) {
        for (const scene of only) {
          const { go, title } = SCENES[scene];
          const where = `${platform === "windows" ? "&platform=windows" : "&platform=macos&lights=1"}&theme=${theme}`;
          const page = await browser.newPage({ viewport: { width, height: 800 }, reducedMotion: "reduce" });
          page.setDefaultTimeout(120_000);
          await page.goto(`${base}scripts/screens/index.html?screen=settings&plan=pro&uiflags=${FLAGS},v21_urdu${where}&applang=ur&mainhide=1`);
          await page.locator('[data-v2="settings"], [data-v2="settings-group"]').first().waitFor();
          await page.waitForFunction(() => document.documentElement.dir === "rtl", null, { timeout: 30_000 }).catch(() => undefined);
          await page.waitForTimeout(1200);
          await go(page);
          await page.waitForTimeout(800);
          await page.mouse.move(0, 0);
          const name = `${platform}-${scene}-${width}-${theme}`;
          await page.screenshot({ path: join(out, `${name}.png`) });
          const at = await page.evaluate(() => `${document.documentElement.lang || "-"}/${document.documentElement.dir || "-"}`);
          const heading = title ? await page.locator('[data-v2="settings-group"]:visible h1').first().textContent() : null;
          console.log(`${set} ${name}: <html> ${at}${heading !== null ? `, ${heading}` : ""}`);
          if (at !== "ur/rtl") problems.push(`${name}: the window is ${at}, not ur/rtl`);
          if (title && heading !== title) problems.push(`${name}: the group's page is "${heading}", not "${title}"`);
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
