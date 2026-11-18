// docs/v2/audit/parity.csv (docs/PLAN_V2_PARITY.md 1): every classic feature with where it lives in v2. The desktop
// rows stay well formed: the plan's columns, one id each, a known status, and a test named for every "done" row.
import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

// Outside the app's folder, so read from disk (Vite serves only the app's own files).
const parityCsv = readFileSync(join(dirname(fileURLToPath(import.meta.url)), "..", "..", "docs", "v2", "audit", "parity.csv"), "utf8");

/** RFC 4180 rows (quoted fields, doubled quotes inside). */
function rows(text: string): string[][] {
  const out: string[][] = [];
  let row: string[] = [];
  let field = "";
  let quoted = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (quoted) {
      if (c === '"' && text[i + 1] === '"') { field += '"'; i++; } else if (c === '"') quoted = false; else field += c;
    } else if (c === '"') quoted = true;
    else if (c === ",") { row.push(field); field = ""; }
    else if (c === "\n") { row.push(field); out.push(row); row = []; field = ""; }
    else field += c;
  }
  if (field || row.length) { row.push(field); out.push(row); }
  return out;
}

describe("the parity audit's desktop rows", () => {
  const [header, ...all] = rows(parityCsv);
  const desktop = all.filter((r) => r[1] === "desktop");

  it("have the plan's columns", () => {
    expect(header).toEqual(["id", "platform", "screen", "feature", "how to reach it in classic", "where it lives in v2", "status", "test that proves it"]);
    expect(desktop.length).toBeGreaterThan(500);
    for (const r of desktop) expect(r, r[0]).toHaveLength(8);
  });

  it("one id each, a known status, and a test for every done row", () => {
    const ids = desktop.map((r) => r[0]);
    expect(ids.filter((id, i) => ids.indexOf(id) !== i)).toEqual([]);
    for (const r of desktop) {
      expect(["missing", "partial", "done", "retired-with-approval"], r[0]).toContain(r[6]);
      if (r[6] === "done") expect(r[7], r[0]).not.toBe("none");
    }
  });
});
