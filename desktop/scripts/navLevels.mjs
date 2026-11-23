// The three levels of navigation (PLAN_NAV_3_LEVELS) in the screens fixture, saved under
// docs/design/screens/desktop/nav-3-levels/: <place>-<width>-<lang>-<theme>.png for the Library at rest, the Library
// with its filters open (they push the list from 1440 px, slide over below), Projects, Settings (a group's page with its
// inner list) and Activity; at 1024 the Library's flyout open. The rail and the panel's feet are checked on screen.
// Then the window on a Mac (D-214), 740 px tall like the app's own window (the rail is short there), with the traffic
// lights drawn where the app puts them: mac-<place>-<width>-<lang>-<theme>.png in a normal window (the Library with its
// panel hidden, with it shown or its flyout open, and Settings), mac-inactive-library-… not in front, and
// mac-fullscreen-library-… in full screen. The run fails when the title-bar strip is not empty and whole, when anything
// is under the lights, or when full screen is not, pixel for pixel, the picture kept from 2.0.12
// (scripts/baselines/titlebar-fullscreen). And Windows (its own title bar, no strip): windows-library-….
//   node scripts/navLevels.mjs [1280,1440] [--platforms] [--baseline] [--report]
//   NAV_LEVELS_OUT=<folder> saves the pictures there instead of docs/design/screens/desktop/nav-3-levels
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS as V2_FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = process.env.NAV_LEVELS_OUT ?? join(desktop, "..", "docs", "design", "screens", "desktop", "nav-3-levels");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const widths = process.argv[2] && !process.argv[2].startsWith("--") ? process.argv[2].split(",").map(Number) : [1280, 1440, 1024, 1920];
const HEIGHT = 900;
/** The app's main window as it opens (tauri.conf.json, 1100 x 740): shorter than SHORT_RAIL_BELOW, so the rail is short. */
const MAC_HEIGHT = 740;
/** A normal Mac window, the same not in front, full screen, and Windows: in English and Urdu, light and dark. */
const WINDOWS = [
  ...["window", "inactive", "fullscreen"].flatMap((look) => ["en", "ur"].flatMap((lang) => ["light", "dark"].map((theme) => ["macos", look, lang, theme]))),
  ...["en", "ur"].map((lang) => ["windows", "windows", lang, "light"]),
];
/** The full-screen pictures kept from 2.0.12 (scripts/baselines/titlebar-fullscreen), compared on every run. */
const BASELINE_CASES = new Set(["1024-en-light", "1280-en-light", "1440-en-light", "1024-ur-dark", "1280-ur-dark", "1440-ur-dark"]);
const baselines = join(desktop, "scripts", "baselines", "titlebar-fullscreen");
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
  await page.waitForTimeout(400);
};
const onScreen = async (page) => {
  const problem = await page.evaluate(() => {
    const rail = document.querySelector(".el-rail");
    const last = rail && [...rail.querySelectorAll(".el-rail__item, .el-rail__avatar")].at(-1);
    if (!last) return "no rail";
    const r = last.getBoundingClientRect();
    return r.bottom > innerHeight + 0.5 ? `the rail's foot ends at ${Math.round(r.bottom)} of ${innerHeight}` : "";
  });
  if (problem) throw new Error(problem);
};
/**
 * The strip in a normal Mac window: across the whole window, as tall as tokens.css says, nothing of ours in it (the lights
 * are the window's), and the shell right below it. Throws otherwise.
 */
const stripEmpty = async (page) => {
  const problems = await page.evaluate(() => {
    const strip = document.querySelector("[data-testid=titlebar-strip]");
    const out = [];
    if (!strip) return ["no strip"];
    const r = strip.getBoundingClientRect();
    const height = parseFloat(getComputedStyle(document.documentElement).getPropertyValue("--el-layout-titlebar-macos"));
    if (r.top !== 0 || r.left !== 0 || Math.round(r.width) !== innerWidth || Math.round(r.height) !== height) out.push(`the strip is ${[r.left, r.top, r.width, r.height].map(Math.round)}`);
    const shell = document.querySelector(".el-shell")?.getBoundingClientRect();
    if (!shell || Math.round(shell.top) !== height) out.push(`the shell starts at ${shell ? Math.round(shell.top) : "nowhere"}`);
    // Empty: whatever the pointer meets anywhere in it is the strip itself (the drawn lights let the pointer through).
    for (let y = 2; y < height; y += 6) for (let x = 1; x < innerWidth; x += 10) {
      const hit = document.elementFromPoint(x, y);
      if (hit && hit !== strip) out.push(`in the strip at ${x},${y}: ${hit.tagName.toLowerCase()}.${[...hit.classList].join(".")}`);
    }
    return [...new Set(out)].slice(0, 8);
  });
  if (problems.length) throw new Error(`strip: ${problems.join(", ")}`);
};
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
  if (differing !== 0) throw new Error(`${name}: full screen is not 2.0.12's (${differing < 0 ? "another size" : `${differing} pixels differ`})`);
  console.log(`${name}: the same as 2.0.12`);
};
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
    const rail = page.getByRole("navigation", { name: "Main" });
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
        await check(stripEmpty(page), name);
      }
      const stem = `${mac ? `mac${look === "window" ? "" : `-${look}`}` : "windows"}-${name}-${width}-${lang}-${theme}`;
      const picture = await page.screenshot({ path: join(out, `${stem}.png`) });
      console.log(join(out, `${stem}.png`));
      // Full screen is 2.0.12's window, unchanged: the same picture, pixel for pixel, as the one kept from it.
      if (look === "fullscreen" && name === "library" && BASELINE_CASES.has(`${width}-${lang}-${theme}`)) {
        const kept = join(baselines, `${stem}.png`);
        if (writeBaselines) await writeFile(kept, picture);
        else await check(samePicture(page, picture, await readFile(kept), stem), name);
      }
    };
    const extra = { window: "&lights=1", inactive: "&lights=1&inactive=1", fullscreen: "&fullscreen=1", windows: "" }[look];
    await page.goto(`${base}scripts/screens/index.html?screen=library&theme=${theme}&uiflags=${V2_FLAGS}&platform=${platform}${extra}${lang === "ur" ? "&lang=ur" : ""}`);
    await page.getByRole("button", { name: "Library", exact: true }).last().click();
    await page.locator("button[data-row-id]").first().waitFor();
    const rail = page.getByRole("navigation", { name: "Main" });
    // The owner's window: the Library with its panel hidden (below 1100 px it is a flyout, closed).
    const hide = page.getByTestId("nav-panel").getByRole("button", { name: "Hide panel" });
    if (await hide.count()) await hide.click();
    await shoot("library");
    if (look === "window") {
      if (width < 1100) {
        await rail.getByRole("button", { name: /^Library/ }).click();
        await page.getByTestId("nav-panel").waitFor();
        await shoot("library-flyout");
        await page.keyboard.press("Escape");
      } else {
        // 2.0.12's transparent drag strip (fixed, 28 px) covers this button's middle, so a click there drags the window;
        // `--report` runs on that code and presses it from the page instead.
        const show = page.getByRole("button", { name: /^Show the Library panel/ });
        await (reportOnly ? show.dispatchEvent("click") : show.click());
        await page.getByTestId("nav-panel").waitFor();
        await shoot("library-panel");
      }
      await rail.getByRole("button", { name: /^Settings/ }).click();
      await page.waitForTimeout(300);
      await shoot("settings");
    }
    await page.close();
  }
} finally {
  await browser.close();
  await server.close();
}
