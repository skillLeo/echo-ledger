// The desktop v2 accessibility pass: axe-core on every gallery and app-path screen in light and dark, a check that every
// control has a name, and keyboard-only walks of the top five tasks (record, find a recording, transcribe, delete with
// Undo, change a setting) in the app's fixture mode with every v2 flag on. Prints a JSON report.
//   node src/v2/gallery/a11y.mjs --out=DIR [--only=axe|keys]
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir, writeFile } from "node:fs/promises";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..", "..", "..");
const arg = (name) => process.argv.find((a) => a.startsWith(`--${name}=`))?.split("=")[1];
const out = resolve(arg("out") ?? join(desktop, "..", "docs", "v2", "bugbash", "desktop-a11y"));
const only = arg("only");
const axePath = join(desktop, "node_modules", "axe-core", "axe.min.js");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";

const FLAGS = "ui_v2.shell,ui_v2.library,ui_v2.settings,ui_v2.setup_health,ui_v2.home,ui_v2.live,ui_v2.ask,live_assist,ui_v2.recording_controls,ui_v2.after_call";
const app = (screen) => `scripts/screens/index.html?screen=${screen}&uiflags=${FLAGS}`;
const nav = (name) => async (page) => {
  await page.getByRole("navigation", { name: "Main" }).getByRole("button", { name, exact: true }).click();
  await page.waitForTimeout(1200);
};
const project = async (page) => {
  await page.getByRole("button", { name: "Acme Holdings", exact: true }).first().click();
  await page.waitForTimeout(1200);
};

/** [name, path, steps] */
const SCREENS = [
  ["g-library", "?v2gallery&screen=library&lang=en"],
  ["g-library-ur", "?v2gallery&screen=library&lang=ur"],
  ["g-recording", "?v2gallery&screen=recording&lang=en"],
  ["g-settings", "?v2gallery&screen=settings&lang=en"],
  ["g-library-loading", "?v2gallery&screen=library&state=loading&lang=en"],
  ["g-library-error", "?v2gallery&screen=library&state=error&lang=en"],
  ["g-recording-empty", "?v2gallery&screen=recording&state=empty&lang=en"],
  ["g-assist", "?v2gallery&screen=assist&lang=en"],
  ["g-plan-ur", "?v2gallery&screen=plan&lang=ur"],
  ["a-library", app("library")],
  ["a-recording", app("library"), async (page) => { await page.locator(".el-row__hit").first().click(); await page.waitForTimeout(1200); }],
  ["a-recording-screens", app("library"), async (page) => { await page.getByRole("button", { name: "Weekly sync with Northwind", exact: true }).click(); await page.waitForTimeout(1500); }],
  ["a-screen-viewer", app("library"), async (page) => { await page.getByRole("button", { name: "Weekly sync with Northwind", exact: true }).click(); await page.waitForTimeout(1500); await page.getByRole("region", { name: /screens?$/ }).getByRole("button").first().click(); await page.waitForTimeout(800); }],
  ["a-trash", app("library"), async (page) => { await page.getByRole("button", { name: "Trash", exact: true }).click(); await page.waitForTimeout(1200); }],
  ["a-home", app("library"), nav("Home")],
  ["a-live", app("library"), nav("Live")],
  ["a-live-translate", app("library"), async (page) => { await page.getByRole("navigation", { name: "Main" }).getByRole("button", { name: "Live", exact: true }).click(); await page.waitForTimeout(1200); await page.getByRole("button", { name: "Live Translate", exact: true }).click(); await page.waitForTimeout(800); }],
  ["a-live-translate-ended", `${app("library")}&translate=ended`, async (page) => { await page.getByRole("navigation", { name: "Main" }).getByRole("button", { name: "Live", exact: true }).click(); await page.waitForTimeout(1200); await page.getByRole("button", { name: "Live Translate", exact: true }).click(); await page.waitForTimeout(800); }],
  ["a-prep", app("library"), async (page) => { await nav("Live")(page); await page.getByRole("button", { name: "New session" }).click(); await page.waitForTimeout(800); }],
  ["a-ask", app("library"), nav("Ask")],
  ["a-project", app("project"), project],
  ["a-project-memory", app("project"), async (page) => { await project(page); await page.getByRole("tab", { name: "Memory & instructions" }).click(); await page.waitForTimeout(800); }],
  ["a-settings", app("settings")],
  ["a-setup", app("settings-mac"), async (page) => { await page.getByRole("button", { name: "Setup & health" }).click(); await page.waitForTimeout(1500); }],
  ["a-help", app("settings"), async (page) => { await page.getByRole("button", { name: "Help", exact: true }).click(); await page.waitForTimeout(1200); }],
  ["a-help-guide", app("settings"), async (page) => { await page.getByRole("button", { name: "Help", exact: true }).click(); await page.waitForTimeout(1200); await page.getByRole("button", { name: "After a call", exact: true }).click(); await page.waitForTimeout(800); }],
  ["a-whats-new", app("settings"), async (page) => { await page.getByRole("button", { name: "Help", exact: true }).click(); await page.waitForTimeout(1200); await page.getByRole("tab", { name: "What's new" }).click(); await page.waitForTimeout(800); }],
  ["a-contact", app("settings"), async (page) => { await page.getByRole("button", { name: "Help", exact: true }).click(); await page.waitForTimeout(1200); await page.getByRole("tab", { name: "Contact support" }).click(); await page.waitForTimeout(500); }],
  ["a-set-voice", app("settings"), async (page) => { await page.getByRole("button", { name: "Voice notes & dictation", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-replacements", app("settings"), async (page) => { await page.getByRole("button", { name: "Voice notes & dictation", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "Replacements", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-history", app("settings"), async (page) => { await page.getByRole("button", { name: "Voice notes & dictation", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "Recent dictations", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-captions", app("settings"), async (page) => { await page.getByRole("button", { name: "Captions", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-caption-style", app("settings"), async (page) => { await page.getByRole("button", { name: "Captions", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "Style", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-calls", app("settings"), async (page) => { await page.getByRole("button", { name: "Calls & meetings", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-call-sites", app("settings"), async (page) => { await page.getByRole("button", { name: "Calls & meetings", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "Call sites", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-language", app("settings"), async (page) => { await page.getByRole("button", { name: "Language", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-words", app("settings"), async (page) => { await page.getByRole("button", { name: "Custom words", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-key", `${app("settings")}&plan=free`, async (page) => { await page.getByRole("button", { name: "Transcription key", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-privacy", app("settings"), async (page) => { await page.getByRole("button", { name: "Privacy & data", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-consent", app("settings"), async (page) => { await page.getByRole("button", { name: "Privacy & data", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "Recording consent", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-delete", app("settings"), async (page) => { await page.getByRole("button", { name: "Privacy & data", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "Delete my account", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-lock", app("settings"), async (page) => { await page.getByRole("button", { name: "App lock", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-pin", app("settings"), async (page) => { await page.getByRole("button", { name: "App lock", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "PIN", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-security", app("settings"), async (page) => { await page.getByRole("button", { name: "Sign-in & security", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-activity", app("settings"), async (page) => { await page.getByRole("button", { name: "Sign-in & security", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "Sign-in activity", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-share", app("settings"), async (page) => { await page.getByRole("button", { name: "Screen-share privacy", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-share-mac", app("settings-mac"), async (page) => { await page.getByRole("button", { name: "Screen-share privacy", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-share-check", app("settings-mac"), async (page) => { await page.getByRole("button", { name: "Screen-share privacy", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "Check screen-share privacy", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-notifications", app("settings"), async (page) => { await page.getByRole("button", { name: "Notifications", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-mic", app("settings"), async (page) => { await page.getByRole("button", { name: "Microphone & audio", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-mic-mac", app("settings-mac"), async (page) => { await page.getByRole("button", { name: "Microphone & audio", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-screen", `${app("settings")}&plan=pro`, async (page) => { await page.getByRole("button", { name: "Screen moments", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-excluded", `${app("settings")}&plan=pro`, async (page) => { await page.getByRole("button", { name: "Screen moments", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "Never capture these apps", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-storage", `${app("settings")}&plan=pro`, async (page) => { await page.getByRole("button", { name: "Storage", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-appearance", app("settings"), async (page) => { await page.getByRole("button", { name: "Appearance", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-shortcuts", app("settings"), async (page) => { await page.getByRole("button", { name: "Keyboard shortcuts", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-shortcuts-mac", app("settings-mac"), async (page) => { await page.getByRole("button", { name: "Keyboard shortcuts", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "Change Dictation", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-profile", app("settings"), async (page) => { await page.getByRole("button", { name: "Profile", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-plan-free", `${app("settings")}&plan=free`, async (page) => { await page.getByRole("button", { name: "Plan & usage", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: /^See every feature/ }).click(); await page.waitForTimeout(500); }],
  ["a-set-plan-pro", `${app("settings")}&plan=pro`, async (page) => { await page.getByRole("button", { name: "Plan & usage", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-plan-request", `${app("settings")}&plan=free&request=awaiting_payment`, async (page) => { await page.getByRole("button", { name: "Plan & usage", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-plan-near", `${app("settings")}&plan=pro&near=1`, async (page) => { await page.getByRole("button", { name: "Plan & usage", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-controls", app("controls"), async (page) => { await page.getByRole("button", { name: /Show the controls/ }).click(); await page.waitForTimeout(400); }],
];

/** Runs in the page: controls without a name the screen reader could read, inside the v2 screens. */
function unnamedControls() {
  const roles = "button, a[href], input:not([type=hidden]), select, textarea, [role=button], [role=switch], [role=combobox], [role=tab], [role=menuitem], [role=option], [role=radio], [role=checkbox], [role=slider]";
  const found = [];
  for (const el of document.querySelectorAll(`.el-root ${roles.split(", ").join(", .el-root ")}`)) {
    const r = el.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) continue;
    const labelledby = (el.getAttribute("aria-labelledby") ?? "").split(/\s+/).filter(Boolean).map((id) => document.getElementById(id)?.textContent ?? "").join(" ");
    const labels = el.labels ? [...el.labels].map((l) => l.textContent).join(" ") : "";
    const name = (el.getAttribute("aria-label") || labelledby || labels || el.textContent || el.getAttribute("title") || el.getAttribute("placeholder") || "").trim();
    if (!name) found.push(`${el.tagName.toLowerCase()}.${[...el.classList].slice(0, 2).join(".")}`);
  }
  return found;
}

const server = await createServer({ root: desktop, configFile: join(desktop, "vite.config.ts"), server: { port: 1460, strictPort: false }, logLevel: "error" });
await server.listen();
const base = server.resolvedUrls.local[0];
await mkdir(out, { recursive: true });
const browser = await chromium.launch();
const report = { axe: [], keys: [] };

async function open(path, theme = "light") {
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, colorScheme: theme, reducedMotion: "reduce" });
  await page.goto(`${base}${path}&theme=${theme}`);
  await page.waitForTimeout(path.includes("v2gallery") ? 700 : 2200);
  return page;
}

try {
  if (only !== "keys") {
    for (const [name, path, step] of SCREENS) for (const theme of ["light", "dark"]) {
      const page = await open(path, theme);
      try {
        if (step) await step(page);
      } catch (e) {
        report.axe.push({ name, theme, error: `step failed: ${String(e.message).split("\n")[0]}` });
        await page.close();
        continue;
      }
      await page.addScriptTag({ path: axePath });
      // The controls canvas also shows today's caption overlay (not v2): only the v2 pill and after-call card are checked
      // there; the overlay's own findings are listed apart (classic).
      const context = name === "a-controls" ? { include: [["[data-testid=recording-pill-v2]"], [".el-aftercall-stack"]] } : null;
      const result = await page.evaluate(async (ctx) => {
        // eslint-disable-next-line no-undef
        const r = await axe.run(ctx ?? document, { runOnly: { type: "tag", values: ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa", "best-practice"] } });
        return r.violations.map((v) => ({
          id: v.id, impact: v.impact, help: v.help,
          nodes: v.nodes.map((n) => ({ target: n.target.join(" "), v2: !!document.querySelector(n.target.join(" "))?.closest(".el-root:not(.el-embedded), .el-embedded"), summary: n.failureSummary?.split("\n").slice(0, 2).join(" ") })),
        }));
      }, context);
      if (name === "a-controls") {
        const classic = await page.evaluate(async () => {
          // eslint-disable-next-line no-undef
          const r = await axe.run({ include: [[".overlay-root"], [".overlay-body"]] }, { runOnly: { type: "tag", values: ["wcag2aa"] } });
          return r.violations.map((v) => `${v.id} (${v.impact}): ${v.nodes.map((n) => n.target.join(" ")).join(", ")}`);
        }).catch(() => []);
        report.classic = [...(report.classic ?? []), ...classic.map((c) => `${theme}: ${c}`)];
      }
      const unnamed = await page.evaluate(unnamedControls);
      report.axe.push({ name, theme, violations: result, unnamed });
      await page.close();
    }
  }

  if (only !== "axe") {
    // Keyboard only: Tab (Shift+Tab) to a control by its name, then Enter or Space; every focus stop must show its ring.
    const tabTo = async (page, test, limit = 80) => {
      for (let i = 1; i <= limit; i++) {
        await page.keyboard.press("Tab");
        const hit = await page.evaluate((src) => {
          const el = document.activeElement;
          if (!el || el === document.body) return null;
          const by = (el.getAttribute("aria-labelledby") ?? "").split(/\s+/).filter(Boolean).map((id) => document.getElementById(id)?.textContent ?? "").join(" ");
          const name = (el.getAttribute("aria-label") || by || el.textContent || "").trim();
          // The ring is on the control, or on the field around an input (.el-input draws it for its input).
          const ringOn = (node) => {
            const style = getComputedStyle(node);
            return style.outlineStyle !== "none" && parseFloat(style.outlineWidth) > 0;
          };
          const ring = ringOn(el) || (!!el.closest(".el-input") && ringOn(el.closest(".el-input")));
          return new RegExp(src).test(name) ? { name, ring, presses: 0 } : null;
        }, test.source);
        if (hit) return { ...hit, presses: i };
      }
      throw new Error(`no focus stop matched ${test} in ${limit} presses`);
    };
    const calls = (page) => page.evaluate(() => window.__calls ?? []);
    const spy = (page) => page.evaluate(() => {
      const internals = window.__TAURI_INTERNALS__;
      const orig = internals.invoke.bind(internals);
      window.__calls = [];
      internals.invoke = (cmd, args, opts) => { window.__calls.push(cmd); return orig(cmd, args, opts); };
    });
    const walk = async (name, path, steps) => {
      const page = await open(path);
      await spy(page);
      const log = [];
      try {
        await steps(page, async (re, key = "Enter") => {
          const stop = await tabTo(page, re);
          log.push(`${stop.presses} × Tab → "${stop.name}"${stop.ring ? "" : " (NO FOCUS RING)"}${key ? `, ${key}` : ""}`);
          if (key) await page.keyboard.press(key);
          await page.waitForTimeout(500);
          return stop;
        }, log);
        report.keys.push({ name, ok: true, log, calls: await calls(page) });
      } catch (e) {
        report.keys.push({ name, ok: false, log, error: String(e.message).split("\n")[0], calls: await calls(page) });
      }
      await page.screenshot({ path: join(out, `keys-${name}.png`) });
      await page.close();
    };

    await walk("record", app("library"), async (page, go, log) => {
      await go(/^Home$/);
      await go(/^Record now$/);
      if (!(await calls(page)).includes("record_now")) throw new Error("record_now was not sent");
      log.push("record_now sent");
    });
    await walk("find", app("library"), async (page, go, log) => {
      await go(/^Search recordings$/, null);
      await page.keyboard.type("Contract");
      await page.waitForTimeout(300);
      await go(/^Contract review$/);
      await page.waitForTimeout(1200);
      const h1 = await page.locator("[data-v2=library] h1").first().textContent();
      if (h1 !== "Contract review") throw new Error(`opened "${h1}"`);
      log.push(`search, the row, Enter → "${h1}"`);
    });
    await walk("transcribe", app("library"), async (page, go, log) => {
      await go(/^Untranscribed/);
      await go(/^Call with/);
      log.push(`opened "${await page.locator("[data-v2=library] h1").first().textContent()}"`);
      await go(/^(Transcribe|Try again)$/);
      if (!(await calls(page)).includes("session_retranscribe")) throw new Error("session_retranscribe was not sent");
      log.push("session_retranscribe sent");
    });
    await walk("delete-undo", app("library"), async (page, go, log) => {
      await go(/^Weekly sync with Northwind$/);
      await page.waitForTimeout(1200);
      await go(/^Delete$/);
      if (!(await calls(page)).includes("delete_session")) throw new Error("delete_session was not sent");
      await go(/^Undo$/);
      if (!(await calls(page)).includes("session_restore")) throw new Error("session_restore was not sent");
      await page.waitForTimeout(600);
      const focused = await page.evaluate(() => {
        const el = document.activeElement;
        const by = el?.getAttribute("aria-labelledby");
        return by ? document.getElementById(by)?.textContent : el?.tagName;
      });
      if (focused !== "Weekly sync with Northwind") throw new Error(`after Undo the focus was on ${focused}`);
      log.push(`focus back on "${focused}"`);
      log.push("delete_session, then Undo → session_restore");
    });
    await walk("undo-keys", app("library"), async (page, go, log) => {
      await go(/^Weekly sync with Northwind$/);
      await page.waitForTimeout(1200);
      await go(/^Delete$/);
      await page.waitForTimeout(400);
      const named = () => page.evaluate(() => {
        const el = document.activeElement;
        const by = el?.getAttribute("aria-labelledby");
        return (by ? document.getElementById(by)?.textContent : el?.getAttribute("aria-label") || el?.textContent?.trim()) ?? el?.tagName;
      });
      const from = await named();
      await page.keyboard.press("F6");
      if ((await named()) !== "Undo") throw new Error(`F6 put focus on ${await named()}`);
      log.push(`F6 from "${from}" → "Undo"`);
      await page.keyboard.press("F6");
      if ((await named()) !== from) throw new Error(`F6 again put focus on ${await named()}`);
      log.push(`F6 again → back on "${from}"`);
      await page.keyboard.press("ControlOrMeta+z");
      await page.waitForTimeout(600);
      if (!(await calls(page)).includes("session_restore")) throw new Error("Cmd/Ctrl+Z didn't send session_restore");
      log.push(`Cmd/Ctrl+Z → session_restore; focus stays on "${await named()}"`);
    });
    await walk("setting", app("settings"), async (page, go, log) => {
      await go(/^Search settings$/, null);
      await page.keyboard.type("live captions");
      await page.waitForTimeout(400);
      await go(/^Live captions while recording$/);
      await page.waitForTimeout(800);
      const sw = await go(/^Live captions while recording$/, "Space");
      if (!(await calls(page)).includes("update_settings")) throw new Error(`update_settings was not sent (focus on "${sw.name}")`);
      log.push("update_settings sent");
    });
  }
} finally {
  await browser.close();
  await server.close();
}
await writeFile(join(out, `report${only ? `-${only}` : ""}.json`), `${JSON.stringify(report, null, 1)}\n`);
const serious = report.axe.flatMap((r) => (r.violations ?? []).filter((v) => v.impact === "serious" || v.impact === "critical").map((v) => `${r.name}/${r.theme}: ${v.id} (${v.impact}) ×${v.nodes.length}`));
const other = report.axe.flatMap((r) => (r.violations ?? []).filter((v) => v.impact !== "serious" && v.impact !== "critical").map((v) => `${r.name}/${r.theme}: ${v.id} (${v.impact}) ×${v.nodes.length}`));
console.log(`axe: ${report.axe.length} pages, ${serious.length} serious or critical, ${other.length} moderate or minor`);
serious.forEach((s) => console.log("  !", s));
other.forEach((s) => console.log("  ·", s));
for (const r of report.axe) if (r.error) console.log("  step:", r.name, r.theme, r.error);
for (const r of report.axe) if (r.unnamed?.length) console.log("  unnamed:", r.name, r.theme, r.unnamed.join(", "));
for (const c of report.classic ?? []) console.log("  classic overlay:", c);
for (const k of report.keys) console.log(`keys ${k.name}: ${k.ok ? "ok" : `FAILED (${k.error})`}\n    ${k.log.join("\n    ")}`);
