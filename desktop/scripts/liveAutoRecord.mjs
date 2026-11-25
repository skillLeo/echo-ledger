// UX-15 on the desktop: the Live page's Auto-record calls switch, as the phone's Live tab has it (the same switch as
// Record's menu), with how calls stand under it, and Calls & meetings for its settings. In the screens fixture (every v2
// flag on, 1280 × 800), on a Mac and on Windows, light and dark, in English and right to left (Urdu), and with the
// Ready check finding a problem ("&older=1": Calls: set up first). Each is measured: the switch is there by its name
// (the design's own switch, as in Settings), reached by Tab, and its line says how calls stand. Saved under
// docs/design/screens/desktop/ux-15-live/<platform>-<theme>-<lang>[-setup].png; the run fails on anything missing.
//   node scripts/liveAutoRecord.mjs
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "ux-15-live");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const CASES = [
  { platform: "macos", theme: "light", lang: "en" },
  { platform: "macos", theme: "dark", lang: "en" },
  { platform: "windows", theme: "light", lang: "en" },
  { platform: "windows", theme: "dark", lang: "en" },
  { platform: "macos", theme: "light", lang: "ur" },
  { platform: "windows", theme: "light", lang: "ur" },
  { platform: "macos", theme: "light", lang: "en", setup: true },
];

const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5261, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];

try {
  for (const c of CASES) {
    const label = `${c.platform}-${c.theme}-${c.lang}${c.setup ? "-setup" : ""}`;
    const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, colorScheme: c.theme, reducedMotion: "reduce" });
    page.setDefaultTimeout(120_000);
    const where = c.platform === "windows" ? "&platform=windows" : "&platform=macos&lights=1";
    await page.goto(`${base}scripts/screens/index.html?screen=library&theme=${c.theme}&uiflags=${FLAGS}${where}${c.lang === "ur" ? "&lang=ur" : ""}${c.setup ? "&older=1" : ""}`);
    const rail = page.getByRole("navigation", { name: "Main" });
    await rail.waitFor();
    await page.waitForTimeout(1000);
    await rail.getByRole("button", { name: /^Live/ }).first().click();
    await page.getByRole("heading", { level: 1, name: "Live" }).waitFor();
    await page.mouse.move(0, 0);
    await page.waitForTimeout(600);
    await page.screenshot({ path: join(out, `${label}.png`) });
    const m = await page.evaluate(() => {
      const list = document.querySelector('[aria-label="Recording"][role="list"], ul[aria-label="Recording"]');
      const sw = [...(list?.querySelectorAll('[role="switch"]') ?? [])].find((s) => {
        const by = document.getElementById(s.getAttribute("aria-labelledby") ?? "");
        return (by?.textContent ?? s.getAttribute("aria-label")) === "Auto-record calls";
      });
      const row = sw?.closest("li");
      const box = sw?.getBoundingClientRect();
      const named = (b) => b.getAttribute("aria-label") ?? document.getElementById(b.getAttribute("aria-labelledby") ?? "")?.textContent ?? b.textContent;
      const link = [...(list?.querySelectorAll("button") ?? [])].find((b) => named(b)?.trim() === "Calls & meetings");
      return {
        switch: !!sw, checked: sw?.getAttribute("aria-checked"), size: box ? `${Math.round(box.width)}×${Math.round(box.height)}` : null,
        line: row?.textContent?.replaceAll("Auto-record calls", "").trim() ?? null, link: !!link,
      };
    });
    // Tab reaches it (from the page's top).
    await page.getByRole("heading", { level: 1, name: "Live" }).click();
    let reached = false;
    for (let i = 0; i < 12 && !reached; i++) {
      await page.keyboard.press("Tab");
      reached = await page.evaluate(() => document.activeElement?.getAttribute("role") === "switch");
    }
    console.log(`${label}: switch ${m.switch ? `${m.checked === "true" ? "on" : "off"} ${m.size}` : "MISSING"}; "${m.line}"; Calls & meetings ${m.link ? "there" : "MISSING"}; Tab ${reached ? "reaches it" : "DOESN'T REACH IT"}`);
    const want = c.setup ? "Calls: set up first" : "Calls: auto-record on";
    const bad = [!m.switch && "no Auto-record calls switch", m.line !== want && `its line says "${m.line}", not "${want}"`, !m.link && "no Calls & meetings row", !reached && "Tab doesn't reach the switch"].filter(Boolean);
    if (bad.length) problems.push(`${label}: ${bad.join("; ")}`);
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
