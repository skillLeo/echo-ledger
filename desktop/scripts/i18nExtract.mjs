// The desktop's words, counted before any screen is converted (desktop Urdu, Phase 0). It reads the TypeScript
// sources (no test, no gallery) with the compiler's own parser and lists every piece of text a person can see:
//   - text between JSX tags;
//   - the props that are words (aria-label, title, placeholder, label, meta, hint, body…);
//   - other string and template literals that read as words ("Couldn't sync", `Clear ${name}`), not ids, keys, class
//     names, URLs, event names or comparisons (e.key === "Escape").
// A template's values become Laravel's placeholders (`Clear ${name}` → "Clear :name"), as the catalogue's keys will be,
// so each string can be looked up in the web's lang/ur.json (the same English as the key, as the web's __()): "seeded"
// when it is there as it is, "seeded, other names" when only the placeholders' names differ.
// It counts per area (src/v2/<folder>, the classic palette the new design uses, the rest of classic, src/lib), and how
// many are still raw (not given to t() or tc(); src/i18n/guard.test.ts refuses raw text in a converted area),
// and, roughly, the Rust core's user-facing sentences (tray, notifications, error words). A heuristic: it says how big
// the work is, not the catalogue itself.
//   node scripts/i18nExtract.mjs [--json=FILE] [--list=AREA] [--seed | --seed=web]
import { readFileSync, readdirSync, statSync, writeFileSync } from "node:fs";
import { dirname, join, relative, sep } from "node:path";
import { fileURLToPath } from "node:url";
import { textsIn } from "../src/i18n/rawText.ts";
import { DESKTOP_OVERRIDES } from "../src/i18n/overrides.ts";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const src = join(desktop, "src");
const arg = (name) => process.argv.find((a) => a.startsWith(`--${name}=`))?.split("=").slice(1).join("=");
const web = JSON.parse(readFileSync(join(desktop, "..", "web", "lang", "ur.json"), "utf8"));
/** A key with its placeholders' names left out: ":count recording|:count recordings" → ":_ recording|:_ recordings". */
const shape = (s) => s.replace(/:[A-Za-z_][A-Za-z0-9_]*/g, ":_");
const webShapes = new Set(Object.keys(web).map(shape));

/**
 * The classic pieces the new design still shows (EL-UX's sizing): the palette; the confirm window (ConfirmDialog, in
 * SettingsControls.tsx with classic Settings' rows, so counted with classic) and the sign-out text (signOutBody, in
 * SettingsView.tsx; its title and button are in MainApp.tsx).
 */
const CLASSIC_IN_V2 = ["views/main/CommandPalette.tsx"];
function areaOf(rel) {
  const p = rel.split(sep).join("/");
  if (CLASSIC_IN_V2.includes(p)) return `classic used by v2 (${p.split("/").pop()})`;
  if (p.startsWith("v2/")) return `v2/${p.split("/")[1].replace(/\.tsx?$/, "")}`;
  if (p.startsWith("views/")) return "classic (stays English)";
  if (p.startsWith("lib/")) return "lib";
  return p.split("/")[0];
}

/** The app's sources: no test, story or type file, no gallery (left out of release builds), no i18n itself. */
function walkFiles(dir) {
  const out = [];
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) {
      if (["gallery", "test", "__tests__", "node_modules", "i18n"].includes(name)) continue;
      out.push(...walkFiles(path));
    } else if (/\.(tsx?|mts)$/.test(name) && !/\.(test|spec|stories)\.tsx?$/.test(name) && !name.endsWith(".d.ts")) out.push(path);
  }
  return out;
}

const found = []; // { area, file, line, kind, text, translated }
for (const file of walkFiles(src)) {
  const rel = relative(src, file);
  for (const f of textsIn(file, readFileSync(file, "utf8"))) found.push({ area: areaOf(rel), file: rel, ...f });
}

// The Rust core's sentences people see (roughly): the tray, notifications, the error words, help, after a call.
const rustDir = join(desktop, "src-tauri", "src");
const rust = { files: 0, strings: 0 };
const RUST_SHOWN = /(tray|notif|error|help|after_call|menu|refus|plan|shar|dialog|onboard|ready)/i;
for (const file of walkFiles2(rustDir)) {
  if (!RUST_SHOWN.test(file)) continue;
  const code = readFileSync(file, "utf8").replace(/#\[cfg\(test\)\][\s\S]*$/m, "");
  const hits = [...code.matchAll(/"((?:[^"\\]|\\.){3,})"/g)].map((m) => m[1]).filter((s) => /^[A-Z][a-z]/.test(s) && /\s/.test(s));
  if (hits.length) {
    rust.files++;
    rust.strings += hits.length;
  }
}
function walkFiles2(dir) {
  const out = [];
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) out.push(...walkFiles2(path));
    else if (name.endsWith(".rs")) out.push(path);
  }
  return out;
}

// Per area: occurrences, unique strings, and how many the web's ur.json has already.
const areas = new Map();
for (const f of found) {
  const a = areas.get(f.area) ?? { area: f.area, files: new Set(), occurrences: 0, unique: new Map(), raw: 0 };
  if (!f.translated) a.raw++;
  a.files.add(f.file);
  a.occurrences++;
  a.unique.set(f.text, (a.unique.get(f.text) ?? 0) + 1);
  areas.set(f.area, a);
}
const seededAs = (text) => (web[text] !== undefined ? "seeded" : webShapes.has(shape(text)) ? "other names" : null);
const rowsOf = () => [...areas.values()].map((a) => {
  const texts = [...a.unique.keys()];
  return {
    area: a.area, files: a.files.size, occurrences: a.occurrences, unique: texts.length,
    seeded: texts.filter((t) => seededAs(t) === "seeded").length, otherNames: texts.filter((t) => seededAs(t) === "other names").length,
    withValues: texts.filter((t) => /:[A-Za-z_]/.test(t)).length, raw: a.raw ?? a.occurrences,
  };
}).sort((x, y) => y.unique - x.unique);

// The menus' words (actions.json: each label and its other state's words), shown by the menu bar, Record's menu and
// the right-click menus.
const actions = JSON.parse(readFileSync(join(src, "v2", "actions", "actions.json"), "utf8")).actions;
for (const a of actions) {
  for (const text of [a.label, a.state?.on].filter(Boolean)) found.push({ area: "v2/actions (actions.json)", file: "v2/actions/actions.json", line: 0, kind: "menu", text });
}
{
  const a = { area: "v2/actions (actions.json)", files: new Set(["v2/actions/actions.json"]), occurrences: 0, unique: new Map() };
  for (const f of found.filter((x) => x.area === a.area)) {
    a.occurrences++;
    a.unique.set(f.text, (a.unique.get(f.text) ?? 0) + 1);
  }
  areas.set(a.area, a);
}
const all = new Set(found.map((f) => f.text));
const rows = rowsOf();
const inV2 = (f) => f.area.startsWith("v2/") || f.area.startsWith("classic used by v2");
const v2Texts = new Set(found.filter(inV2).map((f) => f.text));
const libTexts = new Set(found.filter((f) => f.area === "lib").map((f) => f.text));
const phase = new Set([...v2Texts, ...libTexts]);
const pad = (s, n) => String(s).padEnd(n);
const padL = (s, n) => String(s).padStart(n);
console.log(`${pad("area", 44)}${padL("files", 6)}${padL("found", 7)}${padL("unique", 8)}${padL("seeded", 8)}${padL("names≠", 8)}${padL("values", 8)}${padL("raw", 7)}`);
for (const r of rows) console.log(`${pad(r.area, 44)}${padL(r.files, 6)}${padL(r.occurrences, 7)}${padL(r.unique, 8)}${padL(r.seeded, 8)}${padL(r.otherNames, 8)}${padL(r.withValues, 8)}${padL(r.raw, 7)}`);
const count = (set) => {
  const texts = [...set];
  return { unique: texts.length, seeded: texts.filter((t) => seededAs(t) === "seeded").length, otherNames: texts.filter((t) => seededAs(t) === "other names").length };
};
const v2 = count(v2Texts);
const withLib = count(phase);
const everything = count(all);
console.log(`\nThe new design (src/v2 and the three classic pieces): ${v2.unique} unique strings, ${v2.seeded} already in the web's ur.json as they are, ${v2.otherNames} with other placeholder names.`);
console.log(`With src/lib (notices, error words, formats): ${withLib.unique} unique, ${withLib.seeded} seeded, ${withLib.otherNames} with other names.`);
console.log(`Everything in src, classic included: ${everything.unique} unique, ${everything.seeded} seeded.`);
console.log(`The Rust core (tray, notifications, errors, help, menus…), roughly: ${rust.strings} sentences in ${rust.files} files.`);

const list = arg("list");
if (list) {
  console.log(`\n${list}:`);
  for (const f of found.filter((x) => x.area === list)) console.log(`${f.file}:${f.line} ${f.kind}: ${f.text}${seededAs(f.text) ? ` [${seededAs(f.text)}]` : ""}`);
}
// --seed: src/i18n/ur.json, the Urdu of every string of the new design, its palette, src/lib and src/components that
// the web's lang/ur.json already has as it is (the same English key, the web's Urdu), sorted. Kept entries stay (a
// translation made for the desktop is never dropped); the web's wins where both have one only when --seed=web, and never
// over a named desktop override (src/i18n/overrides.ts).
if (process.argv.some((a) => a === "--seed" || a.startsWith("--seed="))) {
  const file = join(src, "i18n", "ur.json");
  let kept = {};
  try {
    kept = JSON.parse(readFileSync(file, "utf8"));
  } catch {
    // None yet.
  }
  const webWins = arg("seed") === "web";
  const shown = found.filter((f) => f.area !== "classic (stays English)" && f.area !== "main.tsx");
  const out = { ...kept };
  let added = 0;
  for (const { text } of shown) {
    if (web[text] === undefined || (out[text] !== undefined && (!webWins || text in DESKTOP_OVERRIDES))) continue;
    if (out[text] === undefined) added++;
    out[text] = web[text];
  }
  const sorted = Object.fromEntries(Object.keys(out).sort((x, y) => (x < y ? -1 : x > y ? 1 : 0)).map((k) => [k, out[k]]));
  writeFileSync(file, `${JSON.stringify(sorted, null, 2)}\n`);
  console.log(`\nsrc/i18n/ur.json: ${Object.keys(sorted).length} entries (${added} added from the web's lang/ur.json).`);
}
const json = arg("json");
if (json) {
  writeFileSync(json, JSON.stringify({ areas: rows, strings: [...all].sort().map((text) => ({ text, seeded: seededAs(text), at: found.filter((f) => f.text === text).map((f) => `${f.file}:${f.line}`) })) }, null, 2));
}
