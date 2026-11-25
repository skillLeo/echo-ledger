// Projects (UX-09, PLAN_V2_1_POLISH): the panel is the project list as a chat app's channels (Pinned first, the rest
// under Projects, Archived folded at the bottom, a search from 11 projects); "All projects" is the page's title, never a
// panel row; Recent is a sort option; with no project there is no panel and the page has one empty state.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { flushAsync, resetTauriMock } from "../../test/tauri";
import type { ClientFolder } from "../../lib/ipc";
import { ProjectsPanel, type PanelProject, type ProjectList } from "./NavRail";
import { Shell } from "./Shell";
import { ProjectsListV2, NO_PROJECTS } from "../workspace/ProjectsListV2";
import { PROJECT_SORT_KEY, projectOrder, resetProjectSortForTests } from "./projectSort";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const PROJECTS: PanelProject[] = [
  { id: "p1", name: "Acme", pinned: true },
  { id: "p2", name: "Contracts", parent_id: "p1" },
  { id: "p3", name: "Zed" },
  { id: "p4", name: "Bilal Traders" },
  { id: "p5", name: "Old client", archived: true },
];
/** A plain tree: each list its own <ul>, its top level in the list's order, folders after their project. */
const tree = (list: readonly PanelProject[], _current: string | null, open: (id: string) => void, how: ProjectList) => {
  const ids = new Set(list.map((p) => p.id));
  const top = list.filter((p) => !p.parent_id || !ids.has(p.parent_id)).sort(how.order);
  const rows = top.flatMap((p) => [p, ...list.filter((c) => c.parent_id === p.id)]);
  return <ul aria-label={how.label}>{rows.map((p) => <li key={p.id}><button type="button" onClick={() => open(p.id)}>{p.name}</button></li>)}</ul>;
};
const names = (list: string) => within(screen.getByRole("list", { name: list })).getAllByRole("button").map((b) => b.textContent);

beforeEach(() => {
  resetTauriMock();
  localStorage.clear();
  resetProjectSortForTests();
});

describe("the Projects panel (UX-09)", () => {
  it("DT-NAV-005: Pinned first, then the rest under Projects, Archived folded last; no All projects, Pinned or Recent rows", () => {
    // The rule changed with UX-09: the panel had All projects, Pinned and Recent as rows of views, New project and Request files.
    const onOpen = vi.fn();
    const onAll = vi.fn();
    const onNew = vi.fn();
    render(<ProjectsPanel projects={PROJECTS} current={null} allCurrent={false} onAll={onAll} onOpen={onOpen} onNew={onNew} tree={tree} />);
    expect(names("Pinned")).toEqual(["Acme", "Contracts"]);
    expect(names("Projects")).toEqual(["Bilal Traders", "Zed"]);
    expect(screen.queryByRole("list", { name: "Archived" })).toBeNull();
    for (const row of [/^All projects/, /^Recent/, "Request files"]) expect(screen.queryByRole("button", { name: row })).toBeNull();
    // Archived, folded at the bottom.
    fireEvent.click(screen.getByRole("button", { name: "Archived" }));
    expect(names("Archived")).toEqual(["Old client"]);
    // The group's title is the way to All projects (the page); + makes a project.
    fireEvent.click(screen.getByRole("button", { name: "Projects" }));
    expect(onAll).toHaveBeenCalled();
    fireEvent.click(screen.getByRole("button", { name: "New project" }));
    expect(onNew).toHaveBeenCalled();
    fireEvent.click(within(screen.getByRole("list", { name: "Projects" })).getByRole("button", { name: "Zed" }));
    expect(onOpen).toHaveBeenCalledWith("p3");
  });

  it("Recent is a sort option (kept on this computer): the projects opened lately first, pinned still first", () => {
    localStorage.setItem("echoledger-recent-projects", JSON.stringify(["p3", "p1"]));
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
    expect(localStorage.getItem(PROJECT_SORT_KEY)).toBe("recent");
    // Pinned first, then the ones opened lately (newest first), then the rest by name.
    expect([...PROJECTS].sort(projectOrder("recent", ["p3", "p1"])).map((p) => p.id)).toEqual(["p1", "p3", "p4", "p2", "p5"]);
  });

  it("a search box from 11 projects (more than 10): its matches in one list", () => {
    const many = Array.from({ length: 11 }, (_, i) => ({ id: `x${i}`, name: `Client ${i + 1}` }));
    render(<ProjectsPanel projects={many} current={null} allCurrent={false} onAll={() => undefined} onOpen={() => undefined} tree={tree} />);
    fireEvent.change(screen.getByRole("searchbox", { name: "Search projects" }), { target: { value: "client 1" } });
    expect(names("Matches")).toEqual(["Client 1", "Client 10", "Client 11"]);
    fireEvent.change(screen.getByRole("searchbox", { name: "Search projects" }), { target: { value: "nobody" } });
    expect(screen.getByText("No project matches")).toBeInTheDocument();
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
    localStorage.setItem("echoledger-recent-projects", JSON.stringify(["p3"]));
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
