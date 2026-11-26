// Desktop Urdu, D-255, in the screens fixture (v2 flags and v21_urdu on, the account's language Urdu: "&applang=ur",
// 1280 × 800), on a Mac and on Windows, light: the shell's last English, now Urdu. The Projects panel (from the rail's
// Projects); the title row with the Library's search open ("In:" its scope, History's Back and Forward); and the
// sidebar shell without the Library's sections (ui_v2.library off: its places, groups, foot, workspace and status
// line). The app's navigation is found by data-nav="main", its name being the window's language (مرکزی). It checks that
// the window reads right to left and that each scene shows its Urdu, and saves
// docs/design/screens/desktop/urdu-d255/<platform>-<step>.png; the run fails when a check doesn't hold.
//   node scripts/urduD255.mjs
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "urdu-d255");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5275, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];
const root = (page) => page.evaluate(() => `${document.documentElement.lang || "-"}/${document.documentElement.dir || "-"}`);
/** Every v2 flag but the Library's sections: the sidebar shell. */
const SIDEBAR_FLAGS = FLAGS.split(",").filter((f) => f !== "ui_v2.library").join(",");

try {
  for (const platform of ["macos", "windows"]) {
    const where = platform === "windows" ? "&platform=windows" : "&platform=macos&lights=1";
    const open = async (flags) => {
      const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, colorScheme: "light", reducedMotion: "reduce" });
      page.setDefaultTimeout(120_000);
      await page.addInitScript((mac) => Object.defineProperty(navigator, "platform", { value: mac ? "MacIntel" : "Win32", configurable: true }), platform === "macos");
      await page.goto(`${base}scripts/screens/index.html?screen=library&uiflags=${flags},v21_urdu${where}&theme=light&applang=ur`);
      await page.locator('nav[data-nav="main"]').waitFor();
      await page.waitForFunction(() => document.documentElement.dir === "rtl", null, { timeout: 30_000 }).catch(() => undefined);
      await page.waitForTimeout(1000);
      return page;
    };
    const shot = async (page, step, words) => {
      await page.waitForTimeout(500);
      await page.screenshot({ path: join(out, `${platform}-${step}.png`) });
      const at = await root(page);
      const named = await page.locator('nav[data-nav="main"]').getAttribute("aria-label");
      const speaks = await page.getByText(words, { exact: false }).first().isVisible().catch(() => false);
      console.log(`${platform}: ${step}; <html> ${at}; nav "${named}"; ${speaks ? "in Urdu" : "NOT in Urdu"}`);
      if (at !== "ur/rtl") problems.push(`${platform}: ${step} is ${at}, not ur/rtl`);
      if (named !== "مرکزی") problems.push(`${platform}: ${step}'s navigation is named "${named}"`);
      if (!speaks) problems.push(`${platform}: ${step} doesn't show “${words}”`);
    };

    // The three levels: the Projects panel, from the rail.
    let page = await open(FLAGS);
    await page.locator('nav[data-nav="main"]').getByRole("button", { name: "پروجیکٹس", exact: true }).click();
    await page.getByTestId("nav-panel").waitFor();
    await page.mouse.move(0, 0);
    await shot(page, "projects-panel", "پن شدہ");

    // The title row on the Library, its search open: "In:" its scope.
    await page.locator('nav[data-nav="main"]').getByRole("button", { name: "لائبریری", exact: true }).click();
    await page.waitForTimeout(800);
    // The fixture opens a recording: back to the list, so the row's search is the Library's.
    if (await page.locator(".el-page__back").count()) await page.locator(".el-page__back").first().click();
    await page.locator(".el-titlerow__field").first().click();
    await page.waitForTimeout(400);
    await shot(page, "title-row", "میں");
    await page.close();

    // The sidebar shell (no Library sections).
    page = await open(SIDEBAR_FLAGS);
    await page.mouse.move(0, 0);
    await shot(page, "sidebar", "میرے لنکس");
    // Its foot: Activity, Settings, Help, the workspace, the account and the status line.
    await page.evaluate(() => document.querySelectorAll('nav[data-nav="main"], nav[data-nav="main"] *').forEach((el) => { el.scrollTop = el.scrollHeight; }));
    await shot(page, "sidebar-foot", "ریکارڈ کے لیے تیار");
    await page.close();
  }
} finally {
  await browser.close();
  await server.close();
}
if (problems.length) {
  console.error(`\n${problems.join("\n")}`);
  process.exit(1);
}
