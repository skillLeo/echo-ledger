// Scrolling and the first paint in the main window, in WebKit (the macOS app's engine) and Chromium (WebView2), classic
// and the new design, at the app's default size (1100 x 740) and full screen on a 16" MacBook Pro (1728 x 1117):
// the window must draw something at once (never only its background), and the Library, Settings and a recording
// must scroll with the mouse wheel and the trackpad.
//   node scripts/scrollcheck.mjs --out=DIR
import { createServer } from "vite";
import { chromium, webkit } from "playwright";
import { mkdir, writeFile } from "node:fs/promises";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const arg = (name) => process.argv.find((a) => a.startsWith(`--${name}=`))?.split("=")[1];
const out = resolve(arg("out") ?? "scrollcheck");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";

const SIZES = [["default", 1100, 740], ["fullscreen", 1728, 1117]];
const DESIGNS = [["classic", ""], ["v2", `&uiflags=${FLAGS}`]];
/** [screen, fixture screen, steps to reach it (per design)] */
const SCREENS = [
  ["library", "library", { classic: null, v2: null }],
  ["settings", "settings", { classic: null, v2: null }],
  ["recording", "library", {
    classic: async (p) => { await p.locator(".item").first().click(); await p.waitForTimeout(1500); },
    v2: async (p) => { await p.locator(".el-row__hit").first().click(); await p.waitForTimeout(1500); },
  }],
];

/** In the page: every element that scrolls (overflow auto or scroll, content taller than itself), largest first. */
function scrollers() {
  return [...document.querySelectorAll("*")].filter((el) => {
    const s = getComputedStyle(el);
    return /(auto|scroll)/.test(s.overflowY) && el.scrollHeight > el.clientHeight + 4;
  }).map((el) => ({ cls: `${el.tagName.toLowerCase()}.${[...el.classList].join(".")}`, top: el.scrollTop, h: el.clientHeight, sh: el.scrollHeight }))
    .sort((a, b) => b.h - a.h);
}

const server = await createServer({ root: desktop, configFile: join(desktop, "vite.config.ts"), server: { port: 1490, strictPort: false }, logLevel: "error" });
await server.listen();
const base = server.resolvedUrls.local[0];
await mkdir(out, { recursive: true });
const report = [];
for (const [engineName, engine] of [["webkit", webkit], ["chromium", chromium]]) {
  const browser = await engine.launch();
  try {
    for (const [design, flags] of DESIGNS) for (const [sizeName, w, h] of SIZES) for (const [name, screen, step] of SCREENS) {
      const page = await browser.newPage({ viewport: { width: w, height: h }, colorScheme: "dark" });
      const errors = [];
      page.on("pageerror", (e) => errors.push(e.message.split("\n")[0]));
      const t0 = Date.now();
      await page.goto(`${base}scripts/screens/index.html?screen=${screen}&theme=dark${flags}`);
      // The first paint: how long until the root draws anything.
      let firstPaint = null;
      for (let i = 0; i < 40; i++) {
        if (await page.evaluate(() => (document.getElementById("root")?.textContent ?? "").trim().length > 0)) { firstPaint = Date.now() - t0; break; }
        await page.waitForTimeout(100);
      }
      await page.waitForTimeout(1500);
      try {
        if (step[design]) await step[design](page);
      } catch (e) {
        errors.push(`step failed: ${String(e.message).split("\n")[0]}`);
      }
      const before = await page.evaluate(scrollers);
      // The wheel and the trackpad over the middle of the content.
      await page.mouse.move(Math.round(w * 0.6), Math.round(h * 0.6));
      await page.mouse.wheel(0, 600);
      await page.waitForTimeout(400);
      const after = await page.evaluate(scrollers);
      const moved = before.length === 0 ? null : after.some((a, i) => a.top !== (before.find((b) => b.cls === a.cls)?.top ?? a.top) || i < 0);
      const file = `${engineName}-${design}-${sizeName}-${name}.png`;
      await page.screenshot({ path: join(out, file) });
      const row = { engine: engineName, design, size: sizeName, screen: name, firstPaint, scrollers: before.map((s) => `${s.cls} ${s.h}/${s.sh}`), wheelScrolled: moved, errors, file };
      report.push(row);
      console.log(JSON.stringify({ e: engineName, d: design, s: sizeName, n: name, paint: firstPaint, scrollers: before.length, wheel: moved, errors: errors.length }));
      await page.close();
    }
  } finally {
    await browser.close();
  }
}
await server.close();
await writeFile(join(out, "report.json"), JSON.stringify(report, null, 1));
