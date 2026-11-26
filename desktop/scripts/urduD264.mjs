// Desktop Urdu, D-264, in the screens fixture (every v2 flag; in Urdu v21_urdu on and the account's language Urdu:
// "&applang=ur"), on a Mac and on Windows, in the light and the dark theme, in Urdu and in English: the last pieces the
// new design still showed in English. Live's New session and the Live Assist prep screen (and its meeting types open),
// Live on the Free plan with Live Translate's tag (PlanBadge), a project's Files tab with the screens kept in its
// meetings (ProjectScreens, "&projectscreens=1") and their viewer, the Live Translate overlay's own window unlocked to
// move it (Done) and hidden (its pill), over a game and over a video ("&translate=editing|hidden"), and the Live Assist
// card's window playing its development session ("controls-assist"). The main window is 1280 × 800; each floating
// window is its own size (the core's: Live Translate's box 480 × 120 and subtitles 720 × 90, the Assist card 560 × 440).
// The floating windows have no account of their own: they speak what the main window keeps on this computer
// (localStorage "echoledger-language", { on, account } as useFollowLanguage writes it), set here before the page loads.
// Saves docs/design/screens/desktop/urdu-d264/<platform>-<scene>[-en][-dark].png and fails (listing each problem with
// its scene and its text) when, in Urdu, the window doesn't read right to left (<html lang="ur" dir="rtl">), a scene's
// own text has Latin letters left that aren't kept (the brands and plans that stay Latin, file formats, the fixture's
// people, projects, titles, screens and the Assist session's words, key combinations), or a text is cut; in English,
// when a text is cut. A text is cut as textOnScreen.mjs's cutTexts says (a box or the window clips its ink, Nastaliq's
// included, or it runs into a card's border, or it is cut with "…" though it isn't the fixture's data). Ink that crosses
// a control's own border with nothing clipping it is printed as a note (the control's size is design's), not a failure.
// Live's tag is checked too: its text is the plan's name as it is, its accessible name the window's language.
//   node scripts/urduD264.mjs [--only=macos-light,windows-dark] ["--scenes=live-*,translate-*"] [--lang=ur|en]
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";
import { cutTexts, onScreen, sceneTexts } from "./textOnScreen.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "urdu-d264");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5282, strictPort: false } });
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

/**
 * What stays Latin in an Urdu line: the brands, the plans, the units, a transcript's language chip, the file formats,
 * and the fixture's device's name.
 */
const KEPT = ["EchoLedger", "WhatsApp Business", "WhatsApp Web", "WhatsApp", "Google Meet", "Google", "Meet", "Zoom", "Microsoft Teams", "Teams", "Groq",
  "macOS", "Windows", "Free", "Pro", "Ultra", "MB", "GB", "KB", "EN", "UR",
  "PDF", "Word", "Markdown", "OPUS", "OGG", "MP3", "M4A", "AAC", "WAV", "MP4", "WEBM", "TXT", "DOCX", "Work laptop"];
/**
 * The fixture's own words (scripts/screens/data.ts), as urduD261.mjs reads them: its people, projects, recordings'
 * titles, screens, emails and files; not the app's enums, ids, dates or the labels the server sends for the app to name
 * in its own words. And what the Live Translate fixture hears (the translation, the original) and the Assist card's
 * development session says (v2/assist/fixture.ts: the other side's question, the suggestion's lines, its "Say next"
 * and its language line), as the card draws them (a bullet's bold lead apart from its words).
 */
const DATA_SKIP = new Set(["id", "kind", "status", "type", "key", "heading", "mime", "source_app", "source_app_label", "source_device", "source_device_label",
  "final_provider", "final_provider_label", "final_model", "final_status", "final_language", "language", "audience", "created_by", "role", "error_code", "final_error_code",
  "mode", "requested_mode", "allowed_modes", "meeting_type", "channel", "model", "replaces", "suggestion_id", "turn_id", "session_id"]);
const fixture = await server.ssrLoadModule("/scripts/screens/data.ts");
const assistFixture = await server.ssrLoadModule("/src/v2/assist/fixture.ts");
const data = new Set(KEPT);
const harvest = (value, key) => {
  if (typeof value === "string") {
    const text = value.trim();
    const email = /^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i.test(text);
    const file = /^[^\s/\\]+\.[a-z0-9]{2,5}$/i.test(text);
    const enumLike = /^[a-z0-9_.:/@+-]+$/.test(text) && !email && !file;
    if (!DATA_SKIP.has(key) && /\p{L}/u.test(text) && !enumLike && !/^\d{4}-\d\d-\d\d/.test(text) && !/^(https?|data):/.test(text)) data.add(text);
  } else if (Array.isArray(value)) value.forEach((v) => harvest(v, key));
  else if (value && typeof value === "object") for (const [k, v] of Object.entries(value)) harvest(v, k);
};
for (const name of ["CLIENTS", "ITEMS", "WORKSPACE", "ANSWER", "PENDING", "ACCOUNT", "RULES", "SHARES", "SHARED", "REQUESTS", "NOTES_R5", "SCREENS_R1", "LIBRARY_VIEWS"]) {
  harvest(fixture[name], name);
}
// The Live Translate fixture's words (scripts/screens/main.tsx translate_status), and the app it hears.
for (const text of ["Necesitamos rotar al sitio B ahora mismo", "We need to rotate to site B right now", "We were never supposed to meet like this.", "Valorant"]) data.add(text);
// The Assist session's words, line by line as the card draws them: "- **Lead**: words" is "Lead" and ": words".
for (const m of assistFixture.FIXTURE_SESSION) {
  if (m.type === "turn") data.add(m.text.trim());
  if (m.type !== "token") continue;
  for (const raw of m.text.split("\n")) {
    const line = raw.replace(/^-\s*/, "").trim();
    if (!line) continue;
    for (const piece of line.split("**")) {
      const words = piece.replace(/^:\s*/, "").replace(/^(Say next|Roman Urdu|Urdu|Arabic):\s*/, "").trim();
      if (/\p{L}/u.test(words)) data.add(words);
    }
  }
}
const DATA = [...data];
const escape = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
const dataPattern = new RegExp(`(?<![A-Za-z])(?:${DATA.sort((a, b) => b.length - a.length).map(escape).join("|")})(?![A-Za-z])`, "g");
/**
 * Keys as the app writes them: ⌘N, ⇧⌘K, Ctrl+N, "Ctrl + Shift + Space", Esc. The named keys anywhere; a letter, a digit
 * or a function key only in a combination (after ⌘ ⌥ ⇧ ⌃, or joined to another key by "+").
 */
const NAMED_KEY = "(?:Ctrl|Control|Shift|Alt|Option|Cmd|Command|Space|Enter|Return|Esc|Escape|Tab|Delete|Backspace)";
const ANY_KEY = `(?:${NAMED_KEY}|F\\d{1,2}|[A-Z0-9]|[←→↑↓,.\\][])(?![A-Za-z])`;
const KEYS = new RegExp(`(?:[⌘⌥⇧⌃]\\s?)+(?:${ANY_KEY})?|(?<![A-Za-z])${ANY_KEY}(?:\\s*\\+\\s*${ANY_KEY})+|\\b${NAMED_KEY}\\b`, "g");
/** What is left of a text once its kept names, data and keys are out (and the isolates): any Latin letter is English left. */
const latinLeft = (text) => {
  const rest = text.replace(/[⁦-⁩‎‏]/g, "").replace(dataPattern, "").replace(KEYS, "");
  return /[A-Za-z]/.test(rest);
};

/** The words each language's controls have, to reach the scenes and to know they opened. */
const WORDS = {
  ur: { newSession: "نیا سیشن", prepTitle: "نیا لائیو اسسٹ سیشن", meetingType: "میٹنگ کی قسم", files: "فائلیں", more: "مزید دکھائیں" },
  en: { newSession: "New session", prepTitle: "New Live Assist session", meetingType: "Meeting type", files: "Files", more: "Show more" },
};

try {
  for (const [platform, theme] of [["macos", "light"], ["macos", "dark"], ["windows", "light"], ["windows", "dark"]]) {
    if (only && !only.includes(`${platform}-${theme}`)) continue;
    for (const lang of langs) {
      const where = `${platform === "windows" ? "&platform=windows" : "&platform=macos&lights=1"}&theme=${theme}`;
      const urdu = lang === "ur";
      const label = `${platform} (${theme}, ${urdu ? "Urdu" : "English"})`;
      const words = WORDS[lang];
      /** The main window on `screen`, with the fixture's `extra` switches. */
      const open = async (screen, extra = "") => {
        const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, colorScheme: theme, reducedMotion: "reduce" });
        page.setDefaultTimeout(120_000);
        await page.addInitScript((mac) => Object.defineProperty(navigator, "platform", { value: mac ? "MacIntel" : "Win32", configurable: true }), platform === "macos");
        await page.goto(`${base}scripts/screens/index.html?screen=${screen}&uiflags=${FLAGS}${urdu ? ",v21_urdu" : ""}${where}${urdu ? "&applang=ur" : ""}${extra}`);
        await page.locator('nav[data-nav="main"]').waitFor();
        if (urdu) await page.waitForFunction(() => document.documentElement.dir === "rtl", null, { timeout: 30_000 }).catch(() => undefined);
        await page.evaluate(() => document.fonts.ready);
        await page.waitForTimeout(1200);
        return page;
      };
      /**
       * A floating window on `screen`, at its own `size`: what the main window keeps on this computer set first (in
       * Urdu: Urdu on, the account's Urdu), a Mac's keys on a Mac ("&computer=macos").
       */
      const openWindow = async (screen, size, extra = "") => {
        const page = await browser.newPage({ viewport: size, colorScheme: theme, reducedMotion: "reduce" });
        page.setDefaultTimeout(120_000);
        await page.addInitScript(({ mac, urdu }) => {
          Object.defineProperty(navigator, "platform", { value: mac ? "MacIntel" : "Win32", configurable: true });
          if (urdu) localStorage.setItem("echoledger-language", JSON.stringify({ on: true, account: "ur" }));
        }, { mac: platform === "macos", urdu });
        await page.goto(`${base}scripts/screens/index.html?screen=${screen}&theme=${theme}&platform=${platform}${platform === "macos" ? "&computer=macos" : ""}${extra}`);
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
        const { cut, notes } = await cutTexts(page, root, DATA);
        for (const c of new Set(cut.map((c) => `cut ${c.what} “${c.text}”: ${c.why}`))) say(c);
        console.log(`${label} ${scene}: <html> ${at}; ${texts.length} texts${urdu ? `, ${english.length} with Latin left` : ""}; ${cut.length} cut${english.length || cut.length ? "" : " (clear)"}`);
        for (const n of notes) console.log(`  note: ${n.what} “${n.text}”: ${n.why}`);
        return say;
      };
      const toLive = async (page) => {
        await page.locator('nav[data-nav="main"] [data-place="live"]').first().click();
        await page.locator("section.content.page-live").waitFor();
        await page.waitForTimeout(1000);
      };

      // Live: New session, the prep screen, and its meeting types open.
      if (wanted("live-prep") || wanted("live-prep-types")) {
        const page = await open("record");
        await toLive(page);
        await page.locator("section.content").getByRole("button", { name: words.newSession, exact: true }).first().click();
        await page.locator('[data-v2="assist-prep"]').waitFor();
        await page.waitForTimeout(1000);
        if (wanted("live-prep")) await shot(page, "live-prep", '[data-v2="assist-prep"]', words.prepTitle);
        if (wanted("live-prep-types")) {
          await page.locator('[data-v2="assist-prep"] [role="combobox"]').first().click();
          await page.locator('[data-v2="assist-prep"] [role="listbox"]').first().waitFor();
          await page.waitForTimeout(600);
          await shot(page, "live-prep-types", '[data-v2="assist-prep"]', words.meetingType);
        }
        await page.close();
      }

      // Live on the Free plan: Live Translate's row with its tag, the plan that has it. The tag's text is the plan's
      // name as it is; its accessible name and tooltip are the window's language.
      if (wanted("live-badge")) {
        const page = await open("record", "&plan=free");
        await toLive(page);
        const badge = page.locator("section.content.page-live .plan-badge").first();
        await badge.waitFor();
        const say = await shot(page, "live-badge", "section.content.page-live", "Ultra");
        const tag = await badge.evaluate((b) => ({ text: b.textContent.trim(), name: b.getAttribute("aria-label") ?? "", title: b.getAttribute("title") ?? "" }));
        const plain = (s) => s.replace(/[⁦-⁩]/g, "");
        console.log(`  tag: “${tag.text}”, named “${plain(tag.name)}”, tooltip “${plain(tag.title)}”`);
        if (tag.text !== "Ultra") say?.(`the tag says “${tag.text}”, not the plan's name`);
        for (const [what, value] of [["accessible name", tag.name], ["tooltip", tag.title]]) {
          if (urdu && (latinLeft(value) || !/[؀-ۿ]/.test(value))) say?.(`the tag's ${what} “${plain(value)}” isn't Urdu`);
          if (!urdu && /[؀-ۿ]/.test(value)) say?.(`the tag's ${what} “${plain(value)}” isn't English`);
        }
        await page.close();
      }

      // A project's Files tab with the screens kept in its meetings (Northwind's Weekly sync), then a screen opened.
      if (wanted("project-screens") || wanted("project-screens-viewer")) {
        const page = await open("projects", "&projectscreens=1");
        await page.locator(".el-project-card", { hasText: "Northwind" }).first().click();
        await page.locator('[data-v2="workspace"] [role="tab"]').first().waitFor();
        await page.getByRole("tab", { name: words.files }).click();
        const grid = page.locator('[data-testid="project-screens"]');
        await grid.waitFor();
        // The section's top at the window's top (the page scrolls in its own box).
        await page.locator('section[aria-labelledby="el-project-screens"]').evaluate((section) => section.scrollIntoView({ block: "start" }));
        await page.waitForTimeout(1200);
        // (Its Keep screen moments switch isn't there: the new design gives the page the project without its `screens`.)
        if (wanted("project-screens")) await shot(page, "project-screens", 'section[aria-labelledby="el-project-screens"]', words.more);
        if (wanted("project-screens-viewer")) {
          await grid.locator("button.screen-thumb").nth(1).click();
          await page.locator(".screen-viewer").waitFor();
          await page.waitForTimeout(1200);
          await shot(page, "project-screens-viewer", ".screen-viewer");
        }
        await page.close();
      }

      // Live Translate's own window, unlocked to move it (Done) and hidden (its pill), over a game and a video.
      for (const [kind, size] of [["box", { width: 480, height: 120 }], ["subtitles", { width: 720, height: 90 }]]) {
        for (const state of ["editing", "hidden"]) {
          const scene = `translate-${state === "editing" ? "done" : "hidden"}${kind === "box" ? "" : "-subtitles"}`;
          if (!wanted(scene)) continue;
          const page = await openWindow(`controls-translate-${kind}`, size, `&translate=${state}`);
          const root = state === "editing" ? ".translate-overlay" : ".translate-hidden";
          await page.locator(state === "editing" ? ".translate-done" : root).waitFor();
          await page.waitForTimeout(600);
          await shot(page, scene, root);
          await page.close();
        }
      }

      // The Live Assist card's window, its development session played to its end.
      if (wanted("assist-card")) {
        const page = await openWindow("controls-assist", { width: 560, height: 440 }, "&assistfixture=1");
        await page.locator(".el-assist-root .el-assist").first().waitFor().catch(() => undefined);
        // The session's eleven messages, one every 400 ms.
        await page.waitForTimeout(6000);
        await shot(page, "assist-card", ".el-assist-root");
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
