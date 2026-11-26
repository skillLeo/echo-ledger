// D-234: the Request files sheet in a window 800 px tall, in Urdu ("&applang=ur", where a Mac's head keeps clear of the
// window buttons) and in English, on a Mac and on Windows, light and dark: "See your request links" is in view, in the
// sheet's footer. Each picture is docs/design/screens/desktop/d-234/<set>/<platform>-<lang>-<theme>.png, <set>
// "before" (v1.1) or "after". The run fails when the link is not in view (drawn at its own place in the window).
//   node scripts/requestSheetFold.mjs --set=before|after
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const set = process.argv.find((a) => a.startsWith("--set="))?.split("=")[1];
if (set !== "before" && set !== "after") throw new Error("--set=before or --set=after");
const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "d-234", set);
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5273, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];
try {
  for (const platform of ["macos", "windows"]) {
    for (const lang of ["ur", "en"]) {
      for (const theme of ["light", "dark"]) {
        const where = `${platform === "windows" ? "&platform=windows" : "&platform=macos&lights=1"}&theme=${theme}`;
        const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, reducedMotion: "reduce" });
        page.setDefaultTimeout(120_000);
        await page.goto(`${base}scripts/screens/index.html?screen=library&uiflags=${FLAGS},v21_urdu${where}&applang=${lang}`);
        await page.locator('nav[data-nav="main"]').waitFor();
        await page.waitForTimeout(1200);
        // Shared, from the Library panel; then New request link (the first button there named so, in either language).
        await page.getByRole("button", { name: /^(شیئر شدہ|Shared)$/ }).first().click();
        await page.locator("[data-v2=shared]").waitFor();
        await page.getByRole("button", { name: /^(نیا درخواست کا لنک|New request link)$/ }).click();
        const sheet = page.locator(".el-sheet").first();
        await sheet.waitFor();
        await page.waitForTimeout(500);
        await page.mouse.move(0, 0);
        const name = `${platform}-${lang}-${theme}`;
        await page.screenshot({ path: join(out, `${name}.png`) });
        // The link, by its words in either language: inside the window, not below it.
        const link = sheet.getByRole("button", { name: /^(اپنے درخواست کے لنکس دیکھیں|See your request links)$/ });
        // In view: what is drawn at its middle is the link itself (not the footer over a scrolled body, not off screen).
        const inView = await link.evaluate((el) => {
          const r = el.getBoundingClientRect();
          const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
          return r.bottom <= window.innerHeight && !!hit && el.contains(hit);
        });
        console.log(`${set} ${name}: the link ${inView ? "in view" : "below the fold"}`);
        if (set === "after" && !inView) problems.push(`${name}: the link is below the fold`);
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
