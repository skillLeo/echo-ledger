// The raw-text guard (desktop Urdu): in an area already converted (converted.ts), every piece of text a person can see
// goes through t() or tc(), so the catalogue can give it in Urdu. The finder is the one scripts/i18nExtract.mjs counts
// with (rawText.ts).
import { describe, expect, it } from "vitest";
import { readFileSync, readdirSync, statSync } from "node:fs";
import { dirname, join, relative, sep } from "node:path";
import { fileURLToPath } from "node:url";
import { CONVERTED } from "./converted";
import { textsIn } from "./rawText";

const src = join(dirname(fileURLToPath(import.meta.url)), "..");

function sources(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) return name === "gallery" ? [] : sources(path);
    return /\.tsx?$/.test(name) && !/\.test\.tsx?$/.test(name) && !name.endsWith(".d.ts") ? [path] : [];
  });
}

/** Every raw text in the converted areas, as "file:line text". */
function rawIn(areas: readonly string[]): string[] {
  const files = sources(src).filter((path) => {
    const rel = relative(src, path).split(sep).join("/");
    return areas.some((area) => (area.endsWith("/") ? rel.startsWith(area) : rel === area));
  });
  return files.flatMap((path) => textsIn(path, readFileSync(path, "utf8")).filter((f) => !f.translated)
    .map((f) => `${relative(src, path)}:${f.line} ${f.text}`));
}

describe("the raw-text guard", () => {
  it("every converted area gives its text to t() or tc()", () => {
    expect(rawIn(CONVERTED)).toEqual([]);
  });

  it("finds text between tags, in a word prop and in a literal, and lets t() and tc() through", () => {
    const code = `
      export function Row({ n, name }: { n: number; name: string }) {
        const words = ok ? "Saved" : "Couldn't save";
        return (
          <section aria-label="Recordings" className="el-group">
            <h2>Search recordings</h2>
            <p title={t("Clear :name", { name })}>{t("Search recordings")}</p>
            <p>{tc(":count recording|:count recordings", n)}</p>
            <input placeholder={\`Search \${name}\`} data-testid="search-field" />
            {e.key === "Escape" && words}
          </section>
        );
      }`;
    const found = textsIn("Row.tsx", code);
    expect(found.filter((f) => !f.translated).map((f) => `${f.kind}: ${f.text}`)).toEqual([
      "literal: Saved", "literal: Couldn't save", "prop: Recordings", "jsx text: Search recordings", "prop: Search :name",
    ]);
    expect(found.filter((f) => f.translated).map((f) => f.text)).toEqual(["Clear :name", "Search recordings", ":count recording|:count recordings"]);
  });
});
