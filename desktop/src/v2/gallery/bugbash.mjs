// The desktop v2 bug bash (Day 5): every v2 screen at 100/130/200 % text, light and dark, English and Urdu (the
// gallery's screens; the app's own v2 screens are English), at 1280 x 800 and at the 720 px compact width. Each page is
// photographed and checked in the DOM for what a person would see as broken: the page scrolling sideways, text cut
// without an ellipsis, something past the window's edge, and controls on top of each other.
//   node src/v2/gallery/bugbash.mjs --out=DIR [--only=gallery|app] [--case=name]
// Text size is the window at 1/scale of its CSS size with that device scale, as when the system text grows.
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir, writeFile } from "node:fs/promises";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { CASES } from "./cases.mjs";
import { audit } from "./checks.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..", "..", "..");
const arg = (name) => process.argv.find((a) => a.startsWith(`--${name}=`))?.split("=")[1];
const out = resolve(arg("out") ?? join(desktop, "..", "docs", "v2", "bugbash", "desktop-day5"));
const only = arg("only");
const onlyCase = arg("case");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";

const THEMES = ["light", "dark"];
const TEXT = [100, 130, 200];
const WIDTHS = [1280, 720];

const server = await createServer({ root: desktop, configFile: join(desktop, "vite.config.ts"), server: { port: 1450, strictPort: false }, logLevel: "error" });
await server.listen();
const base = server.resolvedUrls.local[0];
await mkdir(out, { recursive: true });
const browser = await chromium.launch();
const report = [];
try {
  for (const [name, kind, path, langs, step] of CASES) {
    if ((only && kind !== only) || (onlyCase && name !== onlyCase)) continue;
    for (const lang of langs) for (const theme of THEMES) for (const text of TEXT) for (const width of WIDTHS) {
      const scale = text / 100;
      const page = await browser.newPage({ viewport: { width: Math.round(width / scale), height: Math.round(800 / scale) }, deviceScaleFactor: scale, colorScheme: theme, reducedMotion: "reduce" });
      const errors = [];
      page.on("pageerror", (e) => errors.push(e.message));
      await page.goto(`${base}${path}&theme=${theme}${kind === "gallery" ? `&lang=${lang}` : ""}`);
      await page.waitForTimeout(kind === "app" ? 2200 : 700);
      try {
        if (step) await step(page);
      } catch (e) {
        errors.push(`step failed: ${String(e.message).split("\n")[0]}`);
      }
      const file = `${name}-${lang}-${theme}-t${text}-w${width}.png`;
      await page.screenshot({ path: join(out, file) });
      const issues = [...(await page.evaluate(audit)), ...errors.map((e) => `script error: ${e}`)];
      report.push({ file, name, lang, theme, text, width, issues });
      await page.close();
    }
  }
} finally {
  await browser.close();
  await server.close();
}
await writeFile(join(out, `report${only ? `-${only}` : ""}${onlyCase ? `-${onlyCase}` : ""}.json`), JSON.stringify(report, null, 1));
const bad = report.filter((r) => r.issues.length);
console.log(`${report.length} pages, ${bad.length} with findings`);
for (const r of bad) console.log(r.file, "\n  " + r.issues.join("\n  "));
