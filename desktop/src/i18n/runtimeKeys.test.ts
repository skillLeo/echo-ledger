// Words the screens pass to t() from a constant or from the server, which the source scan can't see as keys (D-261): the
// platform's settings button (lib/controls), the Bluetooth mic note, Live Translate's languages, and the headings the
// server writes on a call note's sections (web/app/Models/CallNote.php). Each has its Urdu, as the web's where it has one.
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import ur from "./ur.json";
import { SETTINGS_LABEL } from "../lib/controls";
import { BLUETOOTH_NOTE } from "../lib/microphone";
import { TRANSLATE_TARGETS } from "../lib/translate";

const here = dirname(fileURLToPath(import.meta.url));
const catalogue = ur as Record<string, string>;
const web = JSON.parse(readFileSync(join(here, "..", "..", "..", "web", "lang", "ur.json"), "utf8")) as Record<string, string>;

/** The note headings as the server's model lists them ('asked_for' => 'Asked for', …). */
function serverHeadings(): string[] {
  const php = readFileSync(join(here, "..", "..", "..", "web", "app", "Models", "CallNote.php"), "utf8");
  const block = php.match(/'asked_for'\s*=>[\s\S]*?\]/)?.[0] ?? "";
  return [...block.matchAll(/'[a-z_]+'\s*=>\s*'([^']+)'/g)].map((m) => m[1]);
}

describe("the words screens get at run time have their Urdu", () => {
  it("the settings button, the Bluetooth note and Live Translate's languages", () => {
    const words = [...Object.values(SETTINGS_LABEL).filter(Boolean), BLUETOOTH_NOTE, ...TRANSLATE_TARGETS.map(([, name]) => name)];
    expect(words.filter((w) => !catalogue[w])).toEqual([]);
  });

  it("every heading the server writes on a call note, as the web says it", () => {
    const headings = serverHeadings();
    expect(headings).toEqual(expect.arrayContaining(["Asked for", "Changes", "Decisions", "My action items"]));
    expect(headings.filter((h) => !catalogue[h])).toEqual([]);
    expect(headings.filter((h) => web[h] && web[h] !== catalogue[h])).toEqual([]);
  });
});
