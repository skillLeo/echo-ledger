// The catalogue, read as text: every key once (desktop Urdu). JSON.parse keeps the last of two equal keys without a word,
// and two branches that each append the same key merge in git without a conflict, so a repeated key would hide one Urdu
// line. The web's lang/ur.json has the same guard (web/tests/Unit/UrduJsonKeysTest.php).
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

const here = dirname(fileURLToPath(import.meta.url));

/** The keys of a flat JSON object as they are written, in order (repeats kept). */
export function catalogueKeys(raw: string): string[] {
  const keys: string[] = [];
  for (const m of raw.matchAll(/^\s*("(?:[^"\\]|\\.)*")\s*:\s*"(?:[^"\\]|\\.)*"\s*,?\s*$/gm)) keys.push(JSON.parse(m[1]) as string);
  return keys;
}

describe("the Urdu catalogue (src/i18n/ur.json)", () => {
  it("has every key once, and nothing in it that is not a key and its line", () => {
    const raw = readFileSync(join(here, "ur.json"), "utf8");
    const keys = catalogueKeys(raw);
    const twice = [...new Set(keys.filter((key, i) => keys.indexOf(key) !== i))];
    expect(twice, `twice in ur.json: ${twice.join(", ")}`).toEqual([]);
    expect(keys.length, "a line of ur.json is not one \"key\": \"value\"").toBe(Object.keys(JSON.parse(raw) as object).length);
  });

  it("finds a key written twice", () => {
    expect(catalogueKeys('{\n  "Hide": "a",\n  "Hide": "b"\n}\n')).toEqual(["Hide", "Hide"]);
  });
});
