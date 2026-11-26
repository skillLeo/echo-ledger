// The three levels of navigation (PLAN_NAV_3_LEVELS) in the screens fixture, saved under
// docs/design/screens/desktop/nav-3-levels/: <place>-<width>-<lang>-<theme>.png for the Library at rest, the Library
// with its filters open (they push the list from 1440 px, slide over below), Projects, Settings (a group's page with its
// inner list) and Activity; at 1024 the Library's flyout open. The rail and the panel's feet are checked on screen.
// Then the window on a Mac (UX-20), 740 px tall like the app's own window, with the traffic lights drawn where the app
// puts them: mac-<place>-<width>-<lang>-<theme>.png in a normal window (the Library with its panel hidden, with it shown
// or its flyout open, and Settings), mac-inactive-library-… not in front, and mac-fullscreen-library-… in full screen.
// The run fails when the title row is not whole (the rail's top cell 72 × 52 holding only the lights, the panel's head
// and the content's row 52 tall from the top, nothing to click within 80 px of the left edge), when anything is under
// the lights or over their spot, when full screen is not strict (no lights, the top cell 8 px, the row there) or not,
// pixel for pixel, the picture kept with --baseline (scripts/baselines/titlebar-fullscreen). And Windows (its own title
// bar, no top cell): windows-library-….
//   node scripts/navLevels.mjs [1280,1440] [--platforms] [--baseline] [--report]
//   NAV_LEVELS_OUT=<folder> saves the pictures there instead of docs/design/screens/desktop/nav-3-levels
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { execFileSync } from "node:child_process";
import os from "node:os";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS as V2_FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = process.env.NAV_LEVELS_OUT ?? join(desktop, "..", "docs", "design", "screens", "desktop", "nav-3-levels");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const widths = process.argv[2] && !process.argv[2].startsWith("--") ? process.argv[2].split(",").map(Number) : [1280, 1440, 1024, 1920];
const HEIGHT = 900;
/** The app's main window as it opens (tauri.conf.json, 1100 x 740): taller than RAIL_FULL_FROM (680), so all of the rail shows. */
const MAC_HEIGHT = 740;
/** A normal Mac window, the same not in front, full screen, and Windows: in English and Urdu, light and dark. */
const WINDOWS = [
  ...["window", "inactive", "fullscreen"].flatMap((look) => ["en", "ur"].flatMap((lang) => ["light", "dark"].map((theme) => ["macos", look, lang, theme]))),
  ...["en", "ur"].map((lang) => ["windows", "windows", lang, "light"]),
];
/**
 * The full-screen pictures kept (scripts/baselines/titlebar-fullscreen), compared pixel for pixel on every run, with no
 * tolerance. Since UX-20 (decision 9) full screen keeps the title row, so they are made from the title row's own code
 * (fix/D-rail-v21) with `--baseline`; they were 2.0.12's before. A picture is only the same on the machine, browser and
 * fonts it was made with, written down next to them in made-on.json. On another machine, or after a Playwright update,
 * make them again from the code they pin first, then compare.
 */
const BASELINE_CASES = new Set(["1024-en-light", "1280-en-light", "1440-en-light", "1024-ur-dark", "1280-ur-dark", "1440-ur-dark"]);
const baselines = join(desktop, "scripts", "baselines", "titlebar-fullscreen");
const MADE_ON_FILE = join(baselines, "made-on.json");
/** `--baseline`: keep this run's full-screen pictures as the ones to compare with (run it on the code to match). */
const writeBaselines = process.argv.includes("--baseline");
/** `--platforms`: only the Mac and Windows pass. `--report`: a failed check is said and the run goes on (old code). */
const onlyPlatforms = process.argv.includes("--platforms");
const reportOnly = process.argv.includes("--report");
const check = (done, name) => (reportOnly ? done.catch((e) => console.log(`CHECK ${name}: ${e.message}`)) : done);
/** One moment for every picture (the fixture's "2 hours ago" and today's date). */
const FIXED_NOW = new Date("2026-11-23T10:00:00+05:00");

const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5232, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
await mkdir(baselines, { recursive: true });
const rest = async (page) => {
  await page.mouse.move(0, 0);
  await page.evaluate(() => (document.activeElement instanceof HTMLElement ? document.activeElement.blur() : undefined));
  await page.evaluate(() => document.fonts.ready);
  // Every list at its top: where a list was left scrolled depends on when Urdu's font came in (the fixture's Back puts
  // focus on the row it opened), so a picture would too.
  await page.evaluate(() => { for (const el of document.querySelectorAll("*")) if (el.scrollTop) el.scrollTop = 0; });
  await page.waitForTimeout(400);
};
const onScreen = async (page) => {
  const problem = await page.evaluate(() => {
    const rail = document.querySelector(".el-rail");
    const last = rail && [...rail.querySelectorAll(".el-rail__item, .el-rail__avatar")].at(-1);
    if (!last) return "no rail";
    const r = last.getBoundingClientRect();
    if (rail.scrollHeight > rail.clientHeight) return `the rail scrolls (${rail.scrollHeight} in ${rail.clientHeight})`;
    return r.bottom > innerHeight + 0.5 ? `the rail's foot ends at ${Math.round(r.bottom)} of ${innerHeight}` : "";
  });
  if (problem) throw new Error(problem);
};
/**
 * The title row in a normal Mac window (UX-20): the rail's top cell at the top left, 72 × 52, holding only the lights
 * (nothing of ours that takes a press, nothing drawn); the panel's head and the content's row as tall, from the top;
 * nothing that can be clicked within 80 px of the window's left edge in the row. Throws otherwise.
 */
const topRowClear = async (page) => {
  const problems = await page.evaluate(() => {
    const css = getComputedStyle(document.documentElement);
    const px = (name) => parseFloat(css.getPropertyValue(name));
    const height = px("--el-desktop-titlerow-height");
    const clear = px("--el-desktop-titlerow-keep-clear");
    const out = [];
    const rail = document.querySelector(".el-rail");
    const top = document.querySelector(".el-rail__top")?.getBoundingClientRect();
    if (!rail || !top) return ["no top cell in the rail"];
    // The rail's own top (on the right, right to left), as wide as the rail inside its hairline, the row's height.
    const edge = rail.getBoundingClientRect();
    if (Math.round(edge.width) !== px("--el-desktop-rail-width")) out.push(`the rail is ${Math.round(edge.width)} px wide`);
    if (top.top !== 0 || Math.round(top.width) !== rail.clientWidth || Math.round(top.height) !== height || top.left < edge.left || top.right > edge.right) out.push(`the rail's top cell is ${[top.left, top.top, top.width, top.height].map(Math.round)}`);
    const row = document.querySelector(".el-titlerow")?.getBoundingClientRect();
    if (!row || Math.round(row.top) !== 0 || Math.round(row.height) !== height) out.push(`the title row is ${row ? [row.top, row.height].map(Math.round) : "missing"}`);
    const head = document.querySelector(".el-panel:not(.el-panel--flyout) .el-panel__head")?.getBoundingClientRect();
    if (head && (Math.round(head.top) !== 0 || Math.round(head.height) !== height)) out.push(`the panel's head is ${[head.top, head.height].map(Math.round)}`);
    // Nothing to press: in the top cell, and within 80 px of the left edge in the row.
    for (let y = 2; y < height; y += 4) for (let x = 1; x < clear; x += 3) {
      const hit = document.elementFromPoint(x, y);
      const thing = hit?.closest("button, a, input, select, [role=button], [role=tab], [role=search], svg, img, h1, h2");
      if (thing) out.push(`at ${x},${y}: ${thing.tagName.toLowerCase()} "${(thing.getAttribute("aria-label") ?? thing.textContent ?? "").trim().slice(0, 30)}"`);
    }
    return [...new Set(out)].slice(0, 8);
  });
  if (problems.length) throw new Error(`title row: ${problems.join(", ")}`);
};
/**
 * The window's buttons stay theirs: at each drawn light's centre (and 4 px round it) nothing of ours takes a press and
 * no drag region starts (Tauri's drag.js rule: the first data-tauri-drag-region up the path decides, a control stops
 * it). elementFromPoint always meets some element; what it meets must be neither.
 */
const lightsClickable = async (page) => {
  const problems = await page.evaluate(() => {
    const lights = [...document.querySelectorAll("[data-traffic-lights] > span")].map((l) => l.getBoundingClientRect());
    if (lights.length !== 3) return [`${lights.length} lights drawn`];
    const CLICKABLE = new Set(["A", "BUTTON", "INPUT", "SELECT", "TEXTAREA", "LABEL", "SUMMARY"]);
    const drags = (el) => {
      for (let at = el; at && at !== document.documentElement; at = at.parentElement) {
        const attr = at.getAttribute("data-tauri-drag-region");
        if (CLICKABLE.has(at.tagName) && attr === null) return false;
        if (attr === null) continue;
        if (attr === "false") return false;
        if (attr === "deep") return true;
        return at === el;
      }
      return false;
    };
    const out = [];
    for (const [i, l] of lights.entries()) for (const [dx, dy] of [[0, 0], [-4, 0], [4, 0], [0, -4], [0, 4]]) {
      const [x, y] = [l.left + l.width / 2 + dx, l.top + l.height / 2 + dy];
      const hit = document.elementFromPoint(x, y);
      if (!hit) continue;
      const thing = hit.closest("button, a, input, select, [role=button], [role=tab], [role=search], [tabindex]:not([tabindex='-1'])");
      if (thing) out.push(`light ${i + 1}: ${thing.tagName.toLowerCase()} "${(thing.getAttribute("aria-label") ?? thing.textContent ?? "").trim().slice(0, 30)}"`);
      if (drags(hit)) out.push(`light ${i + 1}: a drag region (${hit.tagName.toLowerCase()}.${[...hit.classList].join(".")}) at ${Math.round(x)},${Math.round(y)}`);
    }
    return [...new Set(out)];
  });
  if (problems.length) throw new Error(`the window's buttons: ${problems.join(", ")}`);
};
/** Full screen, strict (UX-20): no lights, the rail's top cell 8 px, the title row there, 52 px from the top. */
const fullscreenTop = async (page) => {
  const problems = await page.evaluate(() => {
    const css = getComputedStyle(document.documentElement);
    const out = [];
    if (document.querySelector("[data-traffic-lights]")) out.push("lights drawn");
    if (document.documentElement.dataset.titlebar) out.push(`data-titlebar="${document.documentElement.dataset.titlebar}"`);
    const top = document.querySelector(".el-rail__top")?.getBoundingClientRect();
    if (!top || Math.round(top.height) !== parseFloat(css.getPropertyValue("--el-desktop-titlerow-rail-top-fullscreen"))) out.push(`the rail's top cell is ${top ? Math.round(top.height) : "missing"} px`);
    const row = document.querySelector(".el-titlerow")?.getBoundingClientRect();
    if (!row || Math.round(row.top) !== 0 || Math.round(row.height) !== parseFloat(css.getPropertyValue("--el-desktop-titlerow-height"))) out.push(`the title row is ${row ? [row.top, row.height].map(Math.round) : "missing"}`);
    return out;
  });
  if (problems.length) throw new Error(`full screen: ${problems.join(", ")}`);
};
/** Windows keeps its own title bar: no top cell in the rail, the title row at the top of the page. */
const windowsTop = async (page) => {
  const problems = await page.evaluate(() => {
    const out = [];
    const top = document.querySelector(".el-rail__top");
    if (top && getComputedStyle(top).display !== "none") out.push("a top cell in the rail");
    const row = document.querySelector(".el-titlerow")?.getBoundingClientRect();
    if (!row || Math.round(row.top) !== 0) out.push("no title row at the top");
    return out;
  });
  if (problems.length) throw new Error(`Windows: ${problems.join(", ")}`);
};
/** The machine, the browser and the page's fonts, as made-on.json says them (no names of people or computers). */
const whereMade = async (page) => {
  const sh = (cmd, args) => { try { return execFileSync(cmd, args, { encoding: "utf8" }).trim(); } catch { return "unknown"; } };
  const fonts = await page.evaluate(() => ({
    loaded: [...new Set([...document.fonts].filter((f) => f.status === "loaded").map((f) => `${f.family.replace(/"/g, "")} ${f.weight}`))].sort(),
    body: getComputedStyle(document.body).fontFamily,
  }));
  return {
    machine: { system: process.platform === "darwin" ? `macOS ${sh("sw_vers", ["-productVersion"])}` : `${os.type()} ${os.release()}`, arch: os.arch(), model: process.platform === "darwin" ? sh("sysctl", ["-n", "hw.model"]) : "unknown" },
    chromium: browser.version(),
    playwright: JSON.parse(await readFile(join(desktop, "node_modules", "playwright", "package.json"), "utf8")).version,
    fonts,
  };
};
const said = (m) => `${m.machine.model} (${m.machine.system} ${m.machine.arch}), Chromium ${m.chromium}, fonts ${m.fonts.loaded.join(", ")}`;
let madeOn = null;
/** Two pictures the same, pixel for pixel (compared in the browser, no library); throws with how many differ. */
const samePicture = async (page, now, kept, name) => {
  const differing = await page.evaluate(async ([a, b]) => {
    const pixels = async (b64) => {
      const bitmap = await createImageBitmap(await (await fetch(`data:image/png;base64,${b64}`)).blob());
      const canvas = new OffscreenCanvas(bitmap.width, bitmap.height);
      const context = canvas.getContext("2d");
      context.drawImage(bitmap, 0, 0);
      return { w: bitmap.width, h: bitmap.height, data: context.getImageData(0, 0, bitmap.width, bitmap.height).data };
    };
    const [x, y] = await Promise.all([pixels(a), pixels(b)]);
    if (x.w !== y.w || x.h !== y.h) return -1;
    let n = 0;
    for (let i = 0; i < x.data.length; i += 4) if (x.data[i] !== y.data[i] || x.data[i + 1] !== y.data[i + 1] || x.data[i + 2] !== y.data[i + 2]) n++;
    return n;
  }, [now.toString("base64"), kept.toString("base64")]);
  if (differing !== 0) {
    const here = await whereMade(page);
    let then = null;
    try { then = JSON.parse(await readFile(MADE_ON_FILE, "utf8")); } catch { /* none kept */ }
    const elsewhere = !then || said(then) !== said(here);
    throw new Error(`${name}: full screen is not the picture kept (${differing < 0 ? "another size" : `${differing} pixels differ`}). `
      + (elsewhere
        ? `Made on another machine? The pictures were made on ${then ? said(then) : "(no made-on.json)"}; this is ${said(here)}. Make them again with --baseline from the code they pin, then compare.`
        : "Same machine, browser and fonts as the pictures: full screen has changed."));
  }
  console.log(`${name}: the same as the picture kept`);
};
/** One first frame (see the pass at the end): the picture as soon as the shell is drawn, then what follows. */
async function firstPaint(width, lang, theme, look, { kept, answerMs }) {
  const page = await browser.newPage({ viewport: { width, height: MAC_HEIGHT }, colorScheme: theme, reducedMotion: "reduce" });
  await page.clock.setFixedTime(FIXED_NOW);
  if (kept) await page.addInitScript((value) => sessionStorage.setItem("echoledger-titlebar", value), kept);
  await page.addInitScript({ path: join(desktop, "src", "lib", "titlebarStart.js") });
  // When the shell is first drawn and when the window's buttons are said gone (full screen: the top cell goes to 8 px).
  await page.addInitScript(() => {
    const seen = new MutationObserver(() => {
      if (!window.__shellAt && document.querySelector(".el-shell")) window.__shellAt = performance.now();
      if (window.__shellAt && !window.__stripGoneAt && !document.documentElement.dataset.titlebar) window.__stripGoneAt = performance.now();
    });
    seen.observe(document, { subtree: true, childList: true, attributes: true, attributeFilter: ["data-titlebar"] });
  });
  const fullscreen = look !== "window";
  await page.goto(`${base}scripts/screens/index.html?screen=library&theme=${theme}&uiflags=${V2_FLAGS}&platform=macos&start=${answerMs}${fullscreen ? "&fullscreen=1" : "&lights=1"}${lang === "ur" ? "&lang=ur" : ""}`);
  await page.locator(".el-shell").waitFor();
  const topCell = () => page.evaluate(() => {
    const el = document.querySelector(".el-rail__top");
    return { topCell: el ? Math.round(el.getBoundingClientRect().height) : null, shellTop: Math.round(document.querySelector(".el-shell").getBoundingClientRect().top) };
  });
  const first = await topCell();
  const file = join(out, `mac-firstpaint-${look}-${width}-${lang}-${theme}.png`);
  await page.screenshot({ path: file });
  console.log(file);
  const problem = (what, want) => `first frame (${look}, ${width}-${lang}-${theme}): ${what} ${JSON.stringify(first)}, wanted ${want}`;
  const fail = (message) => check(Promise.reject(new Error(message)), `first frame ${width}-${lang}-${theme}`);
  if (look === "window") {
    if (first.topCell !== 52 || first.shellTop !== 0) await fail(problem("not the title row's top cell", "a 52 px top cell, the shell at the top"));
    await check(clearOfLights(page), `first frame ${width}-${lang}-${theme}`);
    await check(lightsClickable(page), `first frame ${width}-${lang}-${theme}`);
    await check(topRowClear(page), `first frame ${width}-${lang}-${theme}`);
  } else if (look === "fullscreen-reload") {
    if (first.topCell !== 8 || first.shellTop !== 0) await fail(problem("not full screen's top cell", "8 px"));
  } else {
    await check(page.waitForFunction(() => window.__stripGoneAt, null, { timeout: 5000 }), "first frame full screen");
    const [shellAt, goneAt] = await page.evaluate(() => [window.__shellAt, window.__stripGoneAt]);
    console.log(`first frame (a window opened full screen): the shell drew at ${Math.round(shellAt)} ms, the top cell went to 8 px at ${Math.round(goneAt)} ms (the answer came ${answerMs} ms after the page began)`);
  }
  await page.close();
}
/** What the page shows under the drawn traffic lights (with a few pixels round them): nothing, or it throws. */
const clearOfLights = async (page) => {
  const under = await page.evaluate(() => {
    const lights = document.querySelector("[data-traffic-lights]")?.getBoundingClientRect();
    if (!lights) return ["no traffic lights drawn"];
    const margin = 4;
    const found = [];
    // What the pointer meets there (the drawn lights let it through): never a control, an icon, a picture or words.
    for (let y = lights.top - margin; y <= lights.bottom + margin; y += 2) for (let x = lights.left - margin; x <= lights.right + margin; x += 2) {
      const hit = document.elementFromPoint(x, y);
      if (!hit) continue;
      const thing = hit.closest("button, a, input, select, [role=button], [role=tab], svg, img, h1, h2, h3");
      const words = [...hit.childNodes].some((n) => n.nodeType === Node.TEXT_NODE && n.textContent.trim());
      if (thing || words) found.push(`${(thing ?? hit).tagName.toLowerCase()} "${((thing ?? hit).getAttribute("aria-label") ?? (thing ?? hit).textContent ?? "").trim().slice(0, 40)}"`);
    }
    return [...new Set(found)];
  });
  if (under.length) throw new Error(`under the traffic lights: ${under.join(", ")}`);
};
try {
  for (const width of onlyPlatforms ? [] : widths) for (const lang of ["en", "ur"]) for (const theme of ["light", "dark"]) {
    const page = await browser.newPage({ viewport: { width, height: HEIGHT }, colorScheme: theme, reducedMotion: "reduce" });
    const shoot = async (name) => {
      await rest(page);
      await onScreen(page);
      const file = join(out, `${name}-${width}-${lang}-${theme}.png`);
      await page.screenshot({ path: file });
      console.log(file);
    };
    await page.goto(`${base}scripts/screens/index.html?screen=library&theme=${theme}&uiflags=${V2_FLAGS}${lang === "ur" ? "&lang=ur" : ""}`);
    // The fixture opens a recording: its Back shows the list.
    await page.getByRole("button", { name: "Library", exact: true }).last().click();
    await page.locator("button[data-row-id]").first().waitFor();
    const rail = page.locator('nav[data-nav="main"]');
    if (width < 1100) {
      await shoot("library");
      await rail.getByRole("button", { name: /^Library/ }).click();
      await page.getByTestId("nav-panel").waitFor();
      await shoot("library-flyout");
      await page.keyboard.press("Escape");
    } else {
      await shoot("library");
      await page.getByRole("button", { name: /^Filters/ }).click();
      await page.getByTestId("filter-panel").waitFor();
      await page.getByTestId("filter-panel").getByRole("checkbox", { name: /^WhatsApp/ }).first().check();
      await page.waitForTimeout(300);
      await shoot("library-filters");
      await page.getByTestId("filter-panel").getByRole("button", { name: "Close filters" }).click();
    }
    await rail.getByRole("button", { name: /^Projects/ }).click();
    await page.waitForTimeout(300);
    await shoot("projects");
    await rail.getByRole("button", { name: /^Settings/ }).click();
    await page.waitForTimeout(300);
    if (width >= 1100) {
      await page.getByTestId("nav-panel").getByRole("button", { name: "Recording" }).click();
      await page.waitForTimeout(400);
    }
    await shoot("settings");
    await rail.getByRole("button", { name: /^Activity/ }).click();
    await page.waitForTimeout(300);
    await shoot("activity");
    await page.close();
  }
  // On a Mac and on Windows (D-214). The window is the app's own height, where the rail is short; the clock is fixed so
  // the pictures can be compared.
  for (const width of widths) for (const [platform, look, lang, theme] of WINDOWS) {
    const page = await browser.newPage({ viewport: { width, height: MAC_HEIGHT }, colorScheme: theme, reducedMotion: "reduce" });
    await page.clock.setFixedTime(FIXED_NOW);
    const mac = platform === "macos";
    const shoot = async (name) => {
      await rest(page);
      await onScreen(page);
      if (mac && look !== "fullscreen") {
        await check(clearOfLights(page), name);
        await check(lightsClickable(page), name);
        await check(topRowClear(page), name);
      }
      if (mac && look === "fullscreen") await check(fullscreenTop(page), name);
      if (!mac) await check(windowsTop(page), name);
      const stem = `${mac ? `mac${look === "window" ? "" : `-${look}`}` : "windows"}-${name}-${width}-${lang}-${theme}`;
      const picture = await page.screenshot({ path: join(out, `${stem}.png`) });
      console.log(join(out, `${stem}.png`));
      // Full screen: the same picture, pixel for pixel, as the one kept (--baseline).
      if (look === "fullscreen" && name === "library" && BASELINE_CASES.has(`${width}-${lang}-${theme}`)) {
        const kept = join(baselines, `${stem}.png`);
        if (writeBaselines) {
          await writeFile(kept, picture);
          madeOn ??= { made: new Date().toISOString(), ...(await whereMade(page)), cases: [...BASELINE_CASES] };
        }
        else await check(samePicture(page, picture, await readFile(kept), stem), name);
      }
    };
    const extra = { window: "&lights=1", inactive: "&lights=1&inactive=1", fullscreen: "&fullscreen=1", windows: "" }[look];
    await page.goto(`${base}scripts/screens/index.html?screen=library&theme=${theme}&uiflags=${V2_FLAGS}&platform=${platform}${extra}${lang === "ur" ? "&lang=ur" : ""}`);
    await page.getByRole("button", { name: "Library", exact: true }).last().click();
    await page.locator("button[data-row-id]").first().waitFor();
    const rail = page.locator('nav[data-nav="main"]');
    // The owner's window: the Library with its panel hidden (below 1100 px it is a flyout, closed).
    const hide = page.getByTestId("nav-panel").getByRole("button", { name: "Hide panel" });
    if (await hide.count()) await hide.click();
    await shoot("library");
    // Full screen: the title row's ⊟, pressed like a person would (it fails when covered).
    if (look === "fullscreen" && width >= 1100) {
      const show = page.getByTestId("title-row").getByRole("button", { name: /^Show the Library panel/ });
      await show.click({ timeout: 5000 });
      await page.getByTestId("nav-panel").waitFor();
      await shoot("library-panel");
    }
    if (look === "window") {
      if (width < 1100) {
        await rail.getByRole("button", { name: /^Library/ }).click();
        await page.getByTestId("nav-panel").waitFor();
        await shoot("library-flyout");
        await page.keyboard.press("Escape");
      } else {
        // The title row's ⊟, pressed like a person would (it fails when covered).
        const show = page.getByTestId("title-row").getByRole("button", { name: /^Show the Library panel/ });
        await show.click({ timeout: 5000 });
        await page.getByTestId("nav-panel").waitFor();
        await shoot("library-panel");
      }
      await rail.getByRole("button", { name: /^Settings/ }).click();
      await page.waitForTimeout(300);
      await shoot("settings");
    }
    await page.close();
  }
  if (madeOn) await writeFile(MADE_ON_FILE, `${JSON.stringify(madeOn, null, 2)}\n`);
  // The first frame of a Mac window as the app starts (D-214, UX-20): the core's start script runs before the page, as
  // it does in the app (page.addInitScript is WebKit's document-start user script), and the window says whether it is
  // full screen only much later. A new window: the 52 px top cell from the first frame, nothing under the buttons. A page
  // reloaded in full screen: the 8 px top cell from the first frame. (A window never opens full screen; if one did, the
  // top cell would be 52 px until the first answer, before the core shows the window: the third case says how long.)
  for (const width of widths) for (const [lang, theme] of [["en", "light"], ["ur", "dark"]]) {
    await firstPaint(width, lang, theme, "window", { kept: null, answerMs: 60_000 });
  }
  await firstPaint(1280, "en", "light", "fullscreen-reload", { kept: "none", answerMs: 60_000 });
  await firstPaint(1280, "en", "light", "fullscreen-new", { kept: null, answerMs: 300 });
} finally {
  await browser.close();
  await server.close();
}
