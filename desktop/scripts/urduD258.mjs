// Desktop Urdu, D-258, in the screens fixture (every v2 flag and v21_urdu on, the account's language Urdu: "&applang=ur",
// 1280 × 800), on a Mac and on Windows, in the light and the dark theme: the Library's All recordings with today's rows
// (their times: "صبح 9:00", the part of the day first) and, scrolled down, the older rows (their dates), each with its
// length in words ("12 منٹ 22 سیکنڈ"). It checks that the window reads right to left, that the rows show the Urdu
// times and lengths and no "am", "pm" or "12m 22s" is left, and saves docs/design/screens/desktop/urdu-d258/
// <platform>-<step>.png (light) and <platform>-<step>-dark.png; the run fails when a check doesn't hold.
//   node scripts/urduD258.mjs [--only=macos-light,windows-dark]
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "urdu-d258");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5278, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];
const root = (page) => page.evaluate(() => `${document.documentElement.lang || "-"}/${document.documentElement.dir || "-"}`);
/** The rows' ends (when, then length) as shown, and any English unit or am/pm left in them. */
const ends = (page) => page.evaluate(() => {
  const texts = [...document.querySelectorAll(".el-rec .el-rec__end")].filter((el) => el.getBoundingClientRect().height > 0).map((el) => el.textContent ?? "");
  return { texts, english: texts.filter((s) => /\b(am|pm|AM|PM)\b|\d+[hms]\b/.test(s)) };
});

try {
  const only = process.argv.find((a) => a.startsWith("--only="))?.split("=")[1]?.split(",");
  for (const [platform, theme] of [["macos", "light"], ["macos", "dark"], ["windows", "light"], ["windows", "dark"]]) {
    if (only && !only.includes(`${platform}-${theme}`)) continue;
    const where = `${platform === "windows" ? "&platform=windows" : "&platform=macos&lights=1"}&theme=${theme}`;
    const label = `${platform} (${theme})`;
    const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, colorScheme: theme, reducedMotion: "reduce" });
    page.setDefaultTimeout(120_000);
    await page.goto(`${base}scripts/screens/index.html?screen=library&uiflags=${FLAGS},v21_urdu${where}&applang=ur`);
    // The main nav by its mark (its name is مرکزی in Urdu once D-255 is in, "Main" before).
    await page.locator('nav[data-nav="main"], nav[aria-label="Main"]').first().waitFor();
    await page.waitForFunction(() => document.documentElement.dir === "rtl", null, { timeout: 30_000 }).catch(() => undefined);
    await page.getByRole("list", { name: "لائبریری کے ویوز" }).getByRole("button", { name: /^تمام ریکارڈنگز/ }).click();
    await page.locator(".el-rec").first().waitFor();
    await page.waitForTimeout(1000);

    /** Saves the window, after checking its direction and the rows' Urdu times and lengths (`want` among them). */
    const shot = async (step, want) => {
      await page.mouse.move(0, 0);
      await page.waitForTimeout(500);
      await page.screenshot({ path: join(out, `${platform}-${step}${theme === "dark" ? "-dark" : ""}.png`) });
      const at = await root(page);
      const { texts, english } = await ends(page);
      const shows = texts.some((s) => want.test(s));
      console.log(`${label}: ${step}; <html> ${at}; ${texts.length} ends, e.g. ${texts.slice(0, 4).join(" | ")}${english.length ? `; ENGLISH: ${english.join(" | ")}` : ""}`);
      if (at !== "ur/rtl") problems.push(`${label}: ${step} is ${at}, not ur/rtl`);
      if (!shows) problems.push(`${label}: ${step} shows no ${want}`);
      if (english.length) problems.push(`${label}: ${step} still says ${english.join(", ")}`);
    };

    // Today's rows: the part of the day first, then the clock; every length in words.
    await shot("library", /^(صبح|دوپہر|سہ پہر|شام|رات)\s\d{1,2}:\d{2}$/u);
    // The older rows (their dates), scrolled to the list's end.
    await page.evaluate(() => {
      const rows = [...document.querySelectorAll(".el-rec")];
      rows[rows.length - 1]?.scrollIntoView({ block: "end" });
    });
    await page.waitForTimeout(800);
    await shot("library-older", /سیکنڈ|منٹ|گھنٹ/u);
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
