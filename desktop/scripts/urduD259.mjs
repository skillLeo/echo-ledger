// Desktop Urdu, D-259, in the screens fixture (every v2 flag and v21_urdu on, the account's language Urdu: "&applang=ur",
// 1280 × 800), on a Mac and on Windows, in the light and the dark theme: the All projects page (its title in the title
// row, Sort, Request files and New project, each card's line: its recordings, last call and folders) and a project's
// Recordings (its rows' times and lengths, D-258, in the project's own left-to-right box). It checks that the window
// reads right to left, that each scene shows its Urdu, that the app's message regions are named in Urdu, and saves
// docs/design/screens/desktop/urdu-d259/<platform>-<step>.png (light) and <platform>-<step>-dark.png; the run fails
// when a check doesn't hold.
//   node scripts/urduD259.mjs [--only=macos-light,windows-dark]
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "urdu-d259");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5279, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];
const root = (page) => page.evaluate(() => `${document.documentElement.lang || "-"}/${document.documentElement.dir || "-"}`);

try {
  const only = process.argv.find((a) => a.startsWith("--only="))?.split("=")[1]?.split(",");
  for (const [platform, theme] of [["macos", "light"], ["macos", "dark"], ["windows", "light"], ["windows", "dark"]]) {
    if (only && !only.includes(`${platform}-${theme}`)) continue;
    const where = `${platform === "windows" ? "&platform=windows" : "&platform=macos&lights=1"}&theme=${theme}`;
    const label = `${platform} (${theme})`;
    const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, colorScheme: theme, reducedMotion: "reduce" });
    page.setDefaultTimeout(120_000);
    await page.goto(`${base}scripts/screens/index.html?screen=projects&uiflags=${FLAGS},v21_urdu${where}&applang=ur`);
    // The main nav by its mark (its name is مرکزی in Urdu once D-255 is in, "Main" before).
    await page.locator('nav[data-nav="main"], nav[aria-label="Main"]').first().waitFor();
    await page.waitForFunction(() => document.documentElement.dir === "rtl", null, { timeout: 30_000 }).catch(() => undefined);
    await page.locator(".el-project-card").first().waitFor();
    await page.waitForTimeout(1000);

    /** Saves the window, after checking its direction and that `words` (Urdu) are on it. */
    const shot = async (step, words) => {
      await page.mouse.move(0, 0);
      await page.waitForTimeout(500);
      await page.screenshot({ path: join(out, `${platform}-${step}${theme === "dark" ? "-dark" : ""}.png`) });
      const at = await root(page);
      const speaks = await page.getByText(words, { exact: false }).first().isVisible().catch(() => false);
      console.log(`${label}: ${step}; <html> ${at}; ${speaks ? "in Urdu" : "NOT in Urdu"}`);
      if (at !== "ur/rtl") problems.push(`${label}: ${step} is ${at}, not ur/rtl`);
      if (!speaks) problems.push(`${label}: ${step} doesn't show “${words}”`);
    };

    // The message regions, named in Urdu (read out with a message, not seen).
    const regions = await page.evaluate(() => [...document.querySelectorAll('[data-testid="app-messages"], [data-testid="app-problems"]')].map((r) => r.getAttribute("aria-label")));
    console.log(`${label}: message regions ${regions.join(", ")}`);
    if (regions.join() !== "پیغامات,مسائل") problems.push(`${label}: the message regions are ${regions.join(", ")}`);

    // All projects: the cards, their lines; Sort, Request files and New project.
    const lines = await page.locator(".el-project-card .el-caption").allTextContents();
    console.log(`${label}: cards: ${lines.join(" | ")}`);
    if (lines.some((l) => /recording|Last call|folder/.test(l))) problems.push(`${label}: a card still says ${lines.join(" | ")}`);
    await shot("all-projects", "نیا پروجیکٹ");
    // Sort's menu open: its two choices.
    await page.getByRole("button", { name: /^ترتیب:/ }).click();
    await page.getByRole("menu", { name: "پروجیکٹس کی ترتیب" }).waitFor();
    await shot("all-projects-sort", "حالیہ");
    await page.keyboard.press("Escape");

    // A project's Recordings: its rows' times and lengths (D-258) in the project's own left-to-right box.
    await page.locator(".el-project-card").first().click();
    await page.waitForTimeout(1200);
    const tab = page.getByRole("tab", { name: /^ریکارڈنگز/ });
    if (await tab.count()) await tab.first().click();
    await page.waitForTimeout(800);
    await shot("project-recordings", "منٹ");
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
