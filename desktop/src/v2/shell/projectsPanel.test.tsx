// Projects (UX-09, PLAN_V2_1_POLISH): the panel is the project list as a chat app's channels (Pinned first, the rest
// under Projects, Archived folded at the bottom, a search from 11 projects); "All projects" is the page's title, never a
// panel row; Recent is a sort option; with no project there is no panel and the page has one empty state.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { flushAsync, resetTauriMock } from "../../test/tauri";
import type { ClientFolder } from "../../lib/ipc";
import { ProjectsPanel, type PanelProject, type ProjectList } from "./NavRail";
import { Shell } from "./Shell";
import { ProjectsListV2, NO_PROJECTS } from "../workspace/ProjectsListV2";
import { PROJECT_SORT_KEY, projectOrder, resetProjectSortForTests } from "./projectSort";
import { setProjectsAccount } from "./kept";
import { noteRecentProject, recentProjects } from "./recentProjects";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const PROJECTS: PanelProject[] = [
  { id: "p1", name: "Acme", pinned: true },
  { id: "p2", name: "Contracts", parent_id: "p1" },
  { id: "p3", name: "Zed" },
  { id: "p4", name: "Bilal Traders" },
  { id: "p5", name: "Old client", archived: true },
  // A pinned folder of a project that isn't: under Pinned only.
  { id: "p6", name: "Zed invoices", parent_id: "p3", pinned: true },
];
const ACCOUNT = "ledger.example.test|ayesha@example.test|w1";
/** A plain tree: each list its own <ul>, its top level in the list's order, folders after their project. */
const tree = (list: readonly PanelProject[], _current: string | null, open: (id: string) => void, how: ProjectList) => {
  const ids = new Set(list.map((p) => p.id));
  const top = list.filter((p) => !p.parent_id || !ids.has(p.parent_id)).sort(how.order);
  const rows = top.flatMap((p) => [p, ...list.filter((c) => c.parent_id === p.id)]);
  return <ul aria-label={how.label}>{rows.map((p) => <li key={p.id}><button type="button" className="el-nav-tree__name" onClick={() => open(p.id)}>{p.name}</button></li>)}</ul>;
};
const names = (list: string) => within(screen.getByRole("list", { name: list })).getAllByRole("button").map((b) => b.textContent);

beforeEach(() => {
  resetTauriMock();
  localStorage.clear();
  setProjectsAccount(ACCOUNT);
  resetProjectSortForTests();
});

describe("the Projects panel (UX-09)", () => {
  it("DT-NAV-005: Pinned first, then the rest under Projects, Archived folded last; no All projects, Pinned or Recent rows", () => {
    // The rule changed with UX-09: the panel had All projects, Pinned and Recent as rows of views, New project and Request files.
    const onOpen = vi.fn();
    const onAll = vi.fn();
    const onNew = vi.fn();
    render(<ProjectsPanel projects={PROJECTS} current={null} allCurrent={false} onAll={onAll} onOpen={onOpen} onNew={onNew} tree={tree} />);
    expect(names("Pinned")).toEqual(["Acme", "Contracts", "Zed invoices"]);
    // The pinned folder isn't under its project in Projects as well.
    expect(names("Projects")).toEqual(["Bilal Traders", "Zed"]);
    expect(screen.queryByRole("list", { name: "Archived" })).toBeNull();
    for (const row of [/^Recent/, "Request files"]) expect(screen.queryByRole("button", { name: row })).toBeNull();
    // Archived, folded at the bottom: a group title like the others (a heading), with its count.
    const archived = screen.getByRole("heading", { level: 3, name: /^Archived/ });
    const fold = within(archived).getByRole("button", { name: "Archived 1" });
    expect(fold).toHaveAttribute("aria-expanded", "false");
    fireEvent.click(fold);
    expect(names("Archived")).toEqual(["Old client"]);
    // The group's title is the way to All projects (the page), and says so; + makes a project.
    const all = screen.getByRole("button", { name: "All projects" });
    expect(all).toHaveTextContent("Projects");
    expect(all.closest("h3")).not.toBeNull();
    fireEvent.click(all);
    expect(onAll).toHaveBeenCalled();
    fireEvent.click(screen.getByRole("button", { name: "New project" }));
    expect(onNew).toHaveBeenCalled();
    fireEvent.click(within(screen.getByRole("list", { name: "Projects" })).getByRole("button", { name: "Zed" }));
    expect(onOpen).toHaveBeenCalledWith("p3");
  });

  it("Recent is a sort option (each account's own on this computer): the projects opened lately first, pinned still first", () => {
    noteRecentProject("p1");
    noteRecentProject("p3");
    expect(localStorage.getItem(`echoledger-recent-projects:${ACCOUNT}`)).toBe(JSON.stringify(["p3", "p1"]));
    render(<ProjectsPanel projects={PROJECTS} current={null} allCurrent onAll={() => undefined} onOpen={() => undefined} tree={tree} />);
    const sort = screen.getByRole("button", { name: "Sort projects" });
    expect(sort).toHaveAttribute("title", "Sort projects: Name");
    expect(sort).toHaveAttribute("aria-haspopup", "menu");
    fireEvent.click(sort);
    const menu = screen.getByRole("menu", { name: "Sort projects" });
    expect(within(menu).getAllByRole("menuitemradio").map((i) => i.textContent)).toEqual(["Name", "Recent"]);
    fireEvent.click(within(menu).getByRole("menuitemradio", { name: "Recent" }));
    expect(names("Projects")).toEqual(["Zed", "Bilal Traders"]);
    expect(screen.getByRole("button", { name: "Sort projects" })).toHaveAttribute("title", "Sort projects: Recent");
    // Each account's own (kept per account, like the panels' widths).
    expect(localStorage.getItem(`${PROJECT_SORT_KEY}:${ACCOUNT}`)).toBe("recent");
    expect(localStorage.getItem(PROJECT_SORT_KEY)).toBeNull();
    // Another account signs in: its own sort (Name) and Recent (none); back again, Recent.
    act(() => setProjectsAccount("ledger.example.test|sara@example.test|w1"));
    expect(screen.getByRole("button", { name: "Sort projects" })).toHaveAttribute("title", "Sort projects: Name");
    expect(recentProjects()).toEqual([]);
    act(() => setProjectsAccount(ACCOUNT));
    expect(screen.getByRole("button", { name: "Sort projects" })).toHaveAttribute("title", "Sort projects: Recent");
    // Pinned first, then the ones opened lately (newest first), then the rest by name.
    expect([...PROJECTS].sort(projectOrder("recent", ["p3", "p1"])).map((p) => p.id)).toEqual(["p1", "p6", "p3", "p4", "p2", "p5"]);
  });

  it("a search box from 11 projects (more than 10): its matches in one list", () => {
    const many = Array.from({ length: 11 }, (_, i) => ({ id: `x${i}`, name: `Client ${i + 1}` }));
    render(<ProjectsPanel projects={many} current={null} allCurrent={false} onAll={() => undefined} onOpen={() => undefined} tree={tree} />);
    const onOpen = vi.fn();
    cleanup();
    render(<ProjectsPanel projects={many} current={null} allCurrent={false} onAll={() => undefined} onOpen={onOpen} tree={tree} />);
    const field = screen.getByRole("searchbox", { name: "Search projects" });
    fireEvent.change(field, { target: { value: "client 1" } });
    expect(names("Matches")).toEqual(["Client 1", "Client 10", "Client 11"]);
    // ↓ goes into the matches; Enter opens the first.
    fireEvent.keyDown(field, { key: "ArrowDown" });
    expect(within(screen.getByRole("list", { name: "Matches" })).getByRole("button", { name: "Client 1" })).toHaveFocus();
    fireEvent.keyDown(field, { key: "Enter" });
    expect(onOpen).toHaveBeenCalledWith("x0");
    fireEvent.change(screen.getByRole("searchbox", { name: "Search projects" }), { target: { value: "nobody" } });
    expect(screen.getByText("No project matches")).toBeInTheDocument();
  });

  it("while the projects are read: shapes of rows under Projects; offline with none read: it says so", () => {
    const { rerender } = render(<ProjectsPanel projects={[]} current={null} allCurrent={false} onAll={() => undefined} onOpen={() => undefined} tree={tree} loading />);
    expect(screen.getByRole("status")).toHaveTextContent("Loading projects");
    rerender(<ProjectsPanel projects={[]} current={null} allCurrent={false} onAll={() => undefined} onOpen={() => undefined} tree={tree} error="offline" />);
    expect(screen.getByRole("status")).toHaveTextContent("Couldn't load the projects.");
  });

  it("ten projects have no search box", () => {
    render(<ProjectsPanel projects={PROJECTS.slice(0, 4)} current={null} allCurrent={false} onAll={() => undefined} onOpen={() => undefined} tree={tree} />);
    expect(screen.queryByRole("searchbox")).toBeNull();
  });

  it("with no project there is no Projects panel", () => {
    // A window wide enough for the panel beside the page (below 1100 px it is a flyout).
    Object.defineProperty(window, "innerWidth", { value: 1440, configurable: true, writable: true });
    const shell = (noProjects: boolean) => (
      <Shell tab="projects" onGo={() => undefined} status={{ kind: "ready", text: "Ready" }} projectTree={noProjects ? [] : PROJECTS} onProject={() => undefined}
        library={<p>Library views</p>} nav={{ noProjects }}><p>The page</p></Shell>
    );
    const { rerender } = render(shell(false));
    expect(screen.getByTestId("nav-panel")).toBeInTheDocument();
    rerender(shell(true));
    expect(screen.queryByTestId("nav-panel")).toBeNull();
    Object.defineProperty(window, "innerWidth", { value: 1024, configurable: true, writable: true });
  });
});

describe("the Projects panel in the app's shell (the real tree)", () => {
  const shell = () => (
    <Shell tab="projects" onGo={() => undefined} status={{ kind: "ready", text: "Ready" }} projectTree={PROJECTS} onProject={() => undefined}
      library={<p>Library views</p>} nav={{ layoutFor: ACCOUNT }}><p>The page</p></Shell>
  );
  const rows = (list: string) => [...screen.getByRole("list", { name: list }).querySelectorAll(".el-nav-tree__name")].map((b) => b.textContent);

  it("Pinned, then Projects in the sort's order, folders under their project; arrows move from project to project and fold", () => {
    Object.defineProperty(window, "innerWidth", { value: 1440, configurable: true, writable: true });
    try {
      localStorage.setItem(`echoledger-recent-projects:${ACCOUNT}`, JSON.stringify(["p3"]));
      render(shell());
      expect(rows("Pinned")).toEqual(["Acme", "Contracts", "Zed invoices"]);
      expect(rows("Projects")).toEqual(["Bilal Traders", "Zed"]);
      fireEvent.click(screen.getByRole("button", { name: "Sort projects" }));
      fireEvent.click(within(screen.getByRole("menu", { name: "Sort projects" })).getByRole("menuitemradio", { name: "Recent" }));
      expect(rows("Projects")).toEqual(["Zed", "Bilal Traders"]);
      // ↓ from Acme to its folder, End to the last project, ← folds Acme's folders (from the row itself).
      const acme = within(screen.getByRole("list", { name: "Pinned" })).getByRole("button", { name: "Acme" });
      acme.focus();
      fireEvent.keyDown(acme, { key: "ArrowDown" });
      expect(within(screen.getByRole("list", { name: "Pinned" })).getByRole("button", { name: "Contracts" })).toHaveFocus();
      fireEvent.keyDown(document.activeElement!, { key: "End" });
      expect(within(screen.getByRole("list", { name: "Projects" })).getByRole("button", { name: "Bilal Traders" })).toHaveFocus();
      fireEvent.keyDown(acme, { key: "ArrowLeft" });
      expect(rows("Pinned")).toEqual(["Acme", "Zed invoices"]);
      fireEvent.keyDown(acme, { key: "ArrowRight" });
      expect(rows("Pinned")).toEqual(["Acme", "Contracts", "Zed invoices"]);
    } finally {
      Object.defineProperty(window, "innerWidth", { value: 1024, configurable: true, writable: true });
    }
  });
});

describe("All projects (UX-09)", () => {
  const FOLDERS = [
    { id: "p1", name: "Acme", pinned: true, parent_id: null }, { id: "p2", name: "Contracts", parent_id: "p1" },
    { id: "p3", name: "Zed", parent_id: null }, { id: "p4", name: "Bilal Traders", parent_id: null },
  ] as unknown as ClientFolder[];
  const page = (projects: ClientFolder[] | null, over: Partial<Parameters<typeof ProjectsListV2>[0]> = {}) => render(
    <ProjectsListV2 projects={projects} error={null} onRetry={() => undefined} items={[]} expanded={new Set()} onToggle={() => undefined}
      onOpen={() => undefined} onNew={() => undefined} onRequest={() => undefined} overview {...over} />,
  );

  it("is the page's title; its cards pinned first, sorted as the panel is; Sort, Request files and New project at the top", async () => {
    const onOpen = vi.fn();
    const onNew = vi.fn();
    const onRequest = vi.fn();
    page(FOLDERS, { onOpen, onNew, onRequest });
    await act(flushAsync);
    expect(screen.getByRole("heading", { level: 1, name: "All projects" })).toBeInTheDocument();
    const cards = () => within(screen.getByRole("list", { name: "Projects" })).getAllByRole("button").map((c) => c.querySelector(".el-project-card__name")?.textContent);
    expect(cards()).toEqual(["Acme", "Bilal Traders", "Zed"]);
    // Each card says what it holds: pinned (for screen readers too), its recordings and last call, its folders.
    const said = within(screen.getByRole("list", { name: "Projects" })).getAllByRole("button").map((c) => c.textContent);
    expect(said).toEqual([expect.stringMatching(/^AAcme, pinned.*1 folder/), expect.stringMatching(/^BTBilal Traders(?!, pinned)/), expect.stringMatching(/^ZZed(?!, pinned)/)]);
    expect(said.every((t) => /No recordings yet|recording/.test(t ?? ""))).toBe(true);
    // The page is never the tree (its folders are on the panel and the project's page).
    expect(screen.queryByRole("button", { name: /folders in/ })).toBeNull();
    noteRecentProject("p3");
    fireEvent.click(screen.getByRole("button", { name: "Sort projects: Name" }));
    fireEvent.click(within(screen.getByRole("menu", { name: "Sort projects" })).getByRole("menuitemradio", { name: "Recent" }));
    expect(cards()).toEqual(["Acme", "Zed", "Bilal Traders"]);
    fireEvent.click(screen.getByRole("button", { name: "Request files" }));
    fireEvent.click(screen.getByRole("button", { name: "New project" }));
    expect([onRequest, onNew].every((f) => f.mock.calls.length === 1)).toBe(true);
  });

  it("with no project: one empty state, New project the main button and Request files from a client", async () => {
    const onNew = vi.fn();
    const onRequest = vi.fn();
    page([], { onNew, onRequest });
    await act(flushAsync);
    const empty = screen.getByRole("region", { name: NO_PROJECTS });
    expect(NO_PROJECTS).toBe("Keep each client's calls, files and notes together");
    const buttons = within(empty).getAllByRole("button");
    expect(buttons.map((b) => b.textContent)).toEqual(["New project", "Request files from a client"]);
    expect(buttons[0]).toHaveClass("el-button--primary");
    fireEvent.click(buttons[1]);
    expect(onRequest).toHaveBeenCalled();
    // Nothing else asks for a project: no Sort, no second New project.
    expect(screen.getAllByRole("button", { name: "New project" })).toHaveLength(1);
    expect(screen.queryByRole("button", { name: /^Sort projects/ })).toBeNull();
  });
});
