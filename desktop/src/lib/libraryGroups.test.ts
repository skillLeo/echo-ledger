import { beforeEach, describe, expect, it } from "vitest";
import { DEFAULT_CHOICE, groupBy, groupPick, loadChoice, loadOpened, rememberOpened, saveChoice, toggleGroup } from "./libraryGroups";
import { sortItems } from "./library";
import { makeItem } from "../test/fixtures";

const now = new Date("2026-11-08T12:00:00");
const items = [
  makeItem("a", { started_at: "2026-11-08T09:00:00", client: { id: "c1", name: "Acme" }, language_chip: "UR", duration_ms: 10_000 }),
  makeItem("b", { started_at: "2026-11-07T09:00:00", client: null, language_chip: "Auto", duration_ms: 90_000 }),
  makeItem("c", { started_at: "2026-10-01T09:00:00", client: { id: "c2", name: "Beta" }, language_chip: "EN", duration_ms: null }),
];

beforeEach(() => window.localStorage.clear());

describe("Group by", () => {
  it("groups by date in time order, reversed for Oldest", () => {
    expect(groupBy(sortItems(items, "newest"), "date", "newest", now).map((g) => [g.label, g.items.length])).toEqual([["Today", 1], ["Yesterday", 1], ["Earlier", 1]]);
    expect(groupBy(sortItems(items, "oldest"), "date", "oldest", now).map((g) => g.label)).toEqual(["Earlier", "Yesterday", "Today"]);
    expect(groupBy(sortItems(items, "longest"), "date", "longest", now).map((g) => g.label)).toEqual(["Today", "Yesterday", "Earlier"]);
  });

  it("puts the catch-all group last and the rest A–Z", () => {
    expect(groupBy(items, "project", "newest", now).map((g) => g.label)).toEqual(["Acme", "Beta", "No project"]);
    expect(groupBy(items, "language", "newest", now).map((g) => g.label)).toEqual(["EN", "UR", "Automatic"]);
  });

  it("is one headerless group for None, and nothing for nothing", () => {
    expect(groupBy(items, "none", "newest", now)).toEqual([{ key: "none", label: "", items }]);
    expect(groupBy([], "date", "newest", now)).toEqual([]);
  });

  it("sorts the shortest first with unknown lengths last", () => {
    expect(sortItems(items, "shortest").map((i) => i.id)).toEqual(["a", "b", "c"]);
  });
});

describe("the header's box", () => {
  it("is all, some or none, and ticks the whole group or puts it back", () => {
    expect(groupPick(items, new Set())).toBe("none");
    expect(groupPick(items, new Set(["a"]))).toBe("some");
    expect(groupPick(items, new Set(["a", "b", "c"]))).toBe("all");
    expect([...toggleGroup(items, new Set(["a", "x"]))].sort()).toEqual(["a", "b", "c", "x"]);
    expect([...toggleGroup(items, new Set(["a", "b", "c", "x"]))]).toEqual(["x"]);
  });
});

describe("remembered per view", () => {
  it("keeps each view's choice and falls back to Date and Newest", () => {
    expect(loadChoice("all")).toEqual(DEFAULT_CHOICE);
    saveChoice("all", { group: "project", sort: "longest" });
    saveChoice("inbox", { group: "none", sort: "title" });
    expect(loadChoice("all")).toEqual({ group: "project", sort: "longest" });
    expect(loadChoice("inbox")).toEqual({ group: "none", sort: "title" });
    window.localStorage.setItem("echoledger-library-view", JSON.stringify({ all: { group: "colour", sort: "random" } }));
    expect(loadChoice("all")).toEqual(DEFAULT_CHOICE);
    window.localStorage.setItem("echoledger-library-view", "not json");
    expect(loadChoice("all")).toEqual(DEFAULT_CHOICE);
  });

  it("remembers when recordings were opened, newest kept", () => {
    rememberOpened("a", 1);
    rememberOpened("b", 2);
    expect(loadOpened()).toEqual({ a: 1, b: 2 });
    expect(sortItems(items, "opened", loadOpened()).map((i) => i.id)).toEqual(["b", "a", "c"]);
  });
});
