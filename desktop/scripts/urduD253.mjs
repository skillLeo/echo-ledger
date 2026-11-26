// Desktop Urdu, D-253, in the screens fixture (every v2 flag and v21_urdu on, the account's language Urdu: "&applang=ur",
// 1280 × 800), on a Mac (a Mac's window and a Mac computer: its keys ⌘) and on Windows, in the light and the dark theme:
// the rail in Urdu (its places, Setup, Help, Settings, the account, a place's tooltip with its key), Record's menu (More
// ways to record), and the ⌘K search's own commands and pages. A Mac draws Record's menu itself (a native menu, which a
// browser can't show): here the page's own menu stands in for it, with the same words. It checks that the window reads
// right to left, that each scene shows its Urdu, that the keys read left to right, that no menu item or rail label is
// cut and that a tooltip's words, Nastaliq's strokes and all, stay inside its box; it saves
// docs/design/screens/desktop/urdu-d253/<platform>-<step>.png (light) and <platform>-<step>-dark.png, and fails when a
// check doesn't hold.
//   node scripts/urduD253.mjs
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { onScreen } from "./textOnScreen.mjs";
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

/**
 * The open menus' items and the rail's labels (none cut), the tooltips (their words drawn inside their box), the menus'
 * keys (left to right), and the rail's labels. A tooltip's box is as wide as its words, but Nastaliq's strokes reach
 * above and beyond the letters' boxes, so its ink is found: the words drawn again on a canvas in the tooltip's font
 * (the same shaping), its inked pixels read, placed from the line's baseline (an empty inline-block put at the line's
 * end for a moment sits on it) and its start edge, against the box's edges.
 */
const drawn = (page) => page.evaluate(() => {
  const cut = [];
  const keys = [];
  for (const label of document.querySelectorAll('[role="menu"] .el-menu__label, .el-rail__label, [role="tooltip"]')) {
    if (label.scrollWidth > label.clientWidth + 1) cut.push(label.textContent);
  }
  for (const tip of document.querySelectorAll('[role="tooltip"]')) {
    const look = getComputedStyle(tip);
    const box = tip.getBoundingClientRect();
    const range = document.createRange();
    range.selectNodeContents(tip);
    const words = range.getBoundingClientRect();
    const foot = document.createElement("span");
    foot.style.cssText = "display: inline-block; inline-size: 0; block-size: 0; vertical-align: baseline;";
    tip.append(foot);
    const baseline = foot.getBoundingClientRect().bottom;
    foot.remove();
    // The words on a canvas 4 times the size, their start edge at `x`, their baseline at `y`, room all round.
    const scale = 4;
    const size = parseFloat(look.fontSize);
    const [w, h, x, y] = [words.width + size * 8, size * 8, words.width + size * 4, size * 5];
    const canvas = document.createElement("canvas");
    [canvas.width, canvas.height] = [w * scale, h * scale];
    const pen = canvas.getContext("2d");
    pen.scale(scale, scale);
    pen.font = `${look.fontStyle} ${look.fontWeight} ${look.fontSize} ${look.fontFamily}`;
    pen.direction = look.direction;
    pen.textAlign = look.direction === "rtl" ? "right" : "left";
    pen.textBaseline = "alphabetic";
    pen.fillText(tip.textContent, look.direction === "rtl" ? x : size * 4, y);
    const { data } = pen.getImageData(0, 0, canvas.width, canvas.height);
    let [minX, maxX, minY, maxY] = [Infinity, -Infinity, Infinity, -Infinity];
    for (let py = 0; py < canvas.height; py++) {
      for (let px = 0; px < canvas.width; px++) {
        if (data[(py * canvas.width + px) * 4 + 3] < 64) continue;
        minX = Math.min(minX, px); maxX = Math.max(maxX, px); minY = Math.min(minY, py); maxY = Math.max(maxY, py);
      }
    }
    if (minX === Infinity) continue;
    // The canvas's start edge is the line's: the right one right to left.
    const shift = look.direction === "rtl" ? words.right - x : words.left - size * 4;
    const ink = { left: minX / scale + shift, right: (maxX + 1) / scale + shift, top: baseline - (y - minY / scale), bottom: baseline + ((maxY + 1) / scale - y) };
    const out = [box.left - ink.left, ink.right - box.right, box.top - ink.top, ink.bottom - box.bottom].map((d) => Math.round(d * 10) / 10);
    if (out.some((d) => d > 1)) cut.push(`the tooltip “${tip.textContent}” (its ink past its box by ${out.join("/")} px: left/right/top/bottom)`);
  }
  for (const kbd of document.querySelectorAll('[role="menu"] kbd.el-menu__shortcut')) {
    keys.push({ text: kbd.textContent, dir: kbd.getAttribute("dir"), drawn: getComputedStyle(kbd).direction });
  }
  return { cut, keys, rail: [...document.querySelectorAll(".el-rail__label")].map((l) => l.textContent) };
});

try {
  for (const [platform, theme] of [["macos", "light"], ["macos", "dark"], ["windows", "light"], ["windows", "dark"]]) {
    // A Mac: its window ("platform") and its computer ("computer": the app is told it runs on a Mac, so the keys it
    // takes from that are ⌘'s, the search's and Record's menu's).
    const where = platform === "windows" ? "&platform=windows" : "&platform=macos&computer=macos&lights=1";
    const tag = `${platform} (${theme})`;
    const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, colorScheme: theme, reducedMotion: "reduce" });
    page.setDefaultTimeout(120_000);
    await asComputer(page, platform);
    await page.goto(`${base}scripts/screens/index.html?screen=library&uiflags=${FLAGS},v21_urdu${where}&theme=${theme}&applang=ur`);
    await page.getByRole("navigation", { name: "Main" }).waitFor();
    await page.waitForFunction(() => document.documentElement.dir === "rtl", null, { timeout: 30_000 }).catch(() => undefined);
    await page.waitForTimeout(1000);

    const shot = async (step, words, { pointer = false } = {}) => {
      if (!pointer) await page.mouse.move(0, 0);
      await page.waitForTimeout(500);
      await page.screenshot({ path: join(out, `${platform}-${step}${theme === "dark" ? "-dark" : ""}.png`) });
      const at = await root(page);
      const speaks = await onScreen(page, words);
      const { cut, keys, rail } = await drawn(page);
      console.log(`${tag}: ${step}; <html> ${at}; ${speaks ? "in Urdu" : "NOT in Urdu"}; rail ${rail.join(" · ")}; keys ${keys.map((k) => k.text).join(" ") || "none"}${cut.length ? `; CUT ${cut.join(", ")}` : ""}`);
      if (at !== "ur/rtl") problems.push(`${tag}: ${step} is ${at}, not ur/rtl`);
      if (!speaks) problems.push(`${tag}: ${step} doesn't show “${words}”`);
      if (cut.length) problems.push(`${tag}: ${step} cuts ${cut.join(", ")}`);
      if (rail.some((l) => /[A-Za-z]/.test(l ?? ""))) problems.push(`${tag}: ${step}'s rail has English: ${rail.join(", ")}`);
      for (const k of keys) if (k.dir !== "ltr" || k.drawn !== "ltr" || /[؀-ۿ]/.test(k.text)) problems.push(`${tag}: ${step}'s key ${k.text} is not Latin, left to right`);
      // A Mac's keys are ⌘'s, Windows' Ctrl's.
      if (keys.some((k) => (platform === "macos" ? /Ctrl/ : /[⌘⌥⇧⌃]/).test(k.text))) problems.push(`${tag}: ${step}'s keys are another computer's: ${keys.map((k) => k.text).join(" ")}`);
    };
    const escape = async () => {
      for (let i = 0; i < 3 && await page.getByRole("menu").count(); i++) {
        await page.keyboard.press("Escape");
        await page.waitForTimeout(200);
      }
    };

    // The rail, a place's tooltip with its key (after the tooltip's delay).
    const rail = page.getByRole("navigation", { name: "Main" });
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
