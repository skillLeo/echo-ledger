// Desktop Urdu, D-253, in the screens fixture (every v2 flag and v21_urdu on, the account's language Urdu: "&applang=ur",
// 1280 × 800), on a Mac and on Windows, light: the rail in Urdu (its places, Setup, Help, Settings, the account, a
// place's tooltip with its key), Record's menu (More ways to record), and the ⌘K search's own commands and pages. A Mac
// draws Record's menu itself (a native menu, which a browser can't show): here the page's own menu stands in for it,
// with the same words. It checks that the window reads right to left, that each scene shows its Urdu, that the keys
// read left to right and that no menu item is cut, and saves docs/design/screens/desktop/urdu-d253/<platform>-<step>.png;
// the run fails when a check doesn't hold.
//   node scripts/urduD253.mjs
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "urdu-d253");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5273, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];
const root = (page) => page.evaluate(() => `${document.documentElement.lang || "-"}/${document.documentElement.dir || "-"}`);

/** The computer the page thinks it runs on, and on a Mac a system menu that can't be drawn (the page shows its own). */
const asComputer = (page, platform) => page.addInitScript((mac) => {
  Object.defineProperty(navigator, "platform", { value: mac ? "MacIntel" : "Win32", configurable: true });
  const internals = {};
  let mocked = null;
  Object.defineProperty(internals, "invoke", {
    configurable: true,
    set: (invoke) => { mocked = invoke; },
    get: () => async (cmd, args, options) => {
      if (cmd === "native_menu_popup") throw new Error("A browser draws no system menu.");
      return mocked(cmd, args, options);
    },
  });
  window.__TAURI_INTERNALS__ = internals;
}, platform === "macos");

/** The open menus' items (none cut) and their keys (left to right), and the rail's labels. */
const drawn = (page) => page.evaluate(() => {
  const cut = [];
  const keys = [];
  for (const label of document.querySelectorAll('[role="menu"] .el-menu__label, .el-rail__label')) {
    if (label.scrollWidth > label.clientWidth + 1) cut.push(label.textContent);
  }
  for (const kbd of document.querySelectorAll('[role="menu"] kbd.el-menu__shortcut')) {
    keys.push({ text: kbd.textContent, dir: kbd.getAttribute("dir"), drawn: getComputedStyle(kbd).direction });
  }
  return { cut, keys, rail: [...document.querySelectorAll(".el-rail__label")].map((l) => l.textContent) };
});

try {
  for (const platform of ["macos", "windows"]) {
    const where = platform === "windows" ? "&platform=windows" : "&platform=macos&lights=1";
    const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, colorScheme: "light", reducedMotion: "reduce" });
    page.setDefaultTimeout(120_000);
    await asComputer(page, platform);
    await page.goto(`${base}scripts/screens/index.html?screen=library&uiflags=${FLAGS},v21_urdu${where}&theme=light&applang=ur`);
    await page.locator('nav[data-nav="main"]').waitFor();
    await page.waitForFunction(() => document.documentElement.dir === "rtl", null, { timeout: 30_000 }).catch(() => undefined);
    await page.waitForTimeout(1000);

    const shot = async (step, words, { pointer = false } = {}) => {
      if (!pointer) await page.mouse.move(0, 0);
      await page.waitForTimeout(500);
      await page.screenshot({ path: join(out, `${platform}-${step}.png`) });
      const at = await root(page);
      const speaks = await page.getByText(words, { exact: false }).first().isVisible().catch(() => false);
      const { cut, keys, rail } = await drawn(page);
      console.log(`${platform}: ${step}; <html> ${at}; ${speaks ? "in Urdu" : "NOT in Urdu"}; rail ${rail.join(" · ")}; keys ${keys.map((k) => k.text).join(" ") || "none"}${cut.length ? `; CUT ${cut.join(", ")}` : ""}`);
      if (at !== "ur/rtl") problems.push(`${platform}: ${step} is ${at}, not ur/rtl`);
      if (!speaks) problems.push(`${platform}: ${step} doesn't show “${words}”`);
      if (cut.length) problems.push(`${platform}: ${step} cuts ${cut.join(", ")}`);
      if (rail.some((l) => /[A-Za-z]/.test(l ?? ""))) problems.push(`${platform}: ${step}'s rail has English: ${rail.join(", ")}`);
      for (const k of keys) if (k.dir !== "ltr" || k.drawn !== "ltr" || /[؀-ۿ]/.test(k.text)) problems.push(`${platform}: ${step}'s key ${k.text} is not Latin, left to right`);
    };
    const escape = async () => {
      for (let i = 0; i < 3 && await page.getByRole("menu").count(); i++) {
        await page.keyboard.press("Escape");
        await page.waitForTimeout(200);
      }
    };

    // The rail, a place's tooltip with its key (after the tooltip's delay).
    const rail = page.locator('nav[data-nav="main"]');
    await rail.getByRole("button", { name: "پروجیکٹس" }).hover();
    await page.waitForTimeout(900);
    await shot("rail", "پروجیکٹس", { pointer: true });

    // Record's menu, from the chevron of the rail's split button.
    await rail.getByRole("button", { name: "ریکارڈ کرنے کے مزید طریقے" }).click();
    await page.getByRole("menu", { name: "ریکارڈ کرنے کے مزید طریقے" }).waitFor();
    await shot("record-menu", "نئی ریکارڈنگ");
    await escape();

    // ⌘K (Ctrl+K on Windows): the palette's own commands and the pages, "لائبریری" typed.
    await page.keyboard.press(platform === "macos" ? "Meta+k" : "Control+k");
    const field = page.locator(".palette-input");
    await field.waitFor();
    await field.fill("لائبریری");
    await page.waitForTimeout(900);
    const commands = page.getByRole("group", { name: "کمانڈز" });
    await commands.scrollIntoViewIfNeeded();
    await shot("palette", "لائبریری سنک کریں");
    await page.keyboard.press("Escape");
    await page.waitForTimeout(300);
    // The palette found by English too: "go to" lists the places' lines.
    await page.keyboard.press(platform === "macos" ? "Meta+k" : "Control+k");
    await field.waitFor();
    await field.fill("go to");
    await page.waitForTimeout(900);
    await commands.scrollIntoViewIfNeeded();
    await shot("palette-english", "پر جائیں");
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
