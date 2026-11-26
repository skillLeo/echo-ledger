// The compact rail (Settings › Appearance › Compact rail (icons only), View › Theme › Compact rail) in the screens
// fixture, saved under docs/design/screens/desktop/compact-rail/:
//   mac-<lang>-<theme>.png        a Mac window 1280 × 740, English and Urdu, light and dark, the rail compact
//   mac-short-en-light.png        the least window height (560): all of the compact rail, Help and Setup in it
//   windows-en-light.png          Windows (its own title bar, no top cell)
//   labels-mac-en-light.png       the same Mac window with the names (the default), to compare
// The run fails when a compact item is not 44 px, a name is drawn, the rail is not 72 px or clips or scrolls, Help or
// Setup is missing at 560 px, the window's buttons' spot has anything of ours under it, or the default rail lost a name.
//   node scripts/compactRail.mjs
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS as V2_FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = process.env.COMPACT_RAIL_OUT ?? join(desktop, "..", "docs", "design", "screens", "desktop", "compact-rail");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const WIDTH = 1280;

const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5239, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });

const open = async ({ platform = "macos", lang = "en", theme = "light", height = 740, compact = true }) => {
  const page = await browser.newPage({ viewport: { width: WIDTH, height }, colorScheme: theme, reducedMotion: "reduce" });
  // A busy machine serves the fixture slowly; the waits are for it to load, not a measure of anything.
  page.setDefaultTimeout(120_000);
  await page.addInitScript((on) => {
    try {
      localStorage.setItem("echoledger-preferences", JSON.stringify({ compactRail: on }));
    } catch {
      // A page without storage: the default rail.
    }
  }, compact);
  const lights = platform === "macos" ? "&lights=1" : "";
  await page.goto(`${base}scripts/screens/index.html?screen=library&theme=${theme}&uiflags=${V2_FLAGS}&platform=${platform}${lights}${lang === "ur" ? "&lang=ur" : ""}`);
  await page.locator('nav[data-nav="main"]').getByRole("button", { name: "Library", exact: true }).click();
  await page.locator("button[data-row-id]").first().waitFor();
  // Below 1100 px the panel is a flyout: closed, so the rail shows as the person sees it at rest.
  if (await page.getByTestId("nav-panel").count() && WIDTH < 1100) await page.keyboard.press("Escape");
  await page.mouse.move(WIDTH / 2, height / 2);
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(400);
  return page;
};

/** The rail as drawn. */
const measure = (page) => page.evaluate(() => {
  const rail = document.querySelector(".el-rail");
  const box = rail.getBoundingClientRect();
  const items = [...rail.querySelectorAll(".el-rail__places .el-rail__item, .el-rail__foot .el-rail__item")];
  const drawn = [...rail.querySelectorAll(".el-rail__label")].filter((l) => l.getClientRects().length > 0).map((l) => l.textContent.trim());
  const names = items.map((i) => i.getAttribute("aria-label"));
  const lights = rail.querySelector(".el-rail__lights")?.getBoundingClientRect();
  // Anything of ours (but the top cell and the lights' own spot) under the window's buttons.
  const under = lights && lights.width > 0
    ? [...rail.querySelectorAll("button, a, [role]")].filter((el) => {
      const r = el.getBoundingClientRect();
      return r.width > 0 && r.left < lights.right && r.right > lights.left && r.top < lights.bottom && r.bottom > lights.top;
    }).map((el) => el.getAttribute("aria-label") ?? el.className)
    : [];
  return {
    compact: rail.classList.contains("el-rail--compact"),
    width: Math.round(box.width),
    clips: rail.scrollHeight > rail.clientHeight + 0.5,
    heights: [...new Set(items.map((i) => Math.round(i.getBoundingClientRect().height)))],
    drawn,
    names,
    under,
  };
});

const problems = [];
const say = (name, m) => console.log(`${name}: ${m.compact ? "compact" : "with names"}, ${m.width} px wide, items ${m.heights.join("/")} px, ${m.clips ? "CLIPS" : "nothing clipped"}, names drawn: ${m.drawn.length ? m.drawn.join(", ") : "none"}; named: ${m.names.join(", ")}${m.under.length ? `; UNDER THE LIGHTS: ${m.under.join(", ")}` : ""}`);
const compactOk = (name, m) => {
  if (!m.compact) problems.push(`${name}: not compact`);
  if (m.width !== 72) problems.push(`${name}: ${m.width} px wide`);
  if (m.heights.some((h) => h !== 44)) problems.push(`${name}: items ${m.heights.join("/")} px`);
  if (m.drawn.length) problems.push(`${name}: names drawn (${m.drawn.join(", ")})`);
  if (m.clips) problems.push(`${name}: the rail clips`);
  if (m.under.length) problems.push(`${name}: under the window's buttons: ${m.under.join(", ")}`);
  if (m.names.some((n) => !n)) problems.push(`${name}: an item without a name`);
};
try {
  for (const lang of ["en", "ur"]) for (const theme of ["light", "dark"]) {
    const name = `mac-${lang}-${theme}`;
    const page = await open({ lang, theme });
    await page.screenshot({ path: join(out, `${name}.png`) });
    const m = await measure(page);
    say(name, m);
    compactOk(name, m);
    await page.close();
  }
  {
    const name = "mac-short-en-light";
    const page = await open({ height: 560 });
    await page.screenshot({ path: join(out, `${name}.png`) });
    const m = await measure(page);
    say(name, m);
    compactOk(name, m);
    for (const item of ["Help", "Setup"]) if (!m.names.some((n) => n?.startsWith(item))) problems.push(`${name}: ${item} is not in the rail`);
    await page.close();
  }
  {
    const name = "windows-en-light";
    const page = await open({ platform: "windows" });
    await page.screenshot({ path: join(out, `${name}.png`) });
    const m = await measure(page);
    say(name, m);
    compactOk(name, m);
    await page.close();
  }
  {
    const name = "labels-mac-en-light";
    const page = await open({ compact: false });
    await page.screenshot({ path: join(out, `${name}.png`) });
    const m = await measure(page);
    say(name, m);
    // 56 px items (the avatar's 58: its 40 px click area, 4 px and the name), every name drawn.
    if (m.compact || m.heights.some((h) => h < 56) || m.drawn.length < m.names.length) problems.push(`${name}: the default rail is not the one with names`);
    await page.close();
  }
} finally {
  await browser.close();
  await server.close();
}
if (problems.length) {
  console.error(problems.join("\n"));
  process.exit(1);
}
