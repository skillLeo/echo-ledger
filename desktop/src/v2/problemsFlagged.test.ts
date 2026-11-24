// FIX-03: a problem said in a toast is flagged as one, so a newer message never pushes it off the screen (Toast's
// `error`). Every say(…), onMessage(…) or notify(…) in src/v2 whose words are a problem (errorMessage(…), "couldn't",
// "Could not", "didn't") passes the flag: say and onMessage through sayProblem(say, text), notify as its second argument.
import { describe, expect, it } from "vitest";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const files = (dir: string): string[] => readdirSync(dir).flatMap((f) => {
  const path = join(dir, f);
  return statSync(path).isDirectory() ? files(path) : [path];
});

/** The arguments of the call whose "(" is at `open`, split at the top level. */
function argsAt(text: string, open: number): string[] {
  const out: string[] = [];
  let depth = 0;
  let quote: string | null = null;
  let current = "";
  for (let i = open + 1; i < text.length; i++) {
    const c = text[i];
    if (quote) {
      current += c;
      if (c === "\\") current += text[++i] ?? "";
      else if (c === quote) quote = null;
      continue;
    }
    if (c === '"' || c === "'" || c === "`") quote = c;
    else if ("([{".includes(c)) depth++;
    else if (")]}".includes(c)) {
      if (depth === 0) {
        out.push(current.trim());
        return out;
      }
      depth--;
    } else if (c === "," && depth === 0) {
      out.push(current.trim());
      current = "";
      continue;
    }
    current += c;
  }
  return out;
}

const PROBLEM = /errorMessage\(|couldn['’]t|could not|didn['’]t/i;

/** Each unflagged problem in a source: "say at line n". */
export function unflagged(text: string): string[] {
  const found: string[] = [];
  for (const m of text.matchAll(/\b(say|onMessage|notify)\(/g)) {
    const args = argsAt(text, m.index! + m[0].length - 1);
    if (!args[0] || !PROBLEM.test(args[0])) continue;
    const flagged = m[1] === "notify" ? args[1] === "true" : args[2] === "true";
    if (!flagged) found.push(`${m[1]} at line ${text.slice(0, m.index).split("\n").length}`);
  }
  return found;
}

const sources = files(here)
  .filter((f) => /\.tsx?$/.test(f) && !/\.test\.tsx?$/.test(f))
  .map((f) => [relative(here, f).split("\\").join("/"), readFileSync(f, "utf8")] as const);

describe("problems are said as problems (FIX-03)", () => {
  it("finds an unflagged problem and passes a flagged one", () => {
    expect(unflagged('say(errorMessage(e));\nsay("Saved");\nsayProblem(say, errorMessage(e));\nnotify(errorMessage(e), true);')).toEqual(["say at line 1"]);
    expect(unflagged('onMessage("The audio didn\'t play.");\nnotify(`Couldn\'t ${x}`, false);\nsay(errorMessage(e), undefined, true);')).toEqual(["onMessage at line 1", "notify at line 2"]);
  });

  it.each(sources)("%s flags every problem it says", (_name, text) => {
    expect(unflagged(text)).toEqual([]);
  });
});
