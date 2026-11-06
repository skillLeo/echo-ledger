// Words (since v1.5, as on the phone): a folder of recordings is a project, never a client ("Client" stays only as the
// other person on a call, the speaker label); leaving a server is Sign out, since accounts made pairing a sign-in. Code
// names (clientId, /api/clients, onUnpaired, the IPC commands) keep their old names.
import { describe, expect, it } from "vitest";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const files = (dir: string): string[] => readdirSync(dir).flatMap((f) => {
  const path = join(dir, f);
  return statSync(path).isDirectory() ? files(path) : [path];
});
/** Every string and JSX text the app's views and libraries can show, as one line per file. */
const sources = [...files(here), ...files(join(here, "../src-tauri/src"))]
  .filter((f) => /\.(tsx?|rs)$/.test(f) && !/\.test\.tsx?$|[/\\]tests?[/\\]|tests\.rs$/.test(f))
  .map((f) => [relative(here, f), readFileSync(f, "utf8").replace(/^\s*(\/\/|\*|\/\*).*$/gm, "")] as const);

describe("the words the apps share", () => {
  it.each([
    "All clients", "No client", "New client", "Assign client", "Client keyterms", "Client folder", "choose a client",
    "this client", "the client's", "for this client", "selected client",
  ])("never shows \"%s\" for a project", (words) => {
    const found = sources.filter(([, text]) => text.includes(words)).map(([name]) => name);
    expect(found).toEqual([]);
  });

  it("never shows Unpair or \"not paired\": leaving a server is Sign out", () => {
    // Capital U: the command and the settings-search word "unpair" are code and keywords, never shown.
    const found = sources.filter(([, text]) => /["'`>][^"'`<]*\b(Unpair|[Nn]ot paired)\b/.test(text)).map(([name]) => name);
    expect(found).toEqual([]);
  });

  it("keeps Client as the speaker label of the other side of a call", () => {
    const record = sources.find(([name]) => name.endsWith("RecordView.tsx"))![1];
    expect(record).toContain('the other side of the call is "Client"');
  });
});
