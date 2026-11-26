// Cross-checks the TypeScript IPC layer (lib/ipc.ts) against the Rust sources it talks to, so a renamed command,
// argument, enum value or struct field on either side fails here instead of at runtime inside the webview.
import { describe, expect, it } from "vitest";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";
import ipcTs from "./ipc.ts?raw";
import { MAINTENANCE_TEXT, UPDATE_REQUIRED_TEXT } from "./ipc";
import { AWAITING_CORE, awaitedNames, type AwaitedCommand } from "./awaitingCore";
import settingsViewTsx from "../views/main/SettingsView.tsx?raw";
import recordViewTsx from "../views/main/RecordView.tsx?raw";
import screenSettingsTsx from "../views/main/ScreenSettings.tsx?raw";
import captureSettingsTsx from "../views/main/CaptureSettings.tsx?raw";
import dictationSettingsTsx from "../views/main/DictationSettings.tsx?raw";
import securitySettingsTsx from "../views/main/SecuritySettings.tsx?raw";
import appSettingsTsx from "../views/main/AppSettings.tsx?raw";
import accountSettingsTsx from "../views/main/AccountSettings.tsx?raw";
import commandsRs from "../../src-tauri/src/commands.rs?raw";
import libRs from "../../src-tauri/src/lib.rs?raw";
import errorRs from "../../src-tauri/src/error.rs?raw";
import serverNoticeRs from "../../src-tauri/src/server_notice.rs?raw";
import settingsRs from "../../src-tauri/src/settings.rs?raw";
import audioRs from "../../src-tauri/src/audio/mod.rs?raw";
import audioTypesRs from "../../src-tauri/src/audio/types.rs?raw";
import detectRs from "../../src-tauri/src/detect/mod.rs?raw";
import geometryRs from "../../src-tauri/src/overlay/geometry.rs?raw";
import shortcutsRs from "../../src-tauri/src/shortcuts.rs?raw";
import { SHORTCUT_ACTIONS } from "./settingsRegistry";
import { UI_FLAG_NAMES } from "../v2/flags";
import modelsRs from "../../src-tauri/src/net/models.rs?raw";
import captionsRs from "../../src-tauri/src/stt/captions.rs?raw";

/**
 * Every file under `dir` matching `keep`, read from disk when the test runs, keyed by its path relative to this file
 * ("../../src-tauri/src/net/api.rs", "../v2/live/LiveV2.tsx", "./ipc.ts"). Not import.meta.glob: Vitest keeps this test
 * module's transform in its module cache by the test file's own content, so a glob's list of files is the one from the
 * last run before a file was added, and a new command (title_click.rs, native_menu.rs) went unseen.
 */
const here = dirname(fileURLToPath(import.meta.url));
function readTree(dir: string, keep: (path: string) => boolean): Record<string, string> {
  // Sorted, so a lookup that takes the first match finds the same file on every system.
  const walk = (at: string): string[] => readdirSync(at).sort().flatMap((name) => {
    const path = join(at, name);
    return statSync(path).isDirectory() ? walk(path) : [path];
  });
  return Object.fromEntries(walk(dir).filter(keep).map((path) => {
    const rel = relative(here, path).split("\\").join("/");
    return [rel.startsWith(".") ? rel : `./${rel}`, readFileSync(path, "utf8")];
  }));
}

// Every Rust source, for types whose module may move: a struct or enum is looked up by name across all files.
const allRustSources = readTree(join(here, "../../src-tauri/src"), (path) => path.endsWith(".rs"));
// An empty or moved folder must not pass in silence: two sources only a full walk finds.
for (const known of ["../../src-tauri/src/title_click.rs", "../../src-tauri/src/native_menu.rs", "../../src-tauri/src/net/models.rs"]) {
  if (!(known in allRustSources)) throw new Error(`the Rust sources were not all read: ${known} is missing`);
}

/** The Rust source declaring `pub struct|enum <name>` (fails with the name when no file declares it). */
function rustSourceDeclaring(kind: "struct" | "enum", name: string): string {
  const re = new RegExp(`pub ${kind} ${name}\\b`);
  const found = Object.values(allRustSources).find((source) => re.test(source));
  if (!found) throw new Error(`no Rust source declares pub ${kind} ${name}`);
  return found;
}

const camel = (snake: string) => snake.replace(/_([a-z0-9])/g, (_m, c: string) => c.toUpperCase());
/** serde's `rename_all = "snake_case"` for a variant name. */
const serdeSnake = (variant: string) => variant.replace(/[A-Z]/g, (c, i: number) => (i > 0 ? "_" : "") + c.toLowerCase());

/** Splits on commas that are not nested inside <>, (), [] or {}. */
function splitTopLevel(text: string): string[] {
  const parts: string[] = [];
  let depth = 0;
  let current = "";
  for (const ch of text) {
    if ("<([{".includes(ch)) depth++;
    if (">)]}".includes(ch)) depth--;
    if (ch === "," && depth === 0) {
      parts.push(current);
      current = "";
    } else current += ch;
  }
  if (current.trim()) parts.push(current);
  return parts.map((p) => p.trim()).filter(Boolean);
}

/** Text between the first `open` at/after `from` and its matching `close`. */
function balanced(source: string, from: number, open: string, close: string): string {
  const start = source.indexOf(open, from);
  let depth = 0;
  for (let i = start; i < source.length; i++) {
    if (source[i] === open) depth++;
    if (source[i] === close && --depth === 0) return source.slice(start + 1, i);
  }
  throw new Error(`Unbalanced ${open}${close} after offset ${from}`);
}

interface RustCommand {
  name: string;
  /** Where it is declared ("commands", "net::api"); generate_handler! names it by that path. */
  module: string;
  /** Arguments the webview must send (State / AppHandle / Window are injected by Tauri). */
  args: { name: string; optional: boolean }[];
}

/** The module a Rust source file is: "src-tauri/src/net/api.rs" → "net::api", "…/audio/mod.rs" → "audio". */
function moduleOf(path: string): string {
  const rel = path.replace(/^.*src-tauri\/src\//, "").replace(/\.rs$/, "").replace(/\/mod$/, "");
  return rel === "lib" || rel === "main" ? "" : rel.split("/").join("::");
}

/** Every `#[tauri::command]` in the core, whichever file declares it, with its module. */
function rustCommands(): Map<string, RustCommand> {
  const found = new Map<string, RustCommand>();
  const re = /#\[tauri::command(?:\([^)]*\))?\]\s*pub\s+(?:async\s+)?fn\s+(\w+)\s*(?:<[^>]*>)?\s*\(/g;
  for (const [path, source] of Object.entries(allRustSources)) {
    for (const match of source.matchAll(re)) {
      const params = splitTopLevel(balanced(source, match.index! + match[0].length - 1, "(", ")"));
      const args = params
        .map((p) => {
          const [name, ...type] = p.split(":");
          return { name: name.trim(), type: type.join(":").trim() };
        })
        .filter((p) => !/^(AppHandle|State\b|WebviewWindow|Window)/.test(p.type))
        .map((p) => ({ name: camel(p.name), optional: p.type.startsWith("Option<") }));
      found.set(match[1], { name: match[1], module: moduleOf(path), args });
    }
  }
  return found;
}

/** The paths listed in `generate_handler![…]`: "commands::app_status", "screens::capture". */
function handlerPaths(): string[] {
  const at = libRs.indexOf("generate_handler![");
  if (at < 0) throw new Error("lib.rs has no generate_handler!");
  return splitTopLevel(balanced(libRs, at, "[", "]")).map((p) => p.replace(/^crate::/, "").trim());
}

interface TsInvocation {
  command: string;
  keys: string[];
}

function tsInvocations(): TsInvocation[] {
  const re = /invoke(?:<[\s\S]*?>)?\(\s*"([a-z_]+)"\s*(?:,\s*\{([^}]*)\})?\s*\)/g;
  return [...ipcTs.matchAll(re)].map((m) => ({
    command: m[1],
    keys: m[2] ? splitTopLevel(m[2]).map((part) => /^\s*(\w+)/.exec(part)![1]) : [],
  }));
}

/** Field names of a Rust struct, camelCased unless the struct is serialised without `rename_all`. */
function rustFields(source: string, struct: string, camelCase = true): string[] {
  const at = source.search(new RegExp(`pub struct ${struct}\\b`));
  if (at < 0) throw new Error(`struct ${struct} not found`);
  const body = balanced(source, at, "{", "}");
  return [...body.matchAll(/^\s*(?:pub\s+)?(\w+)\s*:/gm)].map((m) => (camelCase ? camel(m[1]) : m[1]));
}

/** Variant names (serde snake_case) of a plain Rust enum. */
function rustEnumValues(source: string, name: string): string[] {
  const at = source.search(new RegExp(`pub enum ${name}\\b`));
  if (at < 0) throw new Error(`enum ${name} not found`);
  const body = balanced(source, at, "{", "}")
    .replace(/\/\/\/.*$/gm, "")
    .replace(/#\[[^\]]*\]/g, "");
  return splitTopLevel(body).map((v) => serdeSnake(v.trim()));
}

/** Keys of a TypeScript interface body. */
function tsInterfaceKeys(name: string): string[] {
  const at = ipcTs.search(new RegExp(`export interface ${name}\\b`));
  if (at < 0) throw new Error(`interface ${name} not found`);
  const body = balanced(ipcTs, at, "{", "}");
  return [...body.matchAll(/^ {2}(\w+)\??\s*:/gm)].map((m) => m[1]);
}

/** String literals of a TypeScript union alias (`type X = "a" | "b"`). */
function tsUnionValues(name: string): string[] {
  const m = new RegExp(`export type ${name} =([^;]+);`).exec(ipcTs);
  if (!m) throw new Error(`type ${name} not found`);
  return [...m[1].matchAll(/"([^"]+)"/g)].map((x) => x[1]);
}

/** Literal values of an inline union on an interface field, e.g. `mode: "call" | "voice_note"`. */
function tsFieldUnion(field: string): string[] {
  const m = new RegExp(`\\b${field}: ((?:"[a-z_]+"\\s*\\|?\\s*)+);`).exec(ipcTs);
  if (!m) throw new Error(`field ${field} not found`);
  return [...m[1].matchAll(/"([^"]+)"/g)].map((x) => x[1]);
}

const sorted = (values: string[]) => [...values].sort();

// Every app source (not the tests), by its path under src/.
const appSources = Object.fromEntries(
  Object.entries(readTree(join(here, ".."), (path) => /\.tsx?$/.test(path)))
    .filter(([path]) => !/\.test\.tsx?$/.test(path) && !path.includes("/test/"))
    // Globbed from src/lib: "../v2/live/LiveV2.tsx" and "./ipc.ts" become "v2/live/LiveV2.tsx" and "lib/ipc.ts".
    .map(([path, text]) => [path.startsWith("./") ? `lib/${path.slice(2)}` : path.replace(/^\.\.\//, ""), text]),
);
// An empty or moved folder must not pass in silence.
for (const known of ["views/main/MainApp.tsx", "lib/ipc.ts", "v2/shell/NavShell.tsx"]) {
  if (!(known in appSources)) throw new Error(`the app's sources were not all read: ${known} is missing`);
}

/** The api wrappers in ipc.ts that invoke `command` ("liveTranslateStatus" for live_translate_status). */
function wrappersOf(command: string, ipc: string = ipcTs): string[] {
  return [...ipc.matchAll(new RegExp(`^\\s*(\\w+):[^\\n]*?invoke(?:<[^\\n]*?>)?\\(\\s*"${command}"`, "gm"))].map((m) => m[1]);
}

/** Source files that call an awaited command (its name in an invoke, or one of its wrappers) but aren't its callers. */
export function strayCalls(sources: Record<string, string>, awaited: readonly AwaitedCommand[], ipc: string = ipcTs): string[] {
  const stray: string[] = [];
  for (const c of awaited) {
    const wrappers = wrappersOf(c.name, ipc);
    const callsIt = (text: string) => new RegExp(`invoke(?:<[^>]*>)?\\(\\s*["'\`]${c.name}["'\`]`).test(text)
      || wrappers.some((w) => new RegExp(`\\.${w}\\s*\\(`).test(text));
    for (const [path, text] of Object.entries(sources)) if (callsIt(text) && !c.callers.includes(path)) stray.push(`${c.name} in ${path}`);
  }
  return stray;
}

/** Source files other than lib/ipc.ts that call Tauri's invoke, or import it to call it (aliased or not). */
export function invokeOutsideIpc(sources: Record<string, string>): string[] {
  const calls = /(^|[^\w.])invoke\s*(<[^>]*>)?\s*\(/m;
  const imports = /import\s*\{[^}]*\binvoke\b[^}]*\}\s*from\s*["']@tauri-apps\/api\/core["']/;
  const namespace = /import\s*\*\s*as\s+\w+\s+from\s*["']@tauri-apps\/api\/core["']/;
  return Object.entries(sources)
    .filter(([path, text]) => path !== "lib/ipc.ts" && (calls.test(text) || imports.test(text) || namespace.test(text)))
    .map(([path]) => path).sort();
}

describe("every command goes through lib/ipc.ts", () => {
  // So each one is held to the core's commands and arguments, and to the awaited-list rules, by the tests here.
  it("no file under src/ but lib/ipc.ts calls invoke(", () => {
    expect(invokeOutsideIpc(appSources)).toEqual([]);
  });

  it("finds a call, a typed call, an import of it and the module imported whole; a wrapper's name is not a call", () => {
    expect(invokeOutsideIpc({
      "lib/ipc.ts": 'invoke<string>("x")',
      "a.ts": 'await invoke("app_status");',
      "b.tsx": 'const s = await invoke<Settings>("get_settings");',
      "c.ts": 'import { invoke as call } from "@tauri-apps/api/core";',
      "d.ts": 'import * as core from "@tauri-apps/api/core";',
      "e.ts": "api.invokeLater(); x.invoke(); invokeAll();",
    })).toEqual(["a.ts", "b.tsx", "c.ts", "d.ts"]);
  });
});

describe("commands awaited from the core are called only where their fallback says", () => {
  it("finds the app's sources and each awaited command's wrappers", () => {
    expect(Object.keys(appSources)).toContain("lib/ipc.ts");
    expect(Object.keys(appSources)).toContain("v2/live/LiveV2.tsx");
    expect(wrappersOf("live_translate_status")).toEqual(["liveTranslateStatus"]);
  });

  // With the list empty (as after core batches 3 and 4) this holds trivially; the next test keeps the rule itself checked.
  it("no awaited command is called outside its listed callers", () => {
    expect(strayCalls(appSources, AWAITING_CORE.commands)).toEqual([]);
  });

  it("a stray call fails: by its wrapper, or by invoking its name", () => {
    const listed = [{ name: "live_translate_status", fallback: "safe", callers: ["lib/ipc.ts", "v2/live/LiveV2.tsx"] },
      { name: "diagnostic_report_copy", fallback: "called only from its wrapper", callers: ["lib/ipc.ts"] }];
    const sources = {
      "lib/ipc.ts": appSources["lib/ipc.ts"],
      "v2/live/LiveV2.tsx": "api.liveTranslateStatus().then(set);",
      "v2/home/HomeV2.tsx": "void api.liveTranslateStatus();",
      "v2/setup/SetupHealth.tsx": 'await invoke<string>("diagnostic_report_copy");',
    };
    expect(strayCalls(sources, listed)).toEqual(["live_translate_status in v2/home/HomeV2.tsx", "diagnostic_report_copy in v2/setup/SetupHealth.tsx"]);
  });
});

describe("command names and arguments", () => {
  const commands = rustCommands();

  it("reads commands from every core source, and generate_handler! by module path", () => {
    expect(moduleOf("../../src-tauri/src/commands.rs")).toBe("commands");
    expect(moduleOf("../../src-tauri/src/net/api.rs")).toBe("net::api");
    expect(moduleOf("../../src-tauri/src/audio/mod.rs")).toBe("audio");
    expect(handlerPaths()).toContain("commands::app_status");
    // Every registered path names a command the core declares, in the module it is declared in.
    const declared = new Set([...commands.values()].map((c) => (c.module ? `${c.module}::${c.name}` : c.name)));
    expect(handlerPaths().filter((p) => !declared.has(p))).toEqual([]);
  });
  const calls = tsInvocations();

  it("finds every wrapper in ipc.ts and every command in commands.rs", () => {
    expect(calls.length).toBeGreaterThanOrEqual(25);
    expect(commands.size).toBeGreaterThanOrEqual(25);
  });

  it.each(calls.map((c) => [c.command, c] as const))("%s exists in Rust and is registered in the invoke handler", (name) => {
    if (awaitedNames().includes(name)) {
      // Fails the day the core has it: take it off AWAITING_CORE, and the checks below then hold it to Rust's arguments.
      expect(commands.has(name), `${name} is in the core now: remove it from AWAITING_CORE (lib/awaitingCore.ts)`).toBe(false);
      return;
    }
    const rust = commands.get(name);
    expect(rust, `the core has no #[tauri::command] ${name}`).toBeDefined();
    const path = rust!.module ? `${rust!.module}::${name}` : name;
    expect(handlerPaths(), `${path} missing from generate_handler!`).toContain(path);
  });

  it.each(calls.map((c) => [c.command, c] as const))("%s sends exactly the arguments Rust declares", (name, call) => {
    const rust = commands.get(name);
    if (!rust) return; // reported by the existence test above
    const allowed = rust.args.map((a) => a.name);
    const required = rust.args.filter((a) => !a.optional).map((a) => a.name);
    for (const key of call.keys) expect(allowed, `ipc.ts sends "${key}" but ${name}(…) takes ${allowed.join(", ") || "nothing"}`).toContain(key);
    for (const key of required) expect(call.keys, `${name}(…) requires "${key}" which ipc.ts does not send`).toContain(key);
  });

  it("wraps every command that the UI needs (only shortcut_defaults is intentionally unused)", () => {
    const wrapped = new Set(calls.map((c) => c.command));
    const unwrapped = [...commands.keys()].filter((c) => !wrapped.has(c));
    expect(unwrapped).toEqual(["shortcut_defaults"]);
  });
});

describe("wire types", () => {
  const settingsPatchKeys = rustFields(commandsRs, "SettingsPatch");

  it("sends toasts only to pages and Account sections the window has (v1.6.1 Notices)", () => {
    const actionsRs = allRustSources["../../src-tauri/src/actions.rs"];
    const pages = [...actionsRs.matchAll(/Target::Page\("(\w+)"\)/g)].map((m) => m[1]);
    const sections = [...actionsRs.matchAll(/Target::Account\("([\w-]+)"\)/g)].map((m) => m[1]);
    expect(pages.length).toBeGreaterThan(0);
    for (const page of pages) expect(["library", "projects", "record", "activity", "shared", "settings"], page).toContain(page);
    for (const section of sections) expect(settingsViewTsx, section).toMatch(new RegExp(`(\\b${section}: "|id: "${section}")`));
  });

  it("says a held request in the core's own words (error.rs, v1.6 P2.7)", () => {
    expect(errorRs).toContain(`pub const MAINTENANCE_TEXT: &str = "${MAINTENANCE_TEXT}";`);
    expect(errorRs).toContain(`pub const UPDATE_REQUIRED_TEXT: &str = "${UPDATE_REQUIRED_TEXT}";`);
  });

  it("ServerNotices fields match the Rust struct", () => {
    expect(sorted(tsInterfaceKeys("ServerNotices"))).toEqual(sorted(rustFields(serverNoticeRs, "ServerNotices")));
  });

  it("AppStatus fields match the Rust struct", () => {
    expect(sorted(tsInterfaceKeys("AppStatus"))).toEqual(sorted(rustFields(commandsRs, "AppStatus")));
  });

  it("Settings fields match the Rust struct", () => {
    expect(sorted(tsInterfaceKeys("Settings"))).toEqual(sorted(rustFields(settingsRs, "Settings")));
  });

  it("RecordingInfo fields, mode and backend values match Rust", () => {
    const audio = audioRs;
    expect(sorted(tsInterfaceKeys("RecordingInfo"))).toEqual(sorted(rustFields(audio, "RecordingInfo")));
    expect(sorted(tsFieldUnion("mode"))).toEqual(sorted(rustEnumValues(audio, "RecordingMode")));
    expect(sorted(tsFieldUnion("backend"))).toEqual(sorted(rustEnumValues(audioTypesRs, "BackendKind")));
  });

  it("MicUser and RectPx fields match Rust", () => {
    expect(sorted(tsInterfaceKeys("MicUser"))).toEqual(sorted(rustFields(detectRs, "MicUser")));
    expect(sorted(tsInterfaceKeys("RectPx"))).toEqual(sorted(rustFields(geometryRs, "RectPx")));
  });

  it("shortcut actions and overlay corners match the serde names of the Rust enums", () => {
    expect(sorted(tsUnionValues("ShortcutAction"))).toEqual(sorted(rustEnumValues(shortcutsRs, "Action")));
    expect(sorted(tsUnionValues("Corner"))).toEqual(sorted(rustEnumValues(geometryRs, "Corner")));
  });

  it("transcript segments carry the fields the Rust DTO serialises", () => {
    const dto = rustFields(modelsRs, "SegmentDto", false);
    for (const key of tsInterfaceKeys("Segment")) expect(dto).toContain(key);
  });

  it("caption events use the camelCase fields and kinds Rust emits", () => {
    const rust = captionsRs;
    const enumBody = balanced(rust, rust.search(/pub enum CaptionEvent/), "{", "}");
    const rustVariants = new Map<string, string[]>();
    for (const part of splitTopLevel(enumBody)) {
      const m = /^(\w+)\s*(?:\{([\s\S]*)\})?$/.exec(part.trim());
      if (!m) continue;
      const fields = m[2] ? splitTopLevel(m[2]).map((f) => camel(f.split(":")[0].trim())) : [];
      rustVariants.set(m[1].toLowerCase(), fields);
    }
    const tsUnion = ipcTs.slice(ipcTs.indexOf("export type CaptionEvent"), ipcTs.indexOf("export interface RectPx"));
    const tsVariants = new Map<string, string[]>();
    for (const line of tsUnion.split("\n")) {
      const m = /\{ kind: "(\w+)"(.*)\}/.exec(line);
      if (!m) continue;
      tsVariants.set(m[1], [...m[2].matchAll(/;\s*(\w+):/g)].map((f) => f[1]));
    }
    expect([...tsVariants.keys()].sort()).toEqual([...rustVariants.keys()].sort());
    for (const [kind, fields] of tsVariants) expect(sorted(fields), `CaptionEvent "${kind}"`).toEqual(sorted(rustVariants.get(kind)!));
  });

  it("every Show as view the transcript offers can be exported in that view", () => {
    const rust = /EXPORT_VIEWS: \[&str; \d+\] = \[([^\]]+)\]/.exec(commandsRs)?.[1] ?? "";
    expect(sorted([...rust.matchAll(/"(\w+)"/g)].map((m) => m[1]))).toEqual(sorted(tsUnionValues("TranscriptView")));
  });

  it("the TypeScript SettingsPatch declares exactly the fields the Rust SettingsPatch accepts", () => {
    expect(sorted(tsInterfaceKeys("SettingsPatch"))).toEqual(sorted(settingsPatchKeys));
  });

  it("the server pages the window opens are the ones the Rust command lets through", () => {
    const rust = /SERVER_PAGES: \[&str; \d+\] = \[([^\]]+)\]/.exec(commandsRs)?.[1] ?? "";
    expect(sorted(tsUnionValues("ServerPage"))).toEqual(sorted([...rust.matchAll(/"([\w/]+)"/g)].map((m) => m[1])));
  });

  it("the meeting pack formats are the ones the Rust command takes", () => {
    const body = commandsRs.slice(commandsRs.indexOf("pub async fn meeting_pack"));
    const rust = [...body.slice(0, body.indexOf("_ => return Err")).matchAll(/"(\w+)" => "\w+"/g)].map((m) => m[1]);
    expect(sorted(tsUnionValues("MeetingPackFormat"))).toEqual(sorted(rust));
  });

  it("a project change sends only the fields the Rust side lets through, the screens switch among them", () => {
    const pick = /updateClient: \(id: string, patch: Partial<Pick<ClientFolder, ([^>]+)>>\)/.exec(ipcTs)?.[1] ?? "";
    const rust = /CLIENT_PATCH_KEYS: \[&str; \d+\] = \[([^\]]+)\]/.exec(commandsRs)?.[1] ?? "";
    const keys = (text: string) => sorted([...text.matchAll(/"(\w+)"/g)].map((m) => m[1]));
    expect(keys(pick)).toEqual(keys(rust));
    expect(keys(pick)).toContain("screens");
  });

  it("the help's types carry the fields the Rust models send (2.0.0-d)", () => {
    for (const name of ["HelpGuideHit", "HelpGuide", "WhatsNewRelease"]) {
      expect(sorted(tsInterfaceKeys(name)), name).toEqual(sorted(rustFields(rustSourceDeclaring("struct", name), name, false)));
    }
    // The lists come as the server sends them, `{data: [...]}`, which the window reads.
    expect(rustFields(rustSourceDeclaring("struct", "DataList"), "DataList", false)).toEqual(["data"]);
  });

  it("D-250: the flags the window reads are exactly the ones the core passes on (ui_flags::KNOWN): none is always off", () => {
    const source = Object.values(allRustSources).find((text) => text.includes("pub const KNOWN: [&str;"));
    if (!source) throw new Error("no Rust source declares pub const KNOWN");
    const list = balanced(source, source.indexOf("pub const KNOWN: [&str;") + "pub const KNOWN: [&str;".length, "[", "]");
    const known = [...list.replace(/\/\/.*$/gm, "").matchAll(/"([^"]+)"/g)].map((m) => m[1]);
    expect(sorted(known)).toEqual(sorted([...UI_FLAG_NAMES]));
    expect(known).toContain("v21_urdu");
  });

  it("an Activity item's person has the fields the Rust model reads (2.0.0-aa)", () => {
    expect(sorted(tsInterfaceKeys("ActivityActor"))).toEqual(sorted(rustFields(rustSourceDeclaring("struct", "ActivityActor"), "ActivityActor", false)));
    expect(rustFields(rustSourceDeclaring("struct", "ActivityItem"), "ActivityItem", false)).toContain("actor");
    expect(tsInterfaceKeys("ActivityItem")).toContain("actor");
  });

  it("a screen has the fields the Rust model reads, file_name and said included (2.0.0-e)", () => {
    expect(sorted(tsInterfaceKeys("ScreenMoment"))).toEqual(sorted(rustFields(rustSourceDeclaring("struct", "ScreenMoment"), "ScreenMoment", false)));
  });

  it("the last device report says when it was sent, as Rust sends it", () => {
    expect(tsInterfaceKeys("LastDeviceReport")).toEqual(rustFields(rustSourceDeclaring("struct", "LastDeviceReport"), "LastDeviceReport"));
  });

  it("Live Translate's availability has the fields and reasons Rust sends", () => {
    const source = rustSourceDeclaring("struct", "Availability");
    expect(sorted(tsInterfaceKeys("LiveTranslateAvailability"))).toEqual(sorted(rustFields(source, "Availability", false)));
    expect(sorted(tsUnionValues("LiveTranslateUnavailable"))).toEqual(sorted(rustEnumValues(rustSourceDeclaring("enum", "Unavailable"), "Unavailable")));
  });

  it("the Ready check's fixes and fields are the ones Rust sends", () => {
    expect(sorted(tsUnionValues("ReadyFixAction"))).toEqual(sorted(rustEnumValues(rustSourceDeclaring("enum", "FixAction"), "FixAction")));
    expect(sorted(tsInterfaceKeys("ReadyCheck"))).toEqual(sorted(rustFields(rustSourceDeclaring("struct", "ReadyCheck"), "ReadyCheck").filter((f) => f !== "critical")));
  });

  it("the shortcut names the Ready check says are the ones Settings shows", () => {
    for (const [action, title] of SHORTCUT_ACTIONS) {
      const variant = action.split("_").map((w) => w[0].toUpperCase() + w.slice(1)).join("");
      expect(shortcutsRs, `${action} is "${title}" in Rust too`).toContain(`Action::${variant} => "${title}"`);
    }
  });

  it("screen hold reasons match the serde names of the Rust enum", () => {
    expect(sorted(tsUnionValues("ScreenHoldReason"))).toEqual(sorted(rustEnumValues(rustSourceDeclaring("enum", "HoldReason"), "HoldReason")));
  });

  it("the screen targets are the ones the Rust privacy rules know", () => {
    const privacy = rustSourceDeclaring("enum", "HoldReason");
    const consts = [...privacy.matchAll(/pub const (?:MEETING_WINDOW|WHOLE_SCREEN): &str = "(\w+)";/g)].map((m) => m[1]);
    expect(sorted(tsUnionValues("ScreenTarget"))).toEqual(sorted(consts));
  });

  it.each(["SiteRule", "PillPosition", "PendingItem", "UploadItem", "RecordingState", "CallDone", "CallGroup", "CardStreak", "AfterCallState", "SkippedItem", "InputDevice", "AskPayload", "NavigatePayload", "ShareOptions", "RequestLinkOptions", "ScreensPill", "ScreensStatus", "PlanInfo", "SignInOutcome", "UpdateStatus"])(
    "%s fields match the Rust struct",
    (name) => {
      expect(sorted(tsInterfaceKeys(name))).toEqual(sorted(rustFields(rustSourceDeclaring("struct", name), name)));
    },
  );

  it.each(["AfterCall", "AskWhen", "AfterEachCall", "CallChoice", "SkipReason", "TrayState", "BudgetState", "IgnoreReason", "SignInResult", "UpdatePhase"])(
    "%s values match the serde names of the Rust enum",
    (name) => {
      expect(sorted(tsUnionValues(name))).toEqual(sorted(rustEnumValues(rustSourceDeclaring("enum", name), name)));
    },
  );

  it("every setting the UI patches is a field the Rust SettingsPatch accepts", () => {
    const sources = [settingsViewTsx, recordViewTsx, screenSettingsTsx, captureSettingsTsx, dictationSettingsTsx, securitySettingsTsx, appSettingsTsx, accountSettingsTsx].join("\n");
    const sent = new Set([...sources.matchAll(/(?:patch|updateSettings)\(\{\s*(\w+):/g)].map((m) => m[1]));
    expect(sent.size).toBeGreaterThanOrEqual(6);
    for (const key of sent) expect(settingsPatchKeys, `"${key}" is not in SettingsPatch`).toContain(key);
  });
});
