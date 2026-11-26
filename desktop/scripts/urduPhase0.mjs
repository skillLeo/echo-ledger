// Desktop Urdu, Phase 0, in the screens fixture (every v2 flag and v21_urdu on, 1280 × 800), on a Mac and on Windows:
// Settings › Appearance › Language, then the Library with the account's language Urdu ("&applang=ur": the window
// follows it, right to left), in the light and the dark theme. It measures <html lang dir> each time and saves
// docs/design/screens/desktop/urdu-phase0/<platform>-<step>.png (light) and <platform>-<step>-dark.png; the run fails
// when the window doesn't follow.
//   node scripts/urduPhase0.mjs
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "urdu-phase0");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5265, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];
const root = (page) => page.evaluate(() => `${document.documentElement.lang || "-"}/${document.documentElement.dir || "-"}`);

try {
  for (const [platform, theme] of [["macos", "light"], ["macos", "dark"], ["windows", "light"], ["windows", "dark"]]) {
    const where = `${platform === "windows" ? "&platform=windows" : "&platform=macos&lights=1"}&theme=${theme}`;
    const shot = (step) => join(out, `${platform}-${step}${theme === "dark" ? "-dark" : ""}.png`);
    const label = `${platform} (${theme})`;
    const open = async (extra) => {
      const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, reducedMotion: "reduce" });
      page.setDefaultTimeout(120_000);
      await page.goto(`${base}scripts/screens/index.html?screen=library&uiflags=${FLAGS},v21_urdu${where}${extra}`);
      await page.locator('nav[data-nav="main"]').waitFor();
      await page.waitForTimeout(1500);
      return page;
    };
    // English account: Settings › Appearance has Language.
    let page = await open("");
    await page.locator('nav[data-nav="main"]').getByRole("button", { name: /^Settings/ }).click();
    await page.waitForTimeout(800);
    await page.getByTestId("nav-panel").getByRole("button", { name: /^Appearance/ }).first().click();
    const group = page.getByRole("radiogroup", { name: "Language" });
    await group.waitFor();
    await page.mouse.move(0, 0);
    await page.screenshot({ path: shot("appearance-en") });
    let at = await root(page);
    console.log(`${label}: English account, Appearance › Language shown; <html> ${at}`);
    if (at !== "en/ltr") problems.push(`${label}: an English account's window is ${at}, not en/ltr`);
    // Choosing اردو turns the window.
    await group.getByRole("radio", { name: "اردو" }).click();
    await page.waitForFunction(() => document.documentElement.dir === "rtl", null, { timeout: 15_000 }).catch(() => undefined);
    await page.waitForTimeout(600);
    await page.screenshot({ path: shot("appearance-ur") });
    at = await root(page);
    console.log(`${label}: chose اردو; <html> ${at}`);
    if (at !== "ur/rtl") problems.push(`${label}: choosing اردو left the window ${at}`);
    await page.close();
    // An Urdu account: the Library, right to left from the start.
    page = await open("&applang=ur");
    await page.locator('nav[data-nav="main"]').getByRole("button", { name: /^Library/ }).click();
    await page.waitForTimeout(1000);
    await page.mouse.move(0, 0);
    await page.screenshot({ path: shot("library-ur") });
    at = await root(page);
    console.log(`${label}: Urdu account, the Library; <html> ${at}`);
    if (at !== "ur/rtl") problems.push(`${label}: an Urdu account's window is ${at}`);
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
