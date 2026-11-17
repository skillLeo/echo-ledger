// Gate 3 screenshots of the v2 sample screens: library, recording, settings and plan (Plan & usage), light and dark,
// English and Urdu, at 1280 x 800, into docs/v2/gate3/desktop/ (library-light-en.png …). Runs the Vite dev server (the gallery exists
// only there) and the repo's Playwright Chromium, with reduced motion.
//   node src/v2/gallery/shoot.mjs                      the 12 pictures
//   node src/v2/gallery/shoot.mjs --states            the loading, empty and error states into docs/v2/gate3/desktop/states/
//                                                      (library-loading-light-en.png …)
//   node src/v2/gallery/shoot.mjs --text=200 --out=DIR  the same at 200 % text, into DIR (for checking, not committed):
//   the window keeps its 1280 x 800 pixels at twice the scale, as when the system text size grows.
//   node src/v2/gallery/shoot.mjs --app               every v2 screen of the app itself (fixture mode, cases.mjs GATE3),
//                                                      light English, light right to left and dark English, into
//                                                      docs/v2/gate3/desktop/app/, and docs/v2/gate3/desktop/index.md
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir, readdir, writeFile } from "node:fs/promises";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { CASES, GATE3 } from "./cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..", "..", "..");
const arg = (name) => process.argv.find((a) => a.startsWith(`--${name}=`))?.split("=")[1];
const text = arg("text") ?? "100";
const out = arg("out") ? resolve(arg("out")) : join(desktop, "..", "docs", "v2", "gate3", "desktop");
const only = arg("screen");
const statesMode = process.argv.includes("--states");
const states = statesMode ? ["loading", "empty", "error"] : ["ready"];
const appMode = process.argv.includes("--app");
const dir = statesMode ? join(out, "states") : appMode ? join(out, "app") : out;
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
/** The app's captures: light English, light right to left, dark English. */
const APP_VARIANTS = [["light", "en"], ["light", "ur"], ["dark", "en"]];

/** Photographs one app screen in each variant; says whether it opened a classic page and what went wrong. */
async function shootApp(browser, base, [file, caseName, , focus]) {
  const [, , path, , step] = CASES.find(([name]) => name === caseName);
  const result = { classic: false, problems: [] };
  for (const [theme, lang] of APP_VARIANTS) {
    const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, colorScheme: theme, reducedMotion: "reduce" });
    page.on("pageerror", (e) => result.problems.push(`${theme}-${lang}: ${e.message.split("\n")[0]}`));
    await page.goto(`${base}${path}&theme=${theme}${lang === "ur" ? "&lang=ur" : ""}`);
    await page.waitForTimeout(2200);
    try {
      if (step) await step(page);
      if (focus) await page.locator(focus).first().scrollIntoViewIfNeeded();
    } catch (e) {
      result.problems.push(`${theme}-${lang}: step failed: ${String(e.message).split("\n")[0]}`);
    }
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(300);
    // Today's Settings page inside the v2 frame wears .settings-root; a v2 page never does.
    if (await page.evaluate(() => !!document.querySelector(".settings-root"))) result.classic = true;
    await page.screenshot({ path: join(dir, `${file}-${theme}-${lang}.png`) });
    await page.close();
  }
  return result;
}

/** docs/v2/gate3/desktop/index.md: one line per screen, with its pictures. */
async function writeIndex(results) {
  const samples = (await readdir(out)).filter((f) => f.endsWith(".png")).sort();
  const states = (await readdir(join(out, "states")).catch(() => [])).filter((f) => f.endsWith(".png")).sort();
  const stems = (files) => [...new Set(files.map((f) => f.replace(/-(light|dark)-(en|ur)(-text\d+)?\.png$/, "")))];
  const links = (files, prefix, stem) => files.filter((f) => new RegExp(`^${stem}-(light|dark)-(en|ur)\\.png$`).test(f))
    .map((f) => `[${f.slice(stem.length + 1, -4)}](${prefix}${f})`).join(" · ");
  const classic = results.filter((r) => r.classic);
  const lines = [
    "# Gate 3: the desktop v2 screens",
    "",
    "Made by `node src/v2/gallery/shoot.mjs` (the sample screens), `--states` and `--app` (the app's own v2 screens, in",
    "the fixture mode of `scripts/screens/main.tsx`), at 1280 x 800 with reduced motion. Every app screen is taken light",
    "in English, light right to left (`ur`) and dark in English.",
    "",
    "The desktop's own words are English: it has no Urdu interface yet. The `ur` pictures of the app are its layout",
    "right to left, as an Urdu reader would have it; Urdu shows where the content itself is Urdu. The sample screens",
    "(the first table) are written in Urdu.",
    "",
    classic.length
      ? `**Still opening today's (classic) page:** ${classic.map((r) => r.title).join(", ")}.`
      : "**Every screen below opens its v2 page; none still opens today's (classic) page.**",
    "",
    "## Sample screens (the gallery, English and Urdu)",
    "",
    "| Screen | Pictures |",
    "| --- | --- |",
    ...stems(samples).map((stem) => `| ${stem} | ${links(samples, "", stem)} |`),
    "",
    "## Their loading, empty and error states",
    "",
    "| Screen and state | Pictures |",
    "| --- | --- |",
    ...stems(states).map((stem) => `| ${stem} | ${links(states, "states/", stem)} |`),
    "",
    "## The app's v2 screens",
    "",
    "| Screen | Pictures | Notes |",
    "| --- | --- | --- |",
    ...results.map((r) => `| ${r.title} | ${APP_VARIANTS.map(([t, l]) => `[${t}-${l}](app/${r.file}-${t}-${l}.png)`).join(" · ")} | ${[r.classic ? "opens today's page" : "", ...r.problems].filter(Boolean).join("; ")} |`),
    "",
  ];
  await writeFile(join(out, "index.md"), lines.join("\n"));
}

const server = await createServer({ root: desktop, configFile: join(desktop, "vite.config.ts"), server: { port: 1436, strictPort: false }, logLevel: "error" });
await server.listen();
const base = server.resolvedUrls.local[0];
await mkdir(dir, { recursive: true });
const browser = await chromium.launch();
try {
  if (appMode) {
    const results = [];
    for (const entry of GATE3) {
      if (only && entry[0] !== only) continue;
      results.push({ file: entry[0], title: entry[2], ...(await shootApp(browser, base, entry)) });
      console.log(entry[0], results.at(-1).classic ? "(classic)" : "", results.at(-1).problems.join("; "));
    }
    if (!only) await writeIndex(results);
  }
  // Plan & usage is in the gallery for its right-to-left meters; it has no loading, empty or error state of its own.
  for (const screen of appMode ? [] : statesMode ? ["library", "recording", "settings"] : ["library", "recording", "settings", "plan"]) {
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
