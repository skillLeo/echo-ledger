import { describe, expect, it, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import { LibraryColumn } from "./SecondaryColumn";
import type { LibraryPlace } from "../../lib/folders";

const place = (key: string, label: string, count: number, view: LibraryPlace["view"] = null): LibraryPlace => ({ key, label, folder: null, view, count, drop: false });

describe("the Library's folder column", () => {
  it("keeps an empty folder a plain row, never the app's tall empty message (D-023)", () => {
    render(<LibraryColumn places={[place("all", "All", 0), place("waiting", "Waiting", 0, "waiting"), place("pinned", "Pinned", 3, "pinned")]} folder={null} view={null} onOpen={vi.fn()} />);
    const rows = within(screen.getByRole("list", { name: "Smart folders" })).getAllByRole("listitem");
    const waiting = rows.find((r) => r.textContent?.includes("Waiting"))!;
    expect(waiting).toHaveClass("is-empty");
    expect(waiting).not.toHaveClass("empty");
    expect(rows.some((r) => r.classList.contains("empty"))).toBe(false);
  });

  it("has no hint line to be cut at the column's edge; the hint is the Projects heading's tooltip (D-024)", () => {
    const { container } = render(
      <LibraryColumn places={[place("all", "All", 2)]} folder={null} view={null} onOpen={vi.fn()} projects={[{ id: "c1", name: "Acme", parent_id: null }]}
        expanded={new Set()} onToggle={vi.fn()} onProject={vi.fn()} />,
    );
    expect(container.querySelector(".subnav-hint")).toBeNull();
    expect(screen.queryByText(/Drag recordings onto/)).not.toBeInTheDocument();
    expect(screen.getByText("Projects")).toHaveAttribute("title", "Drag recordings onto the Inbox or a project to move them.");
  });
});
