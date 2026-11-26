// Contract 2.0.0-w: Settings › Calls with the account's call rules on (After each call, When to ask and Short calls the
// account's, the Call rules row), its Short calls section, and the Call rules page (a contact's Don't record in decision
// 25's words, a group rule set on the phone, Zoom's Don't record noted), each in the new look and the classic one; and
// the Library's "Always for <name>" with its four choices and the confirm before Don't record (on Windows, where the menu
// is the window's own; a Mac shows it as its native menu). In English and in Urdu (right to left), on a Mac, light and
// dark, at 1280 × 800, in the screens fixture with &callrules=1. Each picture is
// docs/design/screens/desktop/call-rules/<scene>-<lang>-<theme>.png. The run fails when a scene doesn't show.
//   node scripts/callRulesPage.mjs            (ONLY=rules,rules-classic takes those scenes only)
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
// [scene, the Settings section it opens, the look]
const SCENES = [
  ["calls", "calls.after", "new"], ["short", "calls.after", "new"], ["rules", "calls.rules", "new"],
  ["calls-classic", "calls.after", "classic"], ["short-classic", "calls.after", "classic"], ["rules-classic", "calls.rules", "classic"],
  ["library-rule", null, "new"], ["library-confirm", null, "new"],
];
try {
  for (const lang of ["en", "ur"]) {
    for (const theme of ["light", "dark"]) {
      for (const [scene, section, look] of SCENES.filter(([scene]) => !process.env.ONLY || process.env.ONLY.split(",").includes(scene))) {
        const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, colorScheme: theme, reducedMotion: "reduce" });
        page.setDefaultTimeout(120_000);
        const shown_flags = look === "classic" ? FLAGS.split(",").filter((f) => f !== "ui_v2.settings").join(",") : FLAGS;
        const flags = `${shown_flags},call_rules${lang === "ur" ? ",v21_urdu&applang=ur" : ""}`;
        const library = scene.startsWith("library");
        // The window's own menu is the one off a Mac (on a Mac it is the system's, which a picture of the page can't show).
        if (library) await page.addInitScript(() => Object.defineProperty(navigator, "platform", { get: () => "Win32" }));
        await page.goto(`${base}scripts/screens/index.html?screen=${library ? "library" : "settings"}&callrules=1&uiflags=${flags}&platform=${library ? "windows" : "macos"}&lights=1&theme=${theme}`);
        if (lang === "ur") await page.waitForFunction(() => document.documentElement.dir === "rtl", null, { timeout: 30_000 }).catch(() => undefined);
        let shown;
        if (library) {
          await page.locator('.el-rail__item[data-place="library"]').first().click().catch(() => undefined);
          await page.locator('button[data-row-id="r1"]').first().waitFor();
          await page.waitForTimeout(1000);
          const row = page.locator("li").filter({ has: page.locator('button[data-row-id="r1"]') }).first();
          await row.hover();
          await row.locator('button[aria-haspopup="menu"]').last().click();
          await page.locator('[role="menu"]').first().waitFor();
          await page.locator('[role="menu"] [role="menuitem"]').filter({ hasText: "Bilal Qureshi" }).first().click();
          await page.waitForTimeout(500);
          if (scene === "library-confirm") {
            await page.locator('[role="menu"]').last().locator('[role="menuitemradio"]').last().click();
            await page.waitForTimeout(800);
            shown = await page.evaluate(() => !!document.querySelector('[role="alertdialog"], [role="dialog"]') && document.body.textContent.includes("Bilal Qureshi"));
          } else {
            shown = await page.evaluate(() => document.querySelectorAll('[role="menu"]').length > 1 && document.querySelectorAll('[role="menuitemradio"]').length === 4);
          }
        } else {
          await page.locator(look === "classic" ? "main, [role=main]" : "[data-v2=settings]").first().waitFor();
          await page.waitForTimeout(1000);
          // The classic Settings opens a page from its row: Calls & meetings, then its Call rules row.
          const classicRules = look === "classic" && scene.startsWith("rules");
          await page.evaluate((id) => window.dispatchEvent(new CustomEvent("echoledger:open-settings-section", { detail: id })), classicRules ? "calls.after" : section);
          await page.waitForTimeout(1500);
          if (classicRules) {
            await page.locator('[data-setting="calls.rules"] button, #setting-calls\\.rules button, [data-setting="calls.rules"]').first().click();
            await page.waitForTimeout(1500);
          }
          if (scene.startsWith("short")) {
            await page.evaluate(() => document.querySelector('[data-setting="calls.short_calls"]')?.scrollIntoView({ block: "center" }));
            await page.waitForTimeout(500);
          }
          shown = await page.evaluate((scene) => scene.startsWith("rules")
            ? !!document.querySelector('[aria-label="Call rules"], [aria-label="کال کے قواعد"]')
            : scene.startsWith("short") ? !!document.querySelector('[data-setting="calls.short_calls"]')
            : [...document.querySelectorAll('[role="switch"]')].length > 0, scene);
        }
        const name = `${scene}-${lang}-${theme}`;
        if (!library) await page.mouse.move(0, 0);
        await page.screenshot({ path: join(out, `${name}.png`) });
        console.log(`${name}: shown ${shown}; dir ${await page.evaluate(() => document.documentElement.dir || "-")}`);
        if (!shown) problems.push(`${name}: the scene didn't show`);
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
