// D-038: a waiting recording's language chip says the short name ("Auto"), never "Auto (English / Urdu / Ara…", and
// keyboard focus draws its ring on the chip (the native select lies transparent over it). In the screens fixture
// (classic Library, the waiting recordings), saved under docs/design/screens/desktop/d-038/:
//   <prefix>-waiting-language-chip-<theme>.png        the cards
//   <prefix>-waiting-language-chip-focus-<theme>.png  after Tab reaches the first chip
//   <prefix>-waiting-language-chip-rtl-<theme>.png    in Urdu, right to left (the arrow at the chip's end, on the left)
// The run fails, after the change, when a chip's words are cut or the focused chip shows no ring.
//   node scripts/languageChip.mjs before
//   node scripts/languageChip.mjs after
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = process.env.CHIP_OUT ?? join(desktop, "..", "docs", "design", "screens", "desktop", "d-038");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const prefix = process.argv[2] ?? "after";
const server = await createServer({ root: desktop, logLevel: "silent", server: { port: 5248, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];
for (const [theme, rtl] of [["light", false], ["dark", false], ["light", true], ["dark", true]]) {
  const page = await browser.newPage({ viewport: { width: 1200, height: 800 }, colorScheme: theme, reducedMotion: "reduce" });
  await page.goto(`${base}scripts/screens/index.html?screen=library&theme=${theme}${rtl ? "&lang=ur" : ""}`);
  const name = rtl ? `rtl-${theme}` : theme;
  // A busy machine serves the fixture slowly; the wait is for it to load, not a measure of anything. The waiting
  // recordings sit behind the slim line's Review.
  const review = page.locator(".queue-slim").getByRole("button").last();
  await review.waitFor({ timeout: 60_000 });
  await review.click();
  const card = page.locator(".queue-actions").filter({ has: page.locator(".queue-language, select.queue-language") }).first();
  await card.waitFor({ timeout: 60_000 });
  const select = card.locator("select").first();
  const chip = await select.evaluate((el) => {
    const c = el.closest(".queue-language") ?? el;
    const label = c.querySelector(".queue-language-label");
    if (label) {
      // The arrow is drawn at the chip's end: on the right left to right, on the left right to left (clear of the words).
      const box = c.getBoundingClientRect();
      const words = label.getBoundingClientRect();
      const rtl = getComputedStyle(c).direction === "rtl";
      const arrowClear = rtl ? words.left - box.left >= 20 : box.right - words.right >= 20;
      return { words: (label.textContent ?? "").trim(), cut: label.scrollWidth > label.clientWidth + 1 || !arrowClear };
    }
    // Before: the select draws its chosen option itself, and its scrollWidth doesn't say when that text is cut, so the
    // words are measured against the room between its paddings.
    const s = getComputedStyle(el);
    const ctx = document.createElement("canvas").getContext("2d");
    ctx.font = `${s.fontWeight} ${s.fontSize} ${s.fontFamily}`;
    const words = el.selectedOptions[0]?.text ?? "";
    const room = el.clientWidth - parseFloat(s.paddingLeft) - parseFloat(s.paddingRight);
    return { words, cut: ctx.measureText(words).width > room + 1 };
  });
  await card.screenshot({ path: join(out, `${prefix}-waiting-language-chip-${name}.png`) });
  // Keyboard focus: Tab from the element just before the select.
  await select.evaluate((el) => {
    const all = [...document.querySelectorAll("button, select, input, a[href], [tabindex]")].filter((e) => !e.disabled);
    all[all.indexOf(el) - 1]?.focus();
  });
  await page.keyboard.press("Tab");
  const ring = await select.evaluate((el) => {
    const c = el.closest(".queue-language") ?? el;
    const s = getComputedStyle(c);
    return { focused: document.activeElement === el, outline: `${s.outlineStyle} ${s.outlineWidth}` };
  });
  if (!rtl) await card.screenshot({ path: join(out, `${prefix}-waiting-language-chip-focus-${theme}.png`) });
  console.log(name, JSON.stringify({ ...chip, ...ring }));
  if (chip.cut || !ring.focused || ring.outline.startsWith("none")) problems.push(name);
  await page.close();
}
await browser.close();
await server.close();
if (problems.length) {
  console.log(`language chip cut or without its focus ring: ${problems.join(", ")}`);
  process.exitCode = 1;
}
