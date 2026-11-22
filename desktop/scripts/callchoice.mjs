// The call choice (docs/v2/copy/call-choice.md) on the desktop: the after-call card and the recording pill, in both
// designs (classic and the new design's flags), light and dark, left to right ("en") and right to left ("ur": the
// desktop's words stay English, the window is laid out for an Urdu reader), each window at its own size, into
// docs/design/screens/call-choice/desktop-<tag>-<surface>-<design>-<theme>-<lang>.png. Run once before a change with
// --tag=old and once after with --tag=new, so the pictures sit side by side.
//   node scripts/callchoice.mjs --tag=old|new [--only=card,pill,...]
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const arg = (name) => process.argv.find((a) => a.startsWith(`--${name}=`))?.split("=")[1];
const tag = arg("tag") ?? "new";
const only = arg("only")?.split(",");
const out = join(desktop, "..", "docs", "design", "screens", "call-choice");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";

/** The floating windows' order on the fixture's canvas (scripts/screens/main.tsx): pill, after-call window, captions. */
const PILL = 0;
const REVIEW = 1;

/** [surface, fixture screen, window, what to do first] */
const SURFACES = [
  ["card", "controls-card", REVIEW, async (page, design) => page.locator(design === "v2" ? "[data-testid=after-call-card]" : "[data-testid=keep-chip], [data-testid=after-call-card]").first().waitFor()],
  ["pill", "controls-card", PILL, async (page, design) => {
    await page.locator(design === "v2" ? "[data-testid=recording-pill-v2]" : "[data-testid=recording-pill]").first().waitFor();
    // The new design's pill opens its controls (or, after the change, its menu) on a press; classic's are always out.
    if (design === "v2") await page.locator(".el-pill__summary").first().click();
    else if (tag === "new") await page.locator("[data-testid=pill-choice]").first().click();
    await page.waitForTimeout(300);
  }],
  ...(tag === "new" ? [
    ["done", "controls-done", REVIEW, async (page) => page.locator("[data-testid=call-done]").first().waitFor()],
    ["group", "controls-group", REVIEW, async (page) => page.locator("[data-testid=call-group]").first().waitFor()],
    ["banner", "controls-banner", PILL, async (page) => page.locator("[data-testid=start-banner]").first().waitFor()],
    ["always", "controls-always", REVIEW, async (page) => page.locator("[data-testid=call-always]").first().waitFor()],
  ] : []),
];

async function launch() {
  for (const options of [{}, { channel: "chrome" }]) {
    try {
      return await chromium.launch(options);
    } catch {
      // Try the next browser.
    }
  }
  throw new Error("No Chromium browser to take the screenshots with");
}

const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5207, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await launch();
await mkdir(out, { recursive: true });
const failed = [];
try {
  for (const [surface, screen, index, prepare] of SURFACES) {
    if (only && !only.includes(surface)) continue;
    for (const design of ["classic", "v2"]) {
      for (const theme of ["light", "dark"]) {
        for (const lang of ["en", "ur"]) {
          const name = `desktop-${tag}-${surface}-${design}-${theme}-${lang}`;
          const page = await browser.newPage({ viewport: { width: 1200, height: 700 }, colorScheme: theme, reducedMotion: "reduce", deviceScaleFactor: 2 });
          page.on("pageerror", (e) => console.error(`${name}: ${e.message.split("\n")[0]}`));
          const flags = design === "v2" ? `&uiflags=${FLAGS}` : "";
          await page.goto(`${base}scripts/screens/index.html?screen=${screen}&theme=${theme}${lang === "ur" ? "&lang=ur" : ""}${flags}`);
          try {
            await page.waitForTimeout(900);
            await prepare(page, design);
            await page.mouse.move(0, 0);
            await page.evaluate(() => document.fonts.ready);
            await page.waitForTimeout(400);
            const file = join(out, `${name}.png`);
            await page.locator(".screens-window").nth(index).screenshot({ path: file });
            console.log(file);
          } catch (e) {
            failed.push(name);
            console.error(`${name}: ${String(e.message).split("\n")[0]}`);
          }
          await page.close();
        }
      }
    }
  }
} finally {
  await browser.close();
  await server.close();
}
if (failed.length) {
  console.error(`Not taken: ${failed.join(", ")}`);
  process.exitCode = 1;
}
