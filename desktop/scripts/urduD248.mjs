// Desktop Urdu, D-248, in the screens fixture (every v2 flag and v21_urdu on, the account's language Urdu: "&applang=ur",
// 1280 × 800), on a Mac and on Windows, in the light and the dark theme: the commands of actions.json in Urdu wherever
// they are shown. A row's right-click menu; the selection bar's ⋮ (More); a label's menu with its Colour ›; the
// recording page's ⋮; the ⌘K search with Urdu results. A Mac draws its right-click menus itself (a native menu, which a
// browser can't show): here the page's own menu stands in for it, with the same words (Urdu as written, no Title Case,
// no shortcuts). It checks that the window reads right to left; that each scene shows its Urdu on the screen (read off
// the page: drawn, in the window and not scrolled out of its box); that every item of an open menu (its first level,
// and a sub-menu of the app's own words, Colour's) has no Latin letter but in the kept names (EchoLedger, WhatsApp…:
// decision 30), its shortcut apart; that none of the Library's English words D1b put in Urdu (Labels, New label,
// the colours) is on the screen; that the menus' keys read left to right; and that no menu item is cut. It saves
// docs/design/screens/desktop/urdu-d248/<platform>-<step>.png (light) and <platform>-<step>-dark.png, and fails when a
// check doesn't hold.
//   node scripts/urduD248.mjs
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { onScreen } from "./textOnScreen.mjs";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "urdu-d248");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5271, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];
const root = (page) => page.evaluate(() => `${document.documentElement.lang || "-"}/${document.documentElement.dir || "-"}`);

/**
 * The computer the page thinks it runs on (the menus' platform), and on a Mac a system menu that can't be drawn, so the
 * page shows its own (popupNative answers false), around the fixture's IPC mock.
 */
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

/** The names kept as they are inside Urdu (decision 30): the app's, the brands' and the plans'; longer ones first. */
const KEPT = ["WhatsApp Business", "WhatsApp Web", "WhatsApp", "EchoLedger", "Google", "Meet", "Zoom", "Teams", "Groq", "Ultra", "Pro", "Free"];
/** English words D1b says in Urdu (the sidebar's Labels and New label, the colours), so never on an Urdu screen. */
const ENGLISH = ["Labels", "New label", "New label…", "Colour", "Grey", "Red", "Orange", "Amber", "Green", "Teal", "Blue", "Pink"];

/**
 * Every open menu's items, read off the page: each item's words (its shortcut apart, which stays Latin) for the menus
 * at the first level, and for every open menu with `all` (a sub-menu of the app's own words); none cut (its words
 * wider than their box); each shortcut left to right.
 */
const menusDrawn = (page, all) => page.evaluate(({ all }) => {
  const cut = [];
  const keys = [];
  const items = [];
  for (const label of document.querySelectorAll('[role="menu"] .el-menu__label')) {
    if (label.scrollWidth > label.clientWidth + 1) cut.push(label.textContent);
  }
  for (const kbd of document.querySelectorAll('[role="menu"] kbd.el-menu__shortcut')) {
    keys.push({ text: kbd.textContent, dir: kbd.getAttribute("dir"), drawn: getComputedStyle(kbd).direction });
  }
  for (const menu of document.querySelectorAll('[role="menu"]')) {
    if (!all && menu.parentElement?.closest('[role="menu"]')) continue;
    for (const item of menu.querySelectorAll('[role^="menuitem"]')) {
      if (item.closest('[role="menu"]') !== menu) continue;
      const words = item.cloneNode(true);
      for (const key of words.querySelectorAll(".el-menu__shortcut")) key.remove();
      items.push(words.textContent.trim());
    }
  }
  return { cut, keys, items };
}, { all });


/** Which of the English words are on the screen as a whole line of their own (a label, a heading, a button). */
const englishShown = (page) => page.evaluate((english) => {
  const found = new Set();
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  for (let node = walker.nextNode(); node; node = walker.nextNode()) {
    const words = node.textContent.trim();
    if (!english.includes(words) || !node.parentElement || node.parentElement.closest(".el-visually-hidden")) continue;
    const r = node.parentElement.getBoundingClientRect();
    if (r.width > 1 && r.height > 1 && r.bottom > 0 && r.top < innerHeight && r.right > 0 && r.left < innerWidth) found.add(words);
  }
  return [...found];
}, ENGLISH);

/** Latin letters, but in the kept names. */
const latinIn = (words) => /[A-Za-z]/.test(KEPT.reduce((rest, name) => rest.split(name).join(""), words));

try {
  for (const [platform, theme] of [["macos", "light"], ["macos", "dark"], ["windows", "light"], ["windows", "dark"]]) {
    const where = `${platform === "windows" ? "&platform=windows" : "&platform=macos&lights=1"}&theme=${theme}`;
    const label = `${platform} (${theme})`;
    const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, colorScheme: theme, reducedMotion: "reduce" });
    page.setDefaultTimeout(120_000);
    await asComputer(page, platform);
    await page.goto(`${base}scripts/screens/index.html?screen=library&uiflags=${FLAGS},v21_urdu${where}&applang=ur`);
    await page.locator('nav[data-nav="main"]').waitFor();
    await page.waitForFunction(() => document.documentElement.dir === "rtl", null, { timeout: 30_000 }).catch(() => undefined);
    await page.waitForTimeout(1000);

    /**
     * Saves the window, after checking its direction, that `words` (Urdu) are on the screen, that no English word of
     * the Library's is, and the open menus' items (`all`: a sub-menu's too, when it holds only the app's words).
     */
    const shot = async (step, words, { pointer = false, all = false } = {}) => {
      if (!pointer) await page.mouse.move(0, 0);
      await page.waitForTimeout(500);
      await page.screenshot({ path: join(out, `${platform}-${step}${theme === "dark" ? "-dark" : ""}.png`) });
      const at = await root(page);
      const speaks = await onScreen(page, words);
      const english = await englishShown(page);
      const { cut, keys, items } = await menusDrawn(page, all);
      const latin = items.filter(latinIn);
      console.log(`${label}: ${step}; <html> ${at}; ${speaks ? "in Urdu" : "NOT in Urdu"}; items ${items.join(" | ") || "none"}; keys ${keys.map((k) => k.text).join(" ") || "none"}${cut.length ? `; CUT ${cut.join(", ")}` : ""}`);
      if (at !== "ur/rtl") problems.push(`${label}: ${step} is ${at}, not ur/rtl`);
      if (!speaks) problems.push(`${label}: ${step} doesn't show “${words}”`);
      if (latin.length) problems.push(`${label}: ${step}'s menu has Latin words: ${latin.join(", ")}`);
      if (english.length) problems.push(`${label}: ${step} shows English: ${english.join(", ")}`);
      if (cut.length) problems.push(`${label}: ${step} cuts ${cut.join(", ")}`);
      for (const k of keys) if (k.dir !== "ltr" || k.drawn !== "ltr" || /[؀-ۿ]/.test(k.text)) problems.push(`${label}: ${step}'s key ${k.text} is not Latin, left to right`);
    };
    /** Closes what is open, a level at a time, until no menu is left (one Esc more would leave the page). */
    const escape = async () => {
      for (let i = 0; i < 3 && await page.getByRole("menu").count(); i++) {
        await page.keyboard.press("Escape");
        await page.waitForTimeout(200);
      }
      await page.waitForTimeout(200);
    };

    // The recording page's ⋮ (the fixture opens the Kick-off call): Mark as important, Labels, Move to trash… in Urdu.
    await page.locator(".el-page__titles").first().waitFor();
    await page.getByRole("button", { name: "ریکارڈنگ کے اختیارات" }).click();
    await page.getByRole("menu", { name: "ریکارڈنگ کے اختیارات" }).waitFor();
    await shot("recording-menu", "اہم نشان لگائیں");
    await escape();

    // Back to the Library: a row's right-click menu.
    await page.locator(".el-page__back").first().click();
    const rows = page.locator("button[data-row-id]");
    await rows.first().waitFor();
    await page.waitForTimeout(500);
    const box = await rows.nth(1).boundingBox();
    await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2, { button: "right" });
    await page.getByRole("menu", { name: "ریکارڈنگ کے اختیارات" }).waitFor();
    await shot("row-menu", "اہم نشان لگائیں", { pointer: true });
    // Its Labels › open.
    await page.getByRole("menuitem", { name: "لیبلز" }).hover();
    await page.waitForTimeout(300);
    await shot("row-menu-labels", "نیا لیبل…", { pointer: true });
    await escape();

    // Two picked: the selection bar and its ⋮ (More).
    // ⌘-click on both: this browser runs on a Mac, where Control-click is a right-click (the Library takes either).
    const mod = "Meta";
    await rows.nth(1).click({ modifiers: [mod] });
    await rows.nth(2).click({ modifiers: [mod] });
    const bar = page.getByRole("toolbar", { name: "منتخب ریکارڈنگز" });
    await bar.waitFor();
    await bar.getByRole("button", { name: "مزید اختیارات" }).click();
    await page.getByRole("menu", { name: "مزید اختیارات" }).waitFor();
    await shot("selection-more", "ہمیشہ رکھیں");
    await escape();
    // The selection let go (Esc on the bar).
    await page.keyboard.press("Escape");
    await page.waitForTimeout(300);

    // A label's menu in the sidebar (Invoice: the middle one, with Move up and Move down), then its Colour ›.
    const invoice = page.getByRole("button", { name: /^Invoice, / }).first();
    await invoice.click({ button: "right" });
    await page.getByRole("menuitem", { name: "رنگ" }).waitFor();
    await shot("label-menu", "لیبل حذف کریں…", { pointer: true });
    await page.getByRole("menuitem", { name: "رنگ" }).hover();
    await page.waitForTimeout(300);
    await page.getByRole("menu", { name: "رنگ" }).waitFor();
    await shot("label-colour", "اوپر لے جائیں", { pointer: true, all: true });
    await escape();

    // ⌘K (Ctrl+K on Windows): what "ریکارڈ" finds, the action list's commands in Urdu among them.
    await page.keyboard.press(platform === "macos" ? "Meta+k" : "Control+k");
    const field = page.locator(".palette-input");
    await field.waitFor();
    await field.fill("ریکارڈ");
    await page.waitForTimeout(900);
    // The commands come last (after the recordings, Ask and the settings): the list scrolled to them, their heading at
    // its top (a group taller than the list would otherwise be scrolled to its middle, the heading out of sight).
    const commands = page.getByRole("group", { name: "کمانڈز" });
    await commands.locator(".palette-group-head").evaluate((head) => head.scrollIntoView({ block: "start" }));
    await shot("palette", "کمانڈز");
    if (!(await commands.getByRole("option", { name: "نئی ریکارڈنگ" }).count()) || !(await onScreen(page, "نئی ریکارڈنگ"))) problems.push(`${label}: the palette doesn't show نئی ریکارڈنگ`);
    await escape();
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
