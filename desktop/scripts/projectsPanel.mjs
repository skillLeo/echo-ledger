// Projects (UX-09): the panel is the project list (Pinned first, the rest, Archived last; a search from 11 projects),
// "All projects" is the page's title, Recent a sort option; with no project there is no panel and the page has one
// empty state; a project's tabs are Recordings · Files · Memory & instructions · Ask · Members, with Request files in its
// header. In the screens fixture, a Mac window (800 px tall, the traffic lights drawn), saved under
// docs/design/screens/desktop/ux-09/:
//   <prefix>-all-<width>-<lang>-<theme>.png   the Projects place (All projects) at 1024 and 1280, English and Urdu, light and dark
//   <prefix>-empty-1280-en-<theme>.png        an account with no project (light and dark)
//   <prefix>-project-1280-en-<theme>.png      a project's page (Acme Holdings) with its tabs
//   after-members-1280-en-<theme>.png         its Members tab
//   after-sort-1280-en-<theme>.png            the panel's sort menu open
//   <prefix>-project-1280-ur-<theme>.png      the project's page in Urdu (right to left); after-members-1280-ur-<theme>.png
//   after-files-screens-1280-en-<theme>.png   Files, scrolled to its Screens section (before-screens-…: the old tab)
//   after-memory-notes-1280-en-<theme>.png    Memory & instructions, scrolled to Call notes (before-callnotes-…: the old tab)
//   after-archived-1280-en-<theme>.png        the panel's Archived, opened (&archived=1)
//   <prefix>-search-1280-en-<theme>.png       the panel's search among 13 projects (&manyprojects=1)
// PROJECTS_EXTRAS_ONLY=1 leaves the All projects set out.
// After the change the run fails when the panel has an "All projects", "Pinned" or "Recent" row, a project isn't in the
// panel, the page's title isn't "All projects", there is a panel with no project, the empty state isn't the one asked
// for, the project's tabs aren't the five in order, Request files isn't in its header, or the page is wider than the window.
//   node scripts/projectsPanel.mjs before
//   node scripts/projectsPanel.mjs after
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS as V2_FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = process.env.PROJECTS_OUT ?? join(desktop, "..", "docs", "design", "screens", "desktop", "ux-09");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const prefix = process.argv[2] ?? "after";
const after = prefix === "after";
const HEIGHT = 800;
const TABS = ["Recordings", "Files", "Memory & instructions", "Ask", "Members"];

const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5249, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });

const open = async (width, lang, theme, extra = "") => {
  return openAt(width, lang, theme, extra);
};
const openAt = async (width, lang, theme, extra = "") => {
  const page = await browser.newPage({ viewport: { width, height: HEIGHT }, colorScheme: theme, reducedMotion: "reduce" });
  // A busy machine serves the fixture slowly; the waits are for it to load, not a measure of anything.
  page.setDefaultTimeout(120_000);
  await page.goto(`${base}scripts/screens/index.html?screen=projects&theme=${theme}&uiflags=${V2_FLAGS}&platform=macos&lights=1${lang === "ur" ? "&lang=ur" : ""}${extra}`);
  const rail = page.locator('nav[data-nav="main"]');
  await rail.waitFor();
  await rail.getByRole("button", { name: /^Projects/ }).click();
  await page.locator("[data-v2=projects]").first().waitFor();
  // Below 1100 px the panel is a flyout: closed, so the page shows as the person sees it at rest.
  if (width < 1100 && await page.getByTestId("nav-panel").isVisible().catch(() => false)) await page.keyboard.press("Escape");
  return page;
};
const rest = async (page) => {
  await page.mouse.move(0, 0);
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(500);
};
/** The panel's rows and the page's title (the title row's, else the page's own). */
const measure = (page) => page.evaluate(() => {
  const panel = document.querySelector('[data-testid="nav-panel"]');
  const rows = panel ? [...panel.querySelectorAll(".el-nav-item")].map((b) => b.textContent.trim()) : null;
  const titleRow = document.querySelector('[data-testid="title-row"] .el-titlerow__name')?.textContent.trim() ?? null;
  const own = document.querySelector("[data-v2=projects] h1")?.textContent.trim() ?? null;
  return { rows, title: titleRow || own, wide: document.documentElement.scrollWidth > innerWidth + 0.5 };
});

const problems = [];
try {
  for (const width of process.env.PROJECTS_EXTRAS_ONLY ? [] : [1024, 1280]) for (const lang of ["en", "ur"]) for (const theme of ["light", "dark"]) {
    const name = `${prefix}-all-${width}-${lang}-${theme}`;
    const page = await open(width, lang, theme);
    await rest(page);
    await page.screenshot({ path: join(out, `${name}.png`) });
    const m = await measure(page);
    console.log(`${name}: title "${m.title}"; panel ${m.rows ? m.rows.join(" | ") : "none"}${m.wide ? "; WIDER THAN THE WINDOW" : ""}`);
    if (after) {
      if (m.title !== "All projects") problems.push(`${name}: the title is "${m.title}"`);
      if (m.wide) problems.push(`${name}: wider than the window`);
      if (m.rows) {
        for (const row of ["All projects", "Pinned", "Recent"]) if (m.rows.some((r) => r.startsWith(row))) problems.push(`${name}: a "${row}" row in the panel`);
        for (const project of ["Acme Holdings", "Northwind", "Aria Studio", "Zed Logistics"]) if (!m.rows.includes(project)) problems.push(`${name}: ${project} isn't in the panel`);
      } else if (width >= 1100) problems.push(`${name}: no panel`);
    }
    await page.close();
  }
  for (const theme of ["light", "dark"]) {
  {
    const name = `${prefix}-empty-1280-en-${theme}`;
    const page = await open(1280, "en", theme, "&noprojects=1");
    await rest(page);
    await page.screenshot({ path: join(out, `${name}.png`) });
    const m = await measure(page);
    const empty = await page.evaluate(() => {
      const box = document.querySelector("[data-v2=projects] .el-empty");
      return box ? { text: box.textContent, buttons: [...box.querySelectorAll("button")].map((b) => [b.textContent.trim(), b.className]) } : null;
    });
    console.log(`${name}: panel ${m.rows ? "SHOWN" : "none"}; empty state: ${empty ? `"${empty.text}"` : "none"}`);
    if (after) {
      if (m.rows) problems.push(`${name}: a panel with no project`);
      if (!empty?.text.includes("Keep each client's calls, files and notes together")) problems.push(`${name}: the empty state says "${empty?.text}"`);
      const [first, second] = empty?.buttons ?? [];
      if (first?.[0] !== "New project" || !first?.[1].includes("primary")) problems.push(`${name}: New project isn't the main button`);
      if (second?.[0] !== "Request files from a client") problems.push(`${name}: no "Request files from a client"`);
    }
    await page.close();
  }
  {
    const name = `${prefix}-project-1280-en-${theme}`;
    const page = await open(1280, "en", theme);
    await page.getByTestId("nav-panel").getByRole("button", { name: "Acme Holdings", exact: true }).click();
    await page.locator("[data-v2=workspace]").waitFor();
    await rest(page);
    await page.screenshot({ path: join(out, `${name}.png`) });
    const tabs = await page.getByRole("tab").allTextContents();
    const request = await page.locator("[data-v2=workspace] .el-page__head").getByRole("button", { name: /^Request files/ }).count();
    console.log(`${name}: tabs ${tabs.join(" | ")}; Request files in the header: ${request ? "yes" : "no"}`);
    if (after) {
      const names = tabs.map((t) => TABS.find((x) => t.startsWith(x)) ?? t);
      if (names.join() !== TABS.join()) problems.push(`${name}: the tabs are ${tabs.join(", ")}`);
      if (!request) problems.push(`${name}: Request files isn't in the header`);
      await page.getByRole("tab", { name: /^Members/ }).click();
      await page.waitForTimeout(800);
      await rest(page);
      await page.screenshot({ path: join(out, `after-members-1280-en-${theme}.png`) });
      const members = await page.getByRole("tabpanel").textContent();
      console.log(`after-members-1280-en-${theme}: ${members.slice(0, 160)}`);
      if (!/\(you\)|You/.test(members)) problems.push("after-members: the owner isn't listed");
    }
    await page.close();
  }
  {
    // The project's page right to left (Urdu): its tabs, and Members.
    const name = `${prefix}-project-1280-ur-${theme}`;
    const page = await open(1280, "ur", theme);
    await page.getByTestId("nav-panel").getByRole("button", { name: "Acme Holdings", exact: true }).click();
    await page.locator("[data-v2=workspace]").waitFor();
    await rest(page);
    await page.screenshot({ path: join(out, `${name}.png`) });
    console.log(`${name}: ${(await page.getByRole("tab").allTextContents()).join(" | ")}`);
    if (after) {
      await page.getByRole("tab", { name: /^Members/ }).click();
      await page.waitForTimeout(800);
      await rest(page);
      await page.screenshot({ path: join(out, `after-members-1280-ur-${theme}.png`) });
      const dir = await page.evaluate(() => getComputedStyle(document.querySelector("[data-v2=workspace]")).direction);
      if (dir !== "rtl") problems.push(`after-members-1280-ur-${theme}: drawn ${dir}`);
    }
    await page.close();
  }
  {
    // Screens: a section of Files now (the old Screens tab before).
    const name = after ? `after-files-screens-1280-en-${theme}` : `before-screens-1280-en-${theme}`;
    const page = await open(1280, "en", theme);
    await page.getByTestId("nav-panel").getByRole("button", { name: "Acme Holdings", exact: true }).click();
    await page.locator("[data-v2=workspace]").waitFor();
    if (after) {
      await page.getByRole("tab", { name: /^Files/ }).click();
      await page.getByRole("region", { name: /^Screens/ }).waitFor();
      await page.waitForTimeout(800);
      await page.getByRole("region", { name: /^Screens/ }).evaluate((el) => el.scrollIntoView({ block: "start" }));
    } else {
      await page.getByRole("tab", { name: /^Screens/ }).click();
      await page.waitForTimeout(1200);
    }
    await rest(page);
    await page.screenshot({ path: join(out, `${name}.png`) });
    console.log(`${name}: shown`);
    await page.close();
  }
  {
    // Call notes: a section of Memory & instructions now (the old Call notes tab before).
    const name = after ? `after-memory-notes-1280-en-${theme}` : `before-callnotes-1280-en-${theme}`;
    const page = await open(1280, "en", theme);
    await page.getByTestId("nav-panel").getByRole("button", { name: "Acme Holdings", exact: true }).click();
    await page.locator("[data-v2=workspace]").waitFor();
    if (after) {
      await page.getByRole("tab", { name: "Memory & instructions" }).click();
      const notes = page.getByRole("region", { name: "Call notes" });
      await notes.waitFor();
      await page.waitForTimeout(600);
      await notes.evaluate((el) => el.scrollIntoView({ block: "start" }));
    } else {
      await page.getByRole("tab", { name: "Call notes" }).click();
      await page.waitForTimeout(1000);
    }
    await rest(page);
    await page.screenshot({ path: join(out, `${name}.png`) });
    console.log(`${name}: shown`);
    await page.close();
  }
  {
    // The panel's search among 13 projects.
    const name = `${prefix}-search-1280-en-${theme}`;
    const page = await open(1280, "en", theme, "&manyprojects=1");
    const field = page.getByTestId("nav-panel").getByRole("searchbox", { name: "Search projects" });
    await field.fill("co");
    await page.waitForTimeout(400);
    await page.mouse.move(0, 0);
    await page.screenshot({ path: join(out, `${name}.png`) });
    const found = await page.getByTestId("nav-panel").locator(".el-nav-tree__name").allTextContents();
    console.log(`${name}: ${found.join(" | ")}`);
    if (after && found.join() !== "Contracts,Indus Cotton") problems.push(`${name}: found ${found.join(", ")}`);
    await page.close();
  }
  if (after) {
    // Archived, opened at the panel's bottom (no server marks one yet: the fixture's &archived=1).
    const name = `after-archived-1280-en-${theme}`;
    const page = await open(1280, "en", theme, "&archived=1");
    const panel = page.getByTestId("nav-panel");
    await panel.getByRole("button", { name: /^Archived/ }).click();
    await page.waitForTimeout(300);
    await page.mouse.move(0, 0);
    await page.screenshot({ path: join(out, `${name}.png`) });
    const archived = await panel.getByRole("list", { name: "Archived" }).locator(".el-nav-tree__name").allTextContents();
    const others = await panel.getByRole("list", { name: "Projects" }).locator(".el-nav-tree__name").allTextContents();
    console.log(`${name}: Archived ${archived.join(" | ")}; Projects ${others.join(" | ")}`);
    if (archived.join() !== "Zed Logistics" || others.includes("Zed Logistics")) problems.push(`${name}: Archived has ${archived.join(", ")}`);
    await page.close();
  }
  if (after) {
    const name = `after-sort-1280-en-${theme}`;
    const page = await open(1280, "en", theme);
    await page.getByTestId("nav-panel").getByRole("button", { name: /^Sort projects/ }).click();
    await page.getByRole("menu").waitFor();
    await page.screenshot({ path: join(out, `${name}.png`) });
    const items = await page.getByRole("menu").locator('[role^="menuitem"]').allTextContents();
    console.log(`${name}: ${items.join(" | ")}`);
    if (!items.some((i) => i.startsWith("Recent"))) problems.push(`${name}: Recent isn't a sort option`);
    await page.close();
  }
  }
} finally {
  await browser.close();
  await server.close();
}
if (problems.length) {
  console.error(problems.join("\n"));
  process.exit(1);
}
