// FIX-08: the least window. The main window can't be made smaller than tauri.conf.json's minWidth × minHeight (720 ×
// 560, decisions 4 and 11). This walks the new design's main pages in that window, and in 720 × 520 (the height the
// decisions name for when the new rail fits), at 100% and 200% zoom (the page that many CSS pixels smaller, drawn that
// much bigger, as UX-07's zoom does), on a Mac (its window buttons drawn) and on Windows, and measures:
//   - nothing wider than the window (no sideways scroll);
//   - the rail: every item whole and inside the window, Settings and Account shown, places that don't fit whole in ⋯ More
//     or the avatar's menu (none cut), and whether any place had to leave the rail;
//   - the title row on screen and its title never cut.
// Saved under docs/design/screens/desktop/fix-08/<width>x<height>-<zoom>-<platform>-<page>.png. The run fails when
// something is cut or off the window at 720 × 560; at 720 × 520 it reports what would be lost (the proposal).
//   node scripts/minSize.mjs
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS as V2_FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = process.env.MIN_SIZE_OUT ?? join(desktop, "..", "docs", "design", "screens", "desktop", "fix-08");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const FLAGS = `${V2_FLAGS},v21_zoom`;
const PAGES = [
  ["library", "library", "Library"],
  ["recording", "library", "Library"],
  ["projects", "projects", "Projects"],
  ["settings", "settings", "Settings"],
  ["activity", "library", "Activity"],
];

const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5257, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });

async function open({ width, height, zoom, platform, screen, place, recording }) {
  const scale = zoom / 100;
  const page = await browser.newPage({ viewport: { width: Math.round(width / scale), height: Math.round(height / scale) }, deviceScaleFactor: scale, reducedMotion: "reduce" });
  page.setDefaultTimeout(120_000);
  await page.addInitScript((kept) => { try { localStorage.setItem("echoledger-zoom", String(kept)); } catch { /* none */ } }, zoom);
  const where = platform === "windows" ? "&platform=windows" : "&platform=macos&lights=1";
  await page.goto(`${base}scripts/screens/index.html?screen=${screen}&uiflags=${FLAGS}${where}&zoom=${zoom}`);
  const rail = page.locator('nav[data-nav="main"]');
  await rail.waitFor();
  await page.waitForTimeout(800);
  // The place from the rail, or from ⋯ More or the avatar's menu when it isn't in the rail.
  const item = rail.getByRole("button", { name: new RegExp(`^${place}`) });
  if (await item.count()) await item.first().click();
  else {
    const more = page.getByTestId("rail-more").getByRole("button");
    await ((await more.count()) ? more : rail.getByRole("button", { name: /^Account/ })).click();
    await page.getByRole("menu").locator('[role^="menuitem"]', { hasText: place }).first().click();
  }
  await page.waitForTimeout(1000);
  if (recording) {
    await page.locator("button[data-row-id]").first().click();
    await page.waitForTimeout(1200);
  }
  if (await page.getByTestId("nav-panel").isVisible().catch(() => false) && await page.evaluate(() => innerWidth < 1100)) await page.keyboard.press("Escape");
  await page.mouse.move(0, 0);
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(300);
  return page;
}

const measure = (page) => page.evaluate(() => {
  const doc = document.scrollingElement;
  const rail = document.querySelector(".el-rail");
  const items = [...rail.querySelectorAll(".el-rail__item")];
  const box = rail.querySelector(".el-rail__places")?.getBoundingClientRect();
  const partly = items.filter((el) => {
    const r = el.getBoundingClientRect();
    const inPlaces = !!el.closest(".el-rail__places") && box;
    const top = inPlaces ? Math.max(box.top, 0) : 0;
    const bottom = inPlaces ? Math.min(box.bottom, innerHeight) : innerHeight;
    return r.top < top - 0.5 || r.bottom > bottom + 0.5;
  }).map((el) => el.getAttribute("aria-label") ?? el.textContent.trim());
  const names = items.map((el) => el.getAttribute("aria-label") ?? "");
  const inRail = [...rail.querySelectorAll(".el-rail__places [data-place]")].map((el) => el.getAttribute("data-place"));
  const row = document.querySelector('[data-testid="title-row"]');
  const name = row?.querySelector(".el-titlerow__name");
  const rowBox = row?.getBoundingClientRect();
  return {
    css: `${innerWidth}×${innerHeight}`,
    sideways: doc.scrollWidth > doc.clientWidth + 1,
    partly,
    whole: names.includes("Settings") && names.some((n) => n.startsWith("Account")) && items.at(-1).getBoundingClientRect().bottom <= innerHeight + 1,
    places: inRail.length,
    more: !!rail.querySelector(".el-rail__more"),
    setupHelp: names.filter((n) => /^(Setup|Help)/.test(n)).length,
    row: !!rowBox && rowBox.left >= -1 && rowBox.right <= innerWidth + 1,
    cut: name ? name.scrollWidth > name.clientWidth + 0.5 || name.scrollHeight > name.clientHeight + 0.5 : false,
  };
});

const problems = [];
const lost = [];
try {
  for (const height of [560, 520]) for (const zoom of [100, 200]) for (const platform of ["macos", "windows"]) {
    for (const [name, screen, place] of PAGES) {
      // Every page at 100% on a Mac; the rest (200%, Windows) for the Library and Settings.
      if ((zoom !== 100 || platform !== "macos") && !["library", "settings"].includes(name)) continue;
      const label = `720x${height}-${zoom}-${platform}-${name}`;
      const page = await open({ width: 720, height, zoom, platform, screen, place, recording: name === "recording" });
      await page.screenshot({ path: join(out, `${label}.png`) });
      const m = await measure(page);
      console.log(`${label}: page ${m.css}; ${m.sideways ? "SIDEWAYS SCROLL" : "fits"}; rail ${m.whole ? "to Account" : "CUT"}, ${m.places} of 5 places in it${m.more ? ", ⋯ More" : ""}, Setup/Help ${m.setupHelp ? "in it" : "in the avatar's menu"}${m.partly.length ? `; PARTLY SHOWN: ${m.partly.join(", ")}` : ""}; title row ${m.row ? "on screen" : "OFF"}${m.cut ? ", title CUT" : ""}`);
      const bad = [m.sideways && "wider than the window", !m.whole && "the rail is cut before Account", m.partly.length && `partly shown: ${m.partly.join(", ")}`, !m.row && "the title row is off the window", m.cut && "the title is cut"].filter(Boolean);
      if (bad.length) (height === 560 ? problems : lost).push(`${label}: ${bad.join("; ")}`);
      if (height === 520 && zoom === 100 && m.places < 5) lost.push(`${label}: ${5 - m.places} place(s) left the rail for ${m.more ? "⋯ More" : "the avatar's menu"}`);
      await page.close();
    }
  }
} finally {
  await browser.close();
  await server.close();
}
console.log(lost.length ? `\nAt 720 × 520:\n${lost.join("\n")}` : "\nAt 720 × 520: nothing cut, every place in the rail at 100%.");
if (problems.length) {
  console.error(`\nAt 720 × 560:\n${problems.join("\n")}`);
  process.exit(1);
}
