// Desktop Urdu, D-261, in the screens fixture (every v2 flag; in Urdu v21_urdu on and the account's language Urdu:
// "&applang=ur"; 1280 × 800), on a Mac and on Windows, in the light and the dark theme, in Urdu and in English: the
// screens the new design still showed in English. The Record page (Home), Live, a project's page (Acme Holdings) on
// each of its tabs, and its name dialogs: New project (from All projects), New folder in a project and Rename (from the
// project's menu). The app's navigation is found by data-nav="main", its name being the window's language.
// Saves docs/design/screens/desktop/urdu-d261/<platform>-<scene>[-en][-dark].png and fails (listing each problem with
// its scene and its text) when, in Urdu, the window doesn't read right to left (<html lang="ur" dir="rtl">), a scene's
// own text has Latin letters left that aren't a kept name (the brands and plans that stay Latin, the fixture's people,
// projects, titles and files, keys), or a label is cut; in English, when a label is cut. A label (a button, a tab, a
// heading, a label, a menu item, a dialog's title) is cut when its words, Nastaliq's ink up and down included, don't lie
// inside its box or a box it sits in clips them (textOnScreen.mjs's cutLabels).
//   node scripts/urduD261.mjs [--only=macos-light,windows-dark] ["--scenes=new-project,rename,project-*"] [--lang=ur|en]
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";
import { cutLabels, onScreen, sceneTexts } from "./textOnScreen.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "urdu-d261");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5281, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];
const arg = (name) => process.argv.find((a) => a.startsWith(`--${name}=`))?.split("=")[1]?.split(",");
const only = arg("only");
const scenes = arg("scenes");
const langs = arg("lang") ?? ["ur", "en"];
const wanted = (scene) => !scenes || scenes.some((s) => scene === s || (s.endsWith("*") && scene.startsWith(s.slice(0, -1))));

/** Names that stay Latin in an Urdu line (the brands, the plans, the units, a transcript's language chip). */
const KEPT = ["EchoLedger", "WhatsApp Business", "WhatsApp Web", "WhatsApp", "Google Meet", "Google", "Meet", "Zoom", "Microsoft Teams", "Teams", "Groq",
  "macOS", "Windows", "Free", "Pro", "Ultra", "MB", "GB", "KB", "PDF", "EN", "UR", "Work laptop"];
/**
 * The fixture's own words (scripts/screens/data.ts): its people, projects, recordings' titles and what was said, files,
 * a brief, memory and answers. Every text in them, but the app's enums, ids, dates and the labels the server sends for
 * the app to name in its own words (source_app_label…), and a call note's headings (the app has headings of its own
 * with the same English: Decisions, Changes).
 */
const DATA_SKIP = new Set(["id", "kind", "status", "type", "key", "heading", "mime", "source_app", "source_app_label", "source_device", "source_device_label",
  "final_provider", "final_provider_label", "final_model", "final_status", "final_language", "language", "audience", "created_by", "role", "error_code", "final_error_code"]);
const fixture = await server.ssrLoadModule("/scripts/screens/data.ts");
const data = new Set(KEPT);
const harvest = (value, key) => {
  if (typeof value === "string") {
    const text = value.trim();
    if (!DATA_SKIP.has(key) && /[A-Za-z]/.test(text) && !/^[a-z0-9_.:/@+-]+$/.test(text) && !/^\d{4}-\d\d-\d\d/.test(text) && !/^(https?|data):/.test(text)) data.add(text);
  } else if (Array.isArray(value)) value.forEach((v) => harvest(v, key));
  else if (value && typeof value === "object") for (const [k, v] of Object.entries(value)) harvest(v, k);
};
// Not the transcripts: their word-by-word timings ("November", "Friday.") would hide an English date's month.
for (const name of ["CLIENTS", "ITEMS", "WORKSPACE", "ANSWER", "PENDING", "ACCOUNT", "RULES", "SHARES", "SHARED", "REQUESTS", "NOTES_R5", "SCREENS_R1", "LIBRARY_VIEWS"]) {
  harvest(fixture[name], name);
}
const DATA = [...data];
const escape = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
const dataPattern = new RegExp(`(?<![A-Za-z])(?:${DATA.sort((a, b) => b.length - a.length).map(escape).join("|")})(?![A-Za-z])`, "g");
/** Keys as the app writes them: ⌘N, ⇧⌘K, Ctrl+N, Ctrl+Shift+F, Esc. */
const KEYS = /(?:[⌘⌥⇧⌃]\s?)+(?:[A-Z0-9]|F\d{1,2}|Enter|Esc|Tab|Space|Delete|[←→↑↓,.\][])|\b(?:Ctrl|Alt|Shift|Cmd|Win)(?:\s?\+\s?(?:Ctrl|Alt|Shift|[A-Z0-9]|F\d{1,2}|Enter|Esc|Tab|Space|Delete|Backspace|[←→↑↓,.\][]))+|\b(?:Esc|Enter)\b/g;
/** What is left of a text once its kept names, data and keys are out (and the isolates): any Latin letter is English left. */
const latinLeft = (text) => {
  const rest = text.replace(/[\u2066-\u2069\u200e\u200f]/g, "").replace(dataPattern, "").replace(KEYS, "");
  return /[A-Za-z]/.test(rest);
};

/** The words each language's controls have, to reach the scenes. */
const WORDS = {
  ur: { newProject: "نیا پروجیکٹ", newFolder: "اندر نیا فولڈر", rename: "نام بدلیں", newProjectTitle: "نیا پروجیکٹ", newFolderTitle: "میں نیا فولڈر", renameTitle: "کا نام بدلیں" },
  en: { newProject: "New project", newFolder: "New folder inside", rename: "Rename", newProjectTitle: "New project", newFolderTitle: "New folder in", renameTitle: "Rename" },
};

try {
  for (const [platform, theme] of [["macos", "light"], ["macos", "dark"], ["windows", "light"], ["windows", "dark"]]) {
    if (only && !only.includes(`${platform}-${theme}`)) continue;
    for (const lang of langs) {
      const where = `${platform === "windows" ? "&platform=windows" : "&platform=macos&lights=1"}&theme=${theme}`;
      const urdu = lang === "ur";
      const label = `${platform} (${theme}, ${urdu ? "Urdu" : "English"})`;
      const words = WORDS[lang];
      const open = async (screen) => {
        const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, colorScheme: theme, reducedMotion: "reduce" });
        page.setDefaultTimeout(120_000);
        await page.addInitScript((mac) => Object.defineProperty(navigator, "platform", { value: mac ? "MacIntel" : "Win32", configurable: true }), platform === "macos");
        await page.goto(`${base}scripts/screens/index.html?screen=${screen}&uiflags=${FLAGS}${urdu ? ",v21_urdu" : ""}${where}${urdu ? "&applang=ur" : ""}`);
        await page.locator('nav[data-nav="main"]').waitFor();
        if (urdu) await page.waitForFunction(() => document.documentElement.dir === "rtl", null, { timeout: 30_000 }).catch(() => undefined);
        await page.evaluate(() => document.fonts.ready);
        await page.waitForTimeout(1200);
        return page;
      };
      /**
       * Saves the window as `scene`, then checks the scene's own text (`root`): in Urdu the window's direction and
       * the Latin left; in both languages the labels cut. `shows`: words the scene must have on screen (it opened).
       */
      const shot = async (page, scene, root, shows) => {
        await page.mouse.move(0, 0);
        await page.waitForTimeout(500);
        await page.evaluate(() => document.fonts.ready);
        await page.screenshot({ path: join(out, `${platform}-${scene}${urdu ? "" : "-en"}${theme === "dark" ? "-dark" : ""}.png`) });
        const say = (problem) => problems.push(`${label} ${scene}: ${problem}`);
        const at = await page.evaluate(() => `${document.documentElement.lang || "-"}/${document.documentElement.dir || "-"}`);
        if (urdu && at !== "ur/rtl") say(`<html> is ${at}, not ur/rtl`);
        if (shows && !(await onScreen(page, shows))) say(`doesn't show “${shows}”`);
        const texts = await sceneTexts(page, root);
        if (texts === null) {
          say(`${root} is not on the page`);
          return;
        }
        const english = urdu ? [...new Set(texts.filter(latinLeft))] : [];
        for (const text of english.slice(0, 12)) say(`Latin left: “${text}”`);
        if (english.length > 12) say(`and ${english.length - 12} more texts with Latin letters`);
        const { cut, notes } = await cutLabels(page, root, DATA);
        for (const c of cut) say(`cut ${c.what} “${c.text}”: ${c.why}`);
        console.log(`${label} ${scene}: <html> ${at}; ${texts.length} texts${urdu ? `, ${english.length} with Latin left` : ""}; ${cut.length} cut${english.length || cut.length ? "" : " (clear)"}`);
        for (const n of notes) console.log(`  note: ${n.what} “${n.text}”: ${n.why}`);
      };
      const dialogOpen = async (page) => {
        await page.locator('[role="dialog"]').first().waitFor();
        await page.waitForTimeout(600);
      };
      const dialogClose = async (page) => {
        await page.keyboard.press("Escape");
        await page.locator('[role="dialog"]').first().waitFor({ state: "detached" }).catch(() => undefined);
        await page.waitForTimeout(300);
      };

      // The Record page (Home), then Live from the rail.
      if (wanted("home") || wanted("live")) {
        const page = await open("record");
        await page.locator("section.content").first().waitFor();
        if (wanted("home")) await shot(page, "home", "section.content");
        if (wanted("live")) {
          await page.locator('nav[data-nav="main"] [data-place="live"]').first().click();
          await page.locator("section.content.page-live").waitFor();
          await page.waitForTimeout(1000);
          await shot(page, "live", "section.content.page-live");
        }
        await page.close();
      }

      // All projects: New project. Then Acme Holdings' page, each tab, and its menu's New folder inside and Rename.
      if (!scenes || scenes.some((s) => s.startsWith("project-") || ["new-project", "new-folder", "rename"].includes(s))) {
        const page = await open("projects");
        await page.locator(".el-project-card").first().waitFor();
        if (wanted("new-project")) {
          await page.locator("section.content").getByRole("button", { name: words.newProject, exact: true }).first().click();
          await dialogOpen(page);
          await shot(page, "new-project", '[role="dialog"]', words.newProjectTitle);
          await dialogClose(page);
        }
        await page.locator(".el-project-card", { hasText: "Acme Holdings" }).first().click();
        await page.locator('[data-v2="workspace"] [role="tab"]').first().waitFor();
        await page.waitForTimeout(1200);
        const tabs = page.locator('[data-v2="workspace"] [role="tab"]');
        const count = await tabs.count();
        for (let i = 0; i < count; i++) {
          const id = (await tabs.nth(i).getAttribute("id")) ?? `tab-${i}`;
          const scene = `project-${id.split("-tab-").pop()}`;
          if (!wanted(scene)) continue;
          await tabs.nth(i).click();
          await page.waitForTimeout(1200);
          await shot(page, scene, "section.content", "Acme Holdings");
        }
        if (count) await tabs.first().click();
        const menu = page.locator('[data-v2="workspace"] .el-page__actions button[aria-haspopup="menu"]').last();
        for (const [scene, item, title] of [["new-folder", words.newFolder, words.newFolderTitle], ["rename", words.rename, words.renameTitle]]) {
          if (!wanted(scene)) continue;
          await menu.click();
          await page.getByRole("menuitem", { name: item, exact: true }).click();
          await dialogOpen(page);
          await shot(page, scene, '[role="dialog"]', title);
          await dialogClose(page);
        }
        await page.close();
      }
    }
  }
} finally {
  await browser.close();
  await server.close();
}
if (problems.length) {
  console.error(`\n${problems.length} problems:\n${problems.join("\n")}`);
  process.exit(1);
}
