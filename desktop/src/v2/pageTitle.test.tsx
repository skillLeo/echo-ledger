// The title row's slot (PLAN_V2_1_POLISH UX-20): a page publishes its title, count and search; the shell shows the
// page mounted last; with no shell providing the slot the page keeps its own header.
import { describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { StrictMode, useState, type ReactNode } from "react";
import { PageTitleSlot, usePageTitle, usePageTitleSlot, type PageTitle } from "./pageTitle";

function Row({ children }: { children: ReactNode }) {
  const [title, slot] = usePageTitleSlot();
  return (
    <PageTitleSlot.Provider value={slot}>
      <header data-testid="row">
        {title ? `${title.title}${title.count === null ? "" : ` ${title.count}`}` : "(none)"}
        {title?.search && <input aria-label={title.search.placeholder} value={title.search.query} onChange={(e) => title.search!.setQuery(e.target.value)} />}
        {title?.search && <span data-testid="scope">In: {title.search.scope}</span>}
      </header>
      {children}
    </PageTitleSlot.Provider>
  );
}

function Page({ t }: { t: PageTitle | null }) {
  const shown = usePageTitle(t);
  return <p data-testid="own">{shown ? "in the row" : "own header"}</p>;
}

function Searching({ onQuery }: { onQuery?: (q: string) => void }) {
  const [query, setQuery] = useState("");
  usePageTitle({ title: "All recordings", count: 219, search: { placeholder: "Search recordings", scope: "All recordings", query,
    setQuery: (q) => { onQuery?.(q); setQuery(q); } } });
  return <p data-testid="list">{query ? `filtered by ${query}` : "everything"}</p>;
}

const row = () => screen.getByTestId("row").textContent;

describe("the title row's slot", () => {
  it("shows the published title and count, and says so to the page", () => {
    render(<Row><Page t={{ title: "All recordings", count: 219 }} /></Row>);
    expect(row()).toBe("All recordings 219");
    expect(screen.getByTestId("own").textContent).toBe("in the row");
  });

  it("with no slot (no title row) the page keeps its own header", () => {
    render(<Page t={{ title: "All recordings", count: 219 }} />);
    expect(screen.getByTestId("own").textContent).toBe("own header");
  });

  it("a count of null shows the title alone; a null title shows nothing", () => {
    const { rerender } = render(<Row><Page t={{ title: "Projects", count: null }} /></Row>);
    expect(row()).toBe("Projects");
    rerender(<Row><Page t={null} /></Row>);
    expect(row()).toBe("(none)");
  });

  it("the page mounted last wins, and the one before comes back when it goes", () => {
    const { rerender } = render(<Row><Page t={{ title: "All recordings", count: 219 }} /></Row>);
    rerender(<Row><Page t={{ title: "All recordings", count: 219 }} /><Page t={{ title: "Trash", count: 3 }} /></Row>);
    expect(row()).toBe("Trash 3");
    // The first page changes in place: it keeps its turn, so the last mounted still shows.
    rerender(<Row><Page t={{ title: "Needs you", count: 10 }} /><Page t={{ title: "Trash", count: 3 }} /></Row>);
    expect(row()).toBe("Trash 3");
    rerender(<Row><Page t={{ title: "Needs you", count: 10 }} /></Row>);
    expect(row()).toBe("Needs you 10");
  });

  it("follows the page's count and title as they change", () => {
    const { rerender } = render(<Row><Page t={{ title: "All recordings", count: 219 }} /></Row>);
    rerender(<Row><Page t={{ title: "All recordings", count: 220 }} /></Row>);
    expect(row()).toBe("All recordings 220");
  });

  it("the row's field runs the page's search: its placeholder, its scope and its query", () => {
    const onQuery = vi.fn();
    render(<Row><Searching onQuery={onQuery} /></Row>);
    const field = screen.getByLabelText("Search recordings");
    expect(screen.getByTestId("scope").textContent).toBe("In: All recordings");
    fireEvent.change(field, { target: { value: "invoice" } });
    expect(onQuery).toHaveBeenCalledWith("invoice");
    expect(screen.getByTestId("list").textContent).toBe("filtered by invoice");
    expect((screen.getByLabelText("Search recordings") as HTMLInputElement).value).toBe("invoice");
  });

  it("always calls the page's newest setQuery, even when nothing else changed", () => {
    const calls: string[] = [];
    function Versioned({ v }: { v: number }) {
      usePageTitle({ title: "All recordings", count: 1, search: { placeholder: "Search recordings", scope: "All recordings", query: "",
        setQuery: (q) => calls.push(`v${v}:${q}`) } });
      return null;
    }
    const { rerender } = render(<Row><Versioned v={1} /></Row>);
    rerender(<Row><Versioned v={2} /></Row>);
    fireEvent.change(screen.getByLabelText("Search recordings"), { target: { value: "a" } });
    expect(calls).toEqual(["v2:a"]);
  });

  it("an unchanged title does not render the row again", () => {
    let renders = 0;
    function Counting({ children }: { children: ReactNode }) {
      const [title, slot] = usePageTitleSlot();
      renders++;
      return <PageTitleSlot.Provider value={slot}><span>{title?.title}</span>{children}</PageTitleSlot.Provider>;
    }
    function Tick() {
      const [n, setN] = useState(0);
      usePageTitle({ title: "Activity", count: 4 });
      return <button type="button" onClick={() => setN(n + 1)}>tick {n}</button>;
    }
    render(<Counting><Tick /></Counting>);
    const before = renders;
    act(() => screen.getByRole("button").click());
    act(() => screen.getByRole("button").click());
    expect(renders).toBe(before);
  });

  it("works in strict mode (effects run twice) and withdraws on unmount", () => {
    const { rerender } = render(<StrictMode><Row><Page t={{ title: "Activity", count: 4 }} /></Row></StrictMode>);
    expect(row()).toBe("Activity 4");
    rerender(<StrictMode><Row>{null}</Row></StrictMode>);
    expect(row()).toBe("(none)");
  });
});
