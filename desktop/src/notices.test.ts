// Every toast says where it goes (docs/DESIGN_SYSTEM.md "Notices" 1, v1.6.1): each `notify(...)` call in the window's
// code either passes a target (the recording, the page, the fix) or `NO_TARGET` on purpose for a notice about nothing
// in particular. A plain error (`notify(text, true)`) may leave it out. Read from the source, so a new call site that
// forgets is caught here.
import { describe, expect, it } from "vitest";

const sources = import.meta.glob<string>("./views/**/*.tsx", { query: "?raw", import: "default", eager: true });

/** The text of each call `name(...)` in `source`, with its arguments split at the top level. */
export function callsOf(source: string, name: RegExp): { line: number; args: string[] }[] {
  const out: { line: number; args: string[] }[] = [];
  for (const match of source.matchAll(name)) {
    let i = (match.index ?? 0) + match[0].length;
    const args: string[] = [];
    let depth = 0;
    let current = "";
    let quote: string | null = null;
    for (; i < source.length; i++) {
      const c = source[i];
      if (quote) {
        current += c;
        if (c === "\\") {
          current += source[++i];
          continue;
        }
        if (c === quote) quote = null;
        continue;
      }
      if (c === '"' || c === "'" || c === "`") {
        quote = c;
        current += c;
        continue;
      }
      if ("([{".includes(c)) depth++;
      if (")]}".includes(c)) {
        if (depth === 0) break;
        depth--;
      }
      if (c === "," && depth === 0) {
        args.push(current.trim());
        current = "";
        continue;
      }
      current += c;
    }
    if (current.trim()) args.push(current.trim());
    out.push({ line: source.slice(0, match.index).split("\n").length, args });
  }
  return out;
}

describe("every toast says where it goes", () => {
  it("reads calls and their arguments", () => {
    expect(callsOf(`notify("a, b", true, { kind: "page", page: x(1, 2) });`, /\bnotify\(/g)[0].args).toEqual(['"a, b"', "true", '{ kind: "page", page: x(1, 2) }']);
    expect(callsOf("notify?.(`${a}, ${b}`)", /\bnotify\?\.\(/g)[0].args).toEqual(["`${a}, ${b}`"]);
  });

  it("each notify(...) passes a target or NO_TARGET, unless it is a plain error", () => {
    const missing: string[] = [];
    let counted = 0;
    let withTarget = 0;
    for (const [file, source] of Object.entries(sources)) {
      if (file.includes(".test.")) continue;
      for (const call of [...callsOf(source, /\bnotify\(/g), ...callsOf(source, /\bnotify\?\.\(/g)]) {
        counted++;
        const [, error, target] = call.args;
        if (target !== undefined) {
          if (target !== "NO_TARGET") withTarget++;
          continue;
        }
        if (error === "true") continue;
        missing.push(`${file}:${call.line} notify(${call.args.join(", ")})`);
      }
    }
    expect(missing, `these toasts must say where they go (a target, or NO_TARGET):\n${missing.join("\n")}`).toEqual([]);
    expect(counted).toBeGreaterThan(100);
    expect(withTarget, "most toasts are about something").toBeGreaterThan(20);
  });
});
