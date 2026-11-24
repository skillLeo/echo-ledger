// The rename box starts with the title only when it is the person's own (contract 2.0.0-k: apps pre-fill their rename
// box only for title_source "own"), as the Library's Rename… and the recording page's title both open it.
import { describe, expect, it } from "vitest";
import { renamePrefill } from "./organise";

describe("the rename box's first words", () => {
  it("the person's own title, and nothing for one from the person called, what was said or the app", () => {
    expect(renamePrefill({ title: "March shipment", title_source: "own" })).toBe("March shipment");
    expect(renamePrefill({ title: "Call with Bilal", title_source: "person" })).toBe("");
    expect(renamePrefill({ title: "Laptop — 16 vs 32 GB", title_source: "auto", title_auto: true })).toBe("");
    expect(renamePrefill({ title: "WhatsApp call", title_source: "app" })).toBe("");
  });

  it("from an older server (no title_source): a title not named from what was said", () => {
    expect(renamePrefill({ title: "Bilal", title_auto: false })).toBe("Bilal");
    expect(renamePrefill({ title: "Bilal" })).toBe("Bilal");
    expect(renamePrefill({ title: "Laptop prices", title_auto: true })).toBe("");
    expect(renamePrefill({ title_source: "own" })).toBe("");
  });
});
