// lib/format.ts and lib/languages.ts load in plain Node (the web's AdminTranscriptPlayerTest imports format.ts to
// compare the transcript copy formats): each one, and every file it imports, imports only relative .ts files, never
// React or a package, a folder ("../i18n") or a path without its extension, which Node's ES modules can't resolve. A
// whole `import type` is erased when Node strips the types, so it doesn't count; `import { type X }` still loads.
import { describe, expect, it } from "vitest";
import { execFileSync } from "node:child_process";
import { existsSync, readFileSync } from "node:fs";
import { dirname, join, relative, resolve, sep } from "node:path";
import { fileURLToPath } from "node:url";

const src = join(dirname(fileURLToPath(import.meta.url)), "..");
const PLAIN = ["lib/format.ts", "lib/languages.ts"];

/** The specifiers a file loads at run time (static imports and exports, and import()), without whole `import type`s. */
function specifiers(code: string): string[] {
  const found: string[] = [];
  const statement = /^\s*(import|export)\s+(type\s+)?([^;]*?)\s*from\s*["']([^"']+)["']/gms;
  for (const m of code.matchAll(statement)) if (!m[2]) found.push(m[4]);
  for (const m of code.matchAll(/^\s*import\s*["']([^"']+)["']/gm)) found.push(m[1]);
  for (const m of code.matchAll(/\bimport\(\s*["']([^"']+)["']\s*\)/g)) found.push(m[1]);
  return found;
}

/** Every file `entry` loads, with what each one imports that plain Node can't load ("file: specifier"). */
function walk(entry: string): { files: string[]; refused: string[] } {
  const files: string[] = [];
  const refused: string[] = [];
  const queue = [join(src, entry)];
  while (queue.length) {
    const file = queue.shift()!;
    const name = relative(src, file).split(sep).join("/");
    if (files.includes(name)) continue;
    files.push(name);
    for (const spec of specifiers(readFileSync(file, "utf8"))) {
      const target = resolve(dirname(file), spec);
      if (!spec.startsWith(".") || !spec.endsWith(".ts") || !existsSync(target)) refused.push(`${name}: ${spec}`);
      else queue.push(target);
    }
  }
  return { files, refused };
}

describe("the modules plain Node imports", () => {
  it("import only relative .ts files, all the way down (no React, no folder, no path without .ts)", () => {
    for (const entry of PLAIN) expect(walk(entry).refused).toEqual([]);
    expect(walk("lib/format.ts").files).toEqual(["lib/format.ts", "i18n/locale.ts", "i18n/context.ts"]);
  });

  it("the check refuses what Node can't load, and lets a whole import type through", () => {
    expect(specifiers(`import { t } from "../i18n";\nimport type { A } from "./ipc";\nimport { type B } from "./ipc";\nimport React from "react";`))
      .toEqual(["../i18n", "./ipc", "react"]);
    expect(specifiers(`export { x } from "./x.ts";\nconst m = import("./ur.json");`)).toEqual(["./x.ts", "./ur.json"]);
  });

  // Node strips the types itself from 22.18 and 23.6; an older Node in CI skips this part (the check above still runs).
  it.runIf(Boolean((process.features as { typescript?: unknown }).typescript))("plain Node loads them", () => {
    for (const entry of PLAIN) {
      const out = execFileSync(process.execPath, ["--no-warnings", "--input-type=module", "-e",
        `const m = await import(${JSON.stringify(new URL(`file://${join(src, entry)}`).href)}); console.log(Object.keys(m).length > 0);`], { encoding: "utf8" });
      expect(out.trim()).toBe("true");
    }
  });
});
