// FIX-03: a problem said in a toast is flagged as one, so a newer message never pushes it off the screen (Toast's
// `error`). Every say(…), onMessage(…) or notify(…) in src/v2 (say?.(…) too) whose words are a problem
// (errorMessage(…), "couldn't", "Could not", "didn't", "can't", "Try again"), or that is said in a `catch` block or a
// `.catch(…)`, passes the flag: say and onMessage through sayProblem(say, text) or `true` after the text, notify as its
// second argument. A wrapper that takes only the text (`const tell = (t) => say(t)`, `say={(t) => notify(t)}`) can't
// pass it: its problems are found as the wrapper's.
import { describe, expect, it } from "vitest";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const files = (dir: string): string[] => readdirSync(dir).flatMap((f) => {
  const path = join(dir, f);
  return statSync(path).isDirectory() ? files(path) : [path];
});

/**
 * Where the comment that starts at `i` ends (the index after it), or -1 when none starts there: a `//` comment runs to
 * the end of its line, a `/* … *\/` one to its close. An apostrophe in a comment ("can't be captured") is no quote.
 */
function commentEnd(text: string, i: number): number {
  if (text[i] !== "/") return -1;
  if (text[i + 1] === "/") {
    const end = text.indexOf("\n", i);
    return end < 0 ? text.length : end;
  }
  if (text[i + 1] === "*") {
    const end = text.indexOf("*/", i + 2);
    return end < 0 ? text.length : end + 2;
  }
  return -1;
}

/** The arguments of the call whose "(" is at `open`, split at the top level (comments left out). */
function argsAt(text: string, open: number): string[] {
  const out: string[] = [];
  let depth = 0;
  let quote: string | null = null;
  let current = "";
  for (let i = open + 1; i < text.length; i++) {
    const c = text[i];
    const skip = quote ? -1 : commentEnd(text, i);
    if (skip >= 0) {
      i = skip - 1;
      continue;
    }
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

const PROBLEM = /errorMessage\(|couldn['’]t|could not|didn['’]t|can['’]t|try again|failed|went wrong|unable/i;
const SAYERS = ["say", "onMessage", "notify"];

/** The index of the closer that matches the opener at `open` ("(", "{"), skipping strings; -1 when there is none. */
function closeOf(text: string, open: number): number {
  let depth = 0;
  let quote: string | null = null;
  for (let i = open; i < text.length; i++) {
    const c = text[i];
    const skip = quote ? -1 : commentEnd(text, i);
    if (skip >= 0) {
      i = skip - 1;
      continue;
    }
    if (quote) {
      if (c === "\\") i++;
      else if (c === quote) quote = null;
      continue;
    }
    if (c === '"' || c === "'" || c === "`") quote = c;
    else if ("([{".includes(c)) depth++;
    else if (")]}".includes(c) && --depth === 0) return i;
  }
  return -1;
}

/**
 * Where an arrow's expression body that starts at `from` ends (pure): at its `;`, or at the end of the line once its
 * brackets are closed, Prettier's wrapped lines included (`=>` then the call on the next line).
 */
function statementEnd(text: string, from: number): number {
  let i = from;
  while (i < text.length && /\s/.test(text[i])) i++;
  let depth = 0;
  let quote: string | null = null;
  for (; i < text.length; i++) {
    const c = text[i];
    const skip = quote ? -1 : commentEnd(text, i);
    if (skip >= 0) {
      i = skip - 1;
      continue;
    }
    if (quote) {
      if (c === "\\") i++;
      else if (c === quote) quote = null;
      continue;
    }
    if (c === '"' || c === "'" || c === "`") quote = c;
    else if ("([{".includes(c)) depth++;
    else if (")]}".includes(c)) {
      if (--depth < 0) return i;
    } else if ((c === ";" || c === "\n") && depth === 0) return i;
  }
  return text.length;
}

/** Where a problem is being handled: every `catch (…) { … }` block and `.catch(…)` call, as [from, to]. */
function catchSpans(text: string): [number, number][] {
  const spans: [number, number][] = [];
  for (const m of text.matchAll(/\bcatch\s*(?:\([^)]*\))?\s*\{/g)) spans.push([m.index!, closeOf(text, m.index! + m[0].length - 1)]);
  for (const m of text.matchAll(/\.catch\(/g)) spans.push([m.index!, closeOf(text, m.index! + m[0].length - 1)]);
  return spans;
}

/**
 * The single-argument wrappers in a source that drop the flag: `const tell = (text) => say(text)` or
 * `function tell(text) { say(text); }`, the text passed on with nothing after it that could say it is a problem.
 */
function droppingWrappers(text: string): string[] {
  const out: string[] = [];
  const defs = [
    ...text.matchAll(/\b(?:const|let)\s+(\w+)\s*=\s*(?:async\s*)?\(\s*(\w+)\s*(?::\s*[^,()]+)?\)\s*(?::\s*[\w<>]+\s*)?=>/g),
    ...text.matchAll(/\bfunction\s+(\w+)\s*\(\s*(\w+)\s*(?::\s*[^,()]+)?\)\s*(?::\s*[\w<>]+\s*)?(?=\{)/g),
  ];
  for (const m of defs) {
    const [, name, param] = m;
    // The wrapper's body: its block, or its expression up to the end of the line.
    const from = m.index! + m[0].length;
    const brace = text.slice(from).match(/^\s*\{/);
    const body = brace ? text.slice(from, closeOf(text, from + brace[0].length - 1) + 1) : text.slice(from, statementEnd(text, from));
    for (const call of body.matchAll(new RegExp(`\\b(${SAYERS.join("|")})(?:\\?\\.)?\\(`, "g"))) {
      const args = argsAt(body, call.index! + call[0].length - 1);
      if (args[0] === param && !args.slice(1).includes("true")) out.push(name);
    }
  }
  return [...new Set(out)].filter((name) => !SAYERS.includes(name));
}

/** Each unflagged problem in a source: "say at line n". */
export function unflagged(text: string): string[] {
  const found: string[] = [];
  const line = (at: number) => text.slice(0, at).split("\n").length;
  const spans = catchSpans(text);
  const inCatch = (at: number) => spans.some(([from, to]) => at > from && (to < 0 || at < to));
  const wrappers = droppingWrappers(text);
  const names = [...SAYERS, ...wrappers];
  // Dotted too: props.say(…), ref.current.notify(…).
  for (const m of text.matchAll(new RegExp(`(?<![\\w$])(${names.join("|")})(\\?\\.)?\\(`, "g"))) {
    const before = text.slice(Math.max(0, m.index! - 40), m.index!);
    // A wrapper's own definition is not a call of it.
    if (/\b(?:const|let|function)\s+$/.test(before)) continue;
    const args = argsAt(text, m.index! + m[0].length - 1);
    if (!args[0]) continue;
    if (!PROBLEM.test(args[0]) && !inCatch(m.index!)) continue;
    const wrapper = wrappers.includes(m[1]);
    // A later argument `true` is the flag: notify's second, say's third (or second where it takes only the flag).
    const flagged = !wrapper && args.slice(1).includes("true");
    if (!flagged) found.push(`${m[1]} at line ${line(m.index!)}`);
  }
  // A one-argument function given as a say, onMessage or notify prop: every problem said through it loses its flag.
  for (const m of text.matchAll(new RegExp(`\\b(${SAYERS.join("|")})=\\{\\s*(?:\\(\\s*\\w+\\s*(?::[^,()]+)?\\)|\\w+)\\s*=>`, "g"))) {
    found.push(`${m[1]} prop at line ${line(m.index!)}`);
  }
  return found;
}

// The pages and the window that hands them its say (MainApp's notify): src/v2 and src/views.
const sources = [...files(here), ...files(join(here, "..", "views"))]
  .filter((f) => /\.tsx?$/.test(f) && !/\.test\.tsx?$/.test(f))
  .map((f) => [relative(join(here, ".."), f).split("\\").join("/"), readFileSync(f, "utf8")] as const);

describe("problems are said as problems (FIX-03)", () => {
  it("finds an unflagged problem and passes a flagged one", () => {
    expect(unflagged('say(errorMessage(e));\nsay("Saved");\nsayProblem(say, errorMessage(e));\nnotify(errorMessage(e), true);')).toEqual(["say at line 1"]);
    expect(unflagged('onMessage("The audio didn\'t play.");\nnotify(`Couldn\'t ${x}`, false);\nsay(errorMessage(e), undefined, true);')).toEqual(["onMessage at line 1", "notify at line 2"]);
  });

  it("finds problems said through say?.(, in a catch or .catch(, with \"can't\" or \"Try again\", or through a wrapper that drops the flag", () => {
    expect(unflagged('say?.(errorMessage(e));\nsay?.(errorMessage(e), true);')).toEqual(["say at line 1"]);
    expect(unflagged('notify("Can\'t reach the server.", false);\nsay("Something went wrong. Try again.");\nnotify("Can\'t reach the server.", true);')).toEqual(["notify at line 1", "say at line 2"]);
    // In a catch, whatever the words: a problem.
    expect(unflagged('try { a(); } catch (e) {\n  say(String(e));\n}\nsay(String(x));')).toEqual(["say at line 2"]);
    expect(unflagged('load().catch((e) =>\n  notify(String(e)));\nload().catch((e) => notify(String(e), true));\nload().then(() => say("Loaded"));')).toEqual(["notify at line 2"]);
    // A wrapper that takes only the text drops the flag, whatever it is called with; one that flags is fine.
    expect(unflagged('const tell = (text: string) => say(text);\ntell(errorMessage(e));\ntell("Saved");')).toEqual(["tell at line 2"]);
    expect(unflagged('function warn(t: string) {\n  notify(t);\n}\nwarn("Couldn\'t save");')).toEqual(["warn at line 4"]);
    expect(unflagged('const fail = (text: string) => say(text, undefined, true);\nfail(errorMessage(e));')).toEqual([]);
    expect(unflagged('<Sheet say={(t) => notify(t)} />\n<Sheet say={(text, error) => notify(text, error)} />\n<Sheet notify={notify} />')).toEqual(["say prop at line 1"]);
    // Not a problem, nowhere near a catch: nothing.
    expect(unflagged('say("Saved");\nnotify("Library synced");')).toEqual([]);
  });

  it("skips comments, reads dotted calls and wrapped wrappers, and knows \"failed\", \"went wrong\" and \"unable\"", () => {
    // An apostrophe in a comment opens no string: the catch ends where it ends, and what follows is read as code.
    expect(unflagged('try { a(); } catch {\n  // The window can\'t be captured.\n  return;\n}\nsay("Saved");')).toEqual([]);
    expect(unflagged('try { a(); } catch {\n  /* it can\'t */ say(String(e));\n}')).toEqual(["say at line 2"]);
    // Dotted calls.
    expect(unflagged('props.say(errorMessage(e));\nref.current.notify("Upload failed", false);\nprops.say(errorMessage(e), undefined, true);')).toEqual(["say at line 1", "notify at line 2"]);
    // An arrow wrapper Prettier wrapped onto the next line.
    expect(unflagged('const tell = (text: string) =>\n  say(text);\ntell(errorMessage(e));')).toEqual(["tell at line 3"]);
    // The words.
    expect(unflagged('say("The upload failed.");\nsay("Something went wrong.");\nsay("Unable to reach the server.");')).toEqual(["say at line 1", "say at line 2", "say at line 3"]);
  });

  it("reads every page and the window (src/v2 and src/views), each by name", () => {
    const names = sources.map(([name]) => name);
    expect(names).toEqual(expect.arrayContaining(["v2/library/LibraryV2.tsx", "v2/activity/ActivityV2.tsx", "views/main/MainApp.tsx"]));
    expect(names.length).toBeGreaterThan(100);
  });

  it.each(sources)("%s flags every problem it says", (_name, text) => {
    expect(unflagged(text)).toEqual([]);
  });
});
