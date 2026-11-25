// Contract 2.0.0-w: Settings › Calls with the account's call rules on (After each call, When to ask and Short calls the
// account's, the Call rules row) and the Call rules page (a contact's Don't record in decision 25's words, a group rule
// set on the phone, Zoom's Don't record noted), in English and in Urdu (right to left), on a Mac, light and dark, at
// 1280 × 800, in the screens fixture with &callrules=1. Each picture is
// docs/design/screens/desktop/call-rules/<scene>-<lang>-<theme>.png. The run fails when a page doesn't show.
//   node scripts/callRulesPage.mjs
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "call-rules");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5275, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];
try {
  for (const lang of ["en", "ur"]) {
    for (const theme of ["light", "dark"]) {
      for (const [scene, section] of [["calls", "calls.after"], ["rules", "calls.rules"]]) {
        const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, colorScheme: theme, reducedMotion: "reduce" });
        page.setDefaultTimeout(120_000);
        const flags = `${FLAGS},call_rules${lang === "ur" ? ",v21_urdu&applang=ur" : ""}`;
        await page.goto(`${base}scripts/screens/index.html?screen=settings&callrules=1&uiflags=${flags}&platform=macos&lights=1&theme=${theme}`);
        await page.locator("[data-v2=settings]").first().waitFor();
        if (lang === "ur") await page.waitForFunction(() => document.documentElement.dir === "rtl", null, { timeout: 30_000 }).catch(() => undefined);
        await page.waitForTimeout(1000);
        await page.evaluate((id) => window.dispatchEvent(new CustomEvent("echoledger:open-settings-section", { detail: id })), section);
        await page.waitForTimeout(1500);
        const shown = await page.evaluate((scene) => scene === "rules"
          ? !!document.querySelector('[aria-label="Call rules"], [aria-label="کال کے قواعد"]')
          : [...document.querySelectorAll('[role="switch"]')].length > 0, scene);
        const name = `${scene}-${lang}-${theme}`;
        await page.mouse.move(0, 0);
        await page.screenshot({ path: join(out, `${name}.png`) });
        console.log(`${name}: shown ${shown}; dir ${await page.evaluate(() => document.documentElement.dir || "-")}`);
        if (!shown) problems.push(`${name}: the page didn't show`);
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
