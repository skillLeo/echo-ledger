// D-260: a tooltip's words fit inside it, Nastaliq too. The rail's tooltips in the screens fixture (every v2 flag,
// 1280 × 800, light and dark), in English and in Urdu ("&applang=ur" with v21_urdu): for each, the glyphs' ink (the
// words drawn in its own box and font, the pixels read back; in Urdu the rail's Urdu words too) must lie inside the
// tooltip's box. Saves docs/design/screens/desktop/tip-nastaliq/<lang>-<theme>.png; fails when ink is cut.
//   node scripts/tipNastaliq.mjs
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "tip-nastaliq");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5274, strictPort: false } });
await server.listen();
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];

/** The Urdu words the rail says (some of the tallest Nastaliq it draws), measured too while its words may be English. */
const URDU = ["ریکارڈ کرنے کے مزید طریقے", "پروجیکٹس Ctrl+2", "اکاؤنٹ، تھیم اور سیٹنگز", "لائبریری Ctrl+1", "سرگرمی Ctrl+5، کچھ ناکام ہوا",
  "نئی ریکارڈنگ Ctrl+N", "سیٹ اپ", "مدد", "گوگل میٹ", "ٹرانسکرپٹ بن رہا ہے…", "سیٹنگز Ctrl+6"];

/**
 * Where a tooltip's ink reaches: its words drawn in magenta on no background (its box, padding and font as they are),
 * the drawn pixels read back, and the ink's top and bottom compared with the box's (px; negative is outside it, cut).
 */
async function ink(page, text) {
  const tip = page.locator('[role="tooltip"].el-tip');
  const box = await tip.evaluate((el, text) => {
    el.dataset.was = el.textContent;
    el.textContent = text;
    Object.assign(el.style, { color: "#ff00ff", background: "transparent", boxShadow: "none" });
    const b = el.getBoundingClientRect();
    const s = getComputedStyle(el);
    return { x: b.left, y: b.top, width: b.width, height: b.height, font: `${s.fontSize}/${s.lineHeight}` };
  }, text);
  await page.evaluate(() => document.fonts.ready);
  const pad = 24;
  const clip = { x: Math.max(0, box.x - pad), y: Math.max(0, box.y - pad), width: box.width + 2 * pad, height: box.height + 2 * pad };
  const png = (await page.screenshot({ clip })).toString("base64");
  const rows = await page.evaluate(async (png) => {
    const img = new Image();
    img.src = `data:image/png;base64,${png}`;
    await img.decode();
    const c = document.createElement("canvas");
    c.width = img.width;
    c.height = img.height;
    const ctx = c.getContext("2d");
    ctx.drawImage(img, 0, 0);
    const d = ctx.getImageData(0, 0, c.width, c.height).data;
    let top = null, bottom = null;
    for (let y = 0; y < c.height; y++) for (let x = 0; x < c.width; x++) {
      const i = (y * c.width + x) * 4;
      if (d[i] > 150 && d[i + 2] > 150 && d[i + 1] < 110 && d[i] - d[i + 1] > 80) { top ??= y; bottom = y; }
    }
    return { top, bottom };
  }, png);
  await tip.evaluate((el) => {
    el.textContent = el.dataset.was;
    el.style.color = el.style.background = el.style.boxShadow = "";
  });
  const top = clip.y + rows.top;
  const bottom = clip.y + rows.bottom + 1;
  return { text, font: box.font, height: Math.round(box.height), above: Math.round((top - box.y) * 10) / 10, below: Math.round((box.y + box.height - bottom) * 10) / 10 };
}

try {
  for (const lang of ["en", "ur"]) {
    for (const theme of ["light", "dark"]) {
      const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, colorScheme: theme, reducedMotion: "reduce" });
      page.setDefaultTimeout(120_000);
      await page.addInitScript(() => Object.defineProperty(navigator, "platform", { value: "Win32", configurable: true }));
      const urdu = lang === "ur" ? ",v21_urdu&applang=ur" : "";
      await page.goto(`${server.resolvedUrls.local[0]}scripts/screens/index.html?screen=library&platform=windows&theme=${theme}&uiflags=${FLAGS}${urdu}`);
      const rail = page.locator("nav.el-rail");
      await rail.waitFor();
      if (lang === "ur") await page.waitForFunction(() => document.documentElement.dir === "rtl", null, { timeout: 30_000 });
      await page.waitForTimeout(800);
      const buttons = rail.getByRole("button");
      const count = await buttons.count();
      let shot = false;
      for (let i = 0; i < count; i++) {
        const b = buttons.nth(i);
        if (!(await b.isVisible())) continue;
        await page.mouse.move(0, 0);
        await page.waitForTimeout(150);
        await b.hover();
        const tip = await page.locator('[role="tooltip"].el-tip').waitFor({ timeout: 2500 }).then(() => true).catch(() => false);
        if (!tip) continue;
        await page.waitForTimeout(150);
        const own = await page.locator('[role="tooltip"].el-tip').textContent();
        for (const text of [own, ...(lang === "ur" && i === 3 ? URDU : [])]) {
          const m = await ink(page, text);
          console.log(`${lang}-${theme}: “${m.text}” ${m.font}, ${m.height} px tall; ink ${m.above} px under its top, ${m.below} px over its bottom`);
          if (m.above < 0 || m.below < 0) problems.push(`${lang}-${theme}: “${m.text}” is cut (${m.above} / ${m.below})`);
        }
        if (!shot && i === 3) {
          // In Urdu the tooltip shows the rail's Urdu words for the picture (on this branch the rail may still be English).
          if (lang === "ur") await page.locator('[role="tooltip"].el-tip').evaluate((el, t) => { el.textContent = t; }, URDU[1]);
          await page.screenshot({ path: join(out, `${lang}-${theme}.png`) });
          shot = true;
        }
      }
      await page.close();
    }
  }
} finally {
  await browser.close();
  await server.close();
}
if (problems.length) {
  console.error(`\n${problems.join("\n")}`);
  process.exit(1);
}
