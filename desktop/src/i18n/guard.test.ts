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

const rel = (path: string) => relative(src, path).split(sep).join("/");
const holds = (area: string, file: string) => (area.endsWith("/") ? file.startsWith(area) : file === area);

/** The areas that name no source file (a misspelt entry would otherwise guard nothing). */
function unmatched(areas: readonly string[]): string[] {
  const files = sources(src).map(rel);
  return areas.filter((area) => !files.some((file) => holds(area, file)));
}

/** Every raw text in the converted areas, as "file:line text". */
function rawIn(areas: readonly string[]): string[] {
  const files = sources(src).filter((path) => areas.some((area) => holds(area, rel(path))));
  return files.flatMap((path) => textsIn(path, readFileSync(path, "utf8")).filter((f) => !f.translated)
    .map((f) => `${relative(src, path)}:${f.line} ${f.text}`));
}

describe("the raw-text guard", () => {
  it("every converted area gives its text to t() or tc()", () => {
    expect(rawIn(CONVERTED)).toEqual([]);
  });

  it("every converted area names at least one source file", () => {
    expect(unmatched(CONVERTED)).toEqual([]);
    expect(unmatched(["v2/library/", "v2/libary/", "i18n/index.ts", "i18n/indx.ts"])).toEqual(["v2/libary/", "i18n/indx.ts"]);
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

  it("any other string prop that reads as words is text too, except the few that never are", () => {
    const code = `
      import { t as tr } from "../../i18n";
      export function Row({ on }: { on: boolean }) {
        const { t: say, tc: many } = useT();
        return (
          <Item value="Not connected" page="Appearance" confirm="Sign out" className="btn compact ghost" buttonClass="chip columns-btn"
            id="Row one" role="Status line" data-hint="Hello there" icon="Big tick" variant="Quiet one" tone="Very red" size="Extra large"
            d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6" aria-keyshortcuts="Meta+F Control+F" href="Plain words" type="Not a word" anchor="Below the fold"
            label={on ? "Turned on" : "Turned off"} sectionClassName={on ? "big wide" : "small narrow"}>
            {say("Search recordings")}{many(":count file|:count files", 2)}{tr("Saved for later")}
          </Item>
        );
      }`;
    const found = textsIn("Row.tsx", code);
    expect(found.filter((f) => !f.translated).map((f) => `${f.kind}: ${f.text}`)).toEqual([
      "prop: Not connected", "prop: Appearance", "prop: Sign out", "literal: Turned on", "literal: Turned off",
    ]);
    // A name given to t or tc (destructured from useT(), or imported as another name) translates too.
    expect(found.filter((f) => f.translated).map((f) => f.text)).toEqual(["Search recordings", ":count file|:count files", "Saved for later"]);
  });
});
