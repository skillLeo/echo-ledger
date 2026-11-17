// Gate 3 screenshots of the v2 sample screens: library, recording and settings, light and dark, English and Urdu,
// at 1280 x 800, into docs/v2/gate3/desktop/ (library-light-en.png …). Runs the Vite dev server (the gallery exists
// only there) and the repo's Playwright Chromium, with reduced motion.
//   node src/v2/gallery/shoot.mjs                      the 12 pictures
//   node src/v2/gallery/shoot.mjs --states            the loading, empty and error states into docs/v2/gate3/desktop/states/
//                                                      (library-loading-light-en.png …)
//   node src/v2/gallery/shoot.mjs --text=200 --out=DIR  the same at 200 % text, into DIR (for checking, not committed):
//   the window keeps its 1280 x 800 pixels at twice the scale, as when the system text size grows.
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..", "..", "..");
const arg = (name) => process.argv.find((a) => a.startsWith(`--${name}=`))?.split("=")[1];
const text = arg("text") ?? "100";
const out = arg("out") ? resolve(arg("out")) : join(desktop, "..", "docs", "v2", "gate3", "desktop");
const only = arg("screen");
const statesMode = process.argv.includes("--states");
const states = statesMode ? ["loading", "empty", "error"] : ["ready"];
const dir = statesMode ? join(out, "states") : out;

const server = await createServer({ root: desktop, configFile: join(desktop, "vite.config.ts"), server: { port: 1436, strictPort: false }, logLevel: "error" });
await server.listen();
const base = server.resolvedUrls.local[0];
await mkdir(dir, { recursive: true });
const browser = await chromium.launch();
try {
  for (const screen of ["library", "recording", "settings", "plan"]) {
    if (only && screen !== only) continue;
    for (const state of states) for (const theme of ["light", "dark"]) {
      for (const lang of ["en", "ur"]) {
        const scale = Number(text) / 100;
        const page = await browser.newPage({ viewport: { width: Math.round(1280 / scale), height: Math.round(800 / scale) }, deviceScaleFactor: scale, colorScheme: theme, reducedMotion: "reduce" });
        await page.goto(`${base}?v2gallery&screen=${screen}&theme=${theme}&lang=${lang}&state=${state}`);
        await page.waitForSelector("[data-gallery-ready]");
        await page.evaluate(() => document.fonts.ready);
        await page.waitForTimeout(150);
        const suffix = text === "100" ? "" : `-text${text}`;
        const name = state === "ready" ? `${screen}-${theme}-${lang}` : `${screen}-${state}-${theme}-${lang}`;
        await page.screenshot({ path: join(dir, `${name}${suffix}.png`) });
        await page.close();
      }
    }
  }
} finally {
  await browser.close();
  await server.close();
}
console.log(`screenshots in ${dir}`);
