import { describe, expect, it } from "vitest";
import type { ClientFolder } from "./ipc";
import { ancestors, canAddSubfolder, canMoveTo, depthOf, hasSubfolders, nestedCount, projectLabel, projectRecordings, projectTree, subtreeHeight, subtreeIds, visibleRows } from "./projects";
import { makeItem } from "../test/fixtures";

const p = (id: string, name: string, parent: string | null, extra: Partial<ClientFolder> = {}): ClientFolder => ({ id, name, parent_id: parent, ...extra });
// Acme › Invoices › 2026 › Q1, and Zed beside it.
const LIST = [p("z", "Zed", null), p("a", "Acme", null), p("i", "Invoices", "a"), p("y", "2026", "i"), p("q", "Q1", "y"), p("c", "Contracts", "a", { pinned: true })];
const names = (rows: { project: ClientFolder }[]) => rows.map((r) => r.project.name);

describe("the project tree", () => {
  it("nests subfolders under their parent, pinned first then by name on every level", () => {
    const tree = projectTree(LIST);
    expect(names(tree)).toEqual(["Acme", "Zed"]);
    expect(names(tree[0].children)).toEqual(["Contracts", "Invoices"]);
    expect(tree[0].children[1].children[0].children[0]).toMatchObject({ depth: 4, project: { name: "Q1" } });
  });

  it("shows a project whose parent is unknown, or that sits in a loop, at the top level", () => {
    const odd = [p("a", "Acme", "gone"), p("l1", "Loop one", "l2"), p("l2", "Loop two", "l1")];
    const tree = projectTree(odd);
    expect(names(tree)).toEqual(["Acme", "Loop one"]);
    expect(names(tree[1].children)).toEqual(["Loop two"]);
    expect(visibleRows(tree, "all")).toHaveLength(3);
  });

  it("lists only the expanded projects' folders", () => {
    const tree = projectTree(LIST);
    expect(names(visibleRows(tree, new Set()))).toEqual(["Acme", "Zed"]);
    expect(names(visibleRows(tree, new Set(["a"])))).toEqual(["Acme", "Contracts", "Invoices", "Zed"]);
    expect(names(visibleRows(tree, new Set(["a", "i"])))).toEqual(["Acme", "Contracts", "Invoices", "2026", "Zed"]);
    expect(names(visibleRows(tree, new Set(["i"])))).toEqual(["Acme", "Zed"]);
  });

  it("knows a server that has subfolders by its parent_id", () => {
    expect(hasSubfolders(LIST)).toBe(true);
    expect(hasSubfolders([{ id: "a", name: "Acme" }])).toBe(false);
    expect(hasSubfolders([])).toBe(false);
  });
});

describe("where a project is", () => {
  it("names its ancestors from the top, its level and its place", () => {
    expect(ancestors(LIST, "q")).toEqual([{ id: "a", name: "Acme" }, { id: "i", name: "Invoices" }, { id: "y", name: "2026" }]);
    expect(ancestors(LIST, "a")).toEqual([]);
    expect(depthOf(LIST, "q")).toBe(4);
    expect(depthOf(LIST, "z")).toBe(1);
    expect(projectLabel(LIST, "y")).toBe("Acme › Invoices › 2026");
    expect(projectLabel(LIST, "nope")).toBe("");
  });

  it("falls back to the server's path when a parent is not in the list", () => {
    const partial = [p("i", "Invoices", "a", { path: [{ id: "a", name: "Acme" }] })];
    expect(ancestors(partial, "i")).toEqual([{ id: "a", name: "Acme" }]);
  });
});

describe("moving projects", () => {
  it("collects a project's subtree and its height", () => {
    expect([...subtreeIds(LIST, "a")].sort()).toEqual(["a", "c", "i", "q", "y"]);
    expect(subtreeHeight(LIST, "a")).toBe(4);
    expect(subtreeHeight(LIST, "q")).toBe(1);
  });

  it("never moves a project into itself or one of its own folders", () => {
    expect(canMoveTo(LIST, "i", "i")).toBe(false);
    expect(canMoveTo(LIST, "i", "q")).toBe(false);
    expect(canMoveTo(LIST, "i", null)).toBe(true);
  });

  it("keeps every folder within four levels", () => {
    // Invoices has two levels under it: it may sit at level 2 at most.
    expect(canMoveTo(LIST, "i", "z")).toBe(true);
    expect(canMoveTo(LIST, "i", "c")).toBe(false);
    expect(canMoveTo(LIST, "q", "y")).toBe(true);
    expect(canMoveTo(LIST, "z", "q")).toBe(false);
    expect(canAddSubfolder(LIST, "y")).toBe(true);
    expect(canAddSubfolder(LIST, "q")).toBe(false);
  });
});

describe("a project's recordings", () => {
  const items = [
    makeItem("1", { client: { id: "a", name: "Acme" }, started_at: "2026-10-01T10:00:00Z" }),
    makeItem("2", { client: { id: "i", name: "Invoices" }, started_at: "2026-10-03T10:00:00Z" }),
    makeItem("3", { client: { id: "q", name: "Q1" }, started_at: "2026-10-02T10:00:00Z" }),
    makeItem("4", { client: null, started_at: "2026-10-04T10:00:00Z" }),
    makeItem("5", { client: { id: "a", name: "Acme" }, deleted: true }),
  ];

  it("lists its own, or its own and its folders', newest first", () => {
    expect(projectRecordings(items, LIST, "a", false).map((i) => i.id)).toEqual(["1"]);
    expect(projectRecordings(items, LIST, "a", true).map((i) => i.id)).toEqual(["2", "3", "1"]);
  });

  it("counts with the server's number when it sent one", () => {
    expect(nestedCount(p("a", "Acme", null, { nested_session_count: 9 }), items, LIST)).toBe(9);
    expect(nestedCount(p("a", "Acme", null, { session_count: 1 }), items, LIST)).toBe(3);
  });
});
