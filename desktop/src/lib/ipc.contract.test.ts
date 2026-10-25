// Cross-checks the TypeScript IPC layer (lib/ipc.ts) against the Rust sources it talks to, so a renamed command,
// argument, enum value or struct field on either side fails here instead of at runtime inside the webview.
import { describe, expect, it } from "vitest";
import ipcTs from "./ipc.ts?raw";
import settingsViewTsx from "../views/main/SettingsView.tsx?raw";
import recordViewTsx from "../views/main/RecordView.tsx?raw";
import commandsRs from "../../src-tauri/src/commands.rs?raw";
import libRs from "../../src-tauri/src/lib.rs?raw";
import settingsRs from "../../src-tauri/src/settings.rs?raw";
import audioRs from "../../src-tauri/src/audio/mod.rs?raw";
import audioTypesRs from "../../src-tauri/src/audio/types.rs?raw";
import detectRs from "../../src-tauri/src/detect/mod.rs?raw";
import geometryRs from "../../src-tauri/src/overlay/geometry.rs?raw";
import shortcutsRs from "../../src-tauri/src/shortcuts.rs?raw";
import modelsRs from "../../src-tauri/src/net/models.rs?raw";
import captionsRs from "../../src-tauri/src/stt/captions.rs?raw";

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
  /** Arguments the webview must send (State / AppHandle / Window are injected by Tauri). */
  args: { name: string; optional: boolean }[];
}

function rustCommands(): Map<string, RustCommand> {
  const found = new Map<string, RustCommand>();
  const re = /#\[tauri::command\]\s*pub\s+(?:async\s+)?fn\s+(\w+)\s*(?:<[^>]*>)?\s*\(/g;
  for (const match of commandsRs.matchAll(re)) {
    const params = splitTopLevel(balanced(commandsRs, match.index! + match[0].length - 1, "(", ")"));
    const args = params
      .map((p) => {
        const [name, ...type] = p.split(":");
        return { name: name.trim(), type: type.join(":").trim() };
      })
      .filter((p) => !/^(AppHandle|State\b|WebviewWindow|Window)/.test(p.type))
      .map((p) => ({ name: camel(p.name), optional: p.type.startsWith("Option<") }));
    found.set(match[1], { name: match[1], args });
  }
  return found;
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

describe("command names and arguments", () => {
  const commands = rustCommands();
  const calls = tsInvocations();

  it("finds every wrapper in ipc.ts and every command in commands.rs", () => {
    expect(calls.length).toBeGreaterThanOrEqual(25);
    expect(commands.size).toBeGreaterThanOrEqual(25);
  });

  it.each(calls.map((c) => [c.command, c] as const))("%s exists in Rust and is registered in the invoke handler", (name) => {
    expect(commands.has(name), `commands.rs has no #[tauri::command] ${name}`).toBe(true);
    expect(libRs, `${name} missing from generate_handler!`).toMatch(new RegExp(`commands::${name}\\b`));
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

  it("every setting the UI patches is a field the Rust SettingsPatch accepts", () => {
    const sources = [settingsViewTsx, recordViewTsx].join("\n");
    const sent = new Set([...sources.matchAll(/(?:patch|updateSettings)\(\{\s*(\w+):/g)].map((m) => m[1]));
    expect(sent.size).toBeGreaterThanOrEqual(6);
    for (const key of sent) expect(settingsPatchKeys, `"${key}" is not in SettingsPatch`).toContain(key);
  });
});
