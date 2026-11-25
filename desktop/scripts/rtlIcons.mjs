// D-231: the glyphs that point a way or read as text mirrored in Urdu (sort, sign out, send, Ask, help's "?"), in the
// screens fixture with every v2 flag and v21_urdu on and the account in Urdu ("&applang=ur"): Settings' home (Sign out,
// the rail's Ask and Help) and the Library (its Sort & display button), on a Mac and on Windows, light and dark,
// 1280 × 800. Each picture is docs/design/screens/desktop/rtl-icons/<set>/<platform>-<scene>-<theme>.png, <set>
// "before" (v1.1) or "after". The run fails when the window isn't right to left.
//   node scripts/rtlIcons.mjs --set=before|after
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const set = process.argv.find((a) => a.startsWith("--set="))?.split("=")[1];
if (set !== "before" && set !== "after") throw new Error("--set=before or --set=after");
const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "rtl-icons", set);
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5270, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];
const SCENES = {
  settings: { screen: "settings&plan=pro", ready: '[data-v2="settings"]' },
  // The fixture opens a recording first: the Library's list, from the rail (narrow, its panel flies over: Esc).
  library: { screen: "library", ready: 'nav[aria-label], [data-testid="title-row"]', go: async (page) => {
    await page.locator('.el-rail__item[data-place="library"]').first().click();
    await page.locator(".el-lib-list, .el-library").first().waitFor().catch(() => undefined);
    await page.keyboard.press("Escape");
  } },
};
try {
  for (const platform of ["macos", "windows"]) {
    for (const theme of ["light", "dark"]) {
      for (const [scene, { screen, ready, go }] of Object.entries(SCENES)) {
        const where = `${platform === "windows" ? "&platform=windows" : "&platform=macos&lights=1"}&theme=${theme}`;
        const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, reducedMotion: "reduce" });
        page.setDefaultTimeout(120_000);
        await page.goto(`${base}scripts/screens/index.html?screen=${screen}&uiflags=${FLAGS},v21_urdu${where}&applang=ur`);
        await page.locator(ready).first().waitFor();
        await page.waitForFunction(() => document.documentElement.dir === "rtl", null, { timeout: 30_000 }).catch(() => undefined);
        await page.waitForTimeout(1200);
        if (go) await go(page);
        await page.waitForTimeout(800);
        await page.mouse.move(0, 0);
        const name = `${platform}-${scene}-${theme}`;
        await page.screenshot({ path: join(out, `${name}.png`) });
        const at = await page.evaluate(() => `${document.documentElement.lang || "-"}/${document.documentElement.dir || "-"}`);
        console.log(`${set} ${name}: <html> ${at}`);
        if (at !== "ur/rtl") problems.push(`${name}: the window is ${at}, not ur/rtl`);
        await page.close();
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
