// UX-20: the title row's parts (TitleRow); the shell around it is in shellTitleRow.test.tsx.
import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, within } from "@testing-library/react";
import { useState } from "react";
import { EVERYTHING, SHORT_FIELD, TitleRow, searchFits, type TitleRowProps } from "./TitleRow";

const base: TitleRowProps = { place: "library", title: null, toggle: null, back: null, forward: null, onEverything: () => undefined };
const row = () => screen.getByTestId("title-row");

describe("the title row (UX-20)", () => {
  it("← →, then the page's title and its count; the arrows are greyed when there is nowhere to go", () => {
    const back = vi.fn();
    const { rerender } = render(<TitleRow {...base} title={{ title: "All recordings", count: 219 }} back={back} />);
    const history = within(row()).getByRole("group", { name: "History" });
    expect(within(history).getByRole("button", { name: "Back" })).toBeEnabled();
    expect(within(history).getByRole("button", { name: "Forward" })).toBeDisabled();
    fireEvent.click(within(history).getByRole("button", { name: "Back" }));
    expect(back).toHaveBeenCalled();
    expect(within(row()).getByRole("heading", { level: 1 }).textContent).toBe("All recordings219");
    expect(row().querySelector(".el-titlerow__count")!.textContent).toBe("219");
    rerender(<TitleRow {...base} title={{ title: "Projects", count: null }} />);
    expect(row().querySelector(".el-titlerow__count")).toBeNull();
    expect(within(row()).getByRole("button", { name: "Back" })).toBeDisabled();
    // No title published: no heading in the row (the page keeps its own).
    rerender(<TitleRow {...base} />);
    expect(within(row()).queryByRole("heading")).toBeNull();
  });

  it("the row, its empty parts and the title move the window; buttons and the field never do", () => {
    render(<TitleRow {...base} title={{ title: "All recordings", count: 219, search: { placeholder: "Search recordings", scope: "All recordings", query: "", setQuery: () => undefined } }}
      toggle={{ label: "Show the Library panel", onToggle: () => undefined }} back={() => undefined} end={<button type="button">Restart to update</button>} />);
    // "deep": a press anywhere in it that isn't on something clickable (Tauri's drag.js walks up to the row).
    expect(row()).toHaveAttribute("data-tauri-drag-region", "deep");
    const regions = [...row().querySelectorAll("[data-tauri-drag-region]")].map((el) => [el.className, el.getAttribute("data-tauri-drag-region")]);
    expect(regions).toEqual([["el-titlerow__search", "false"]]);
    // Buttons and the field never carry it (a clickable element with it would drag).
    for (const control of row().querySelectorAll("button, input")) expect(control.hasAttribute("data-tauri-drag-region")).toBe(false);
    expect(row().querySelector(".el-titlerow__search")).toContainElement(row().querySelector("input"));
  });

  it("⊟ comes first, right after the window's buttons, while the panel is off the screen", () => {
    const onToggle = vi.fn();
    render(<TitleRow {...base} toggle={{ label: "Show the Library panel", expanded: false, onToggle }} />);
    const first = row().querySelector("button")!;
    expect(first).toHaveAccessibleName("Show the Library panel");
    expect(first).toHaveAttribute("aria-expanded", "false");
    fireEvent.click(first);
    expect(onToggle).toHaveBeenCalledWith(first);
  });

  it("without a search of the page's own the field names the place and opens the search of everything (⌘K)", () => {
    const onEverything = vi.fn();
    const { rerender } = render(<TitleRow {...base} place="projects" onEverything={onEverything} />);
    const field = within(row()).getByRole("button", { name: /Search projects/ });
    expect(field).toHaveAttribute("aria-keyshortcuts", "Meta+K Control+K");
    expect(field.textContent).toMatch(/K$/);
    fireEvent.click(field);
    expect(onEverything).toHaveBeenCalledWith("");
    rerender(<TitleRow {...base} place="activity" onEverything={onEverything} />);
    expect(within(row()).getByRole("button", { name: new RegExp(EVERYTHING) })).toBeInTheDocument();
  });

  it("with the page's search: typing filters the view; ⌘F shows the scope chip, and its ✕ searches everything instead", () => {
    const onEverything = vi.fn();
    function Page({ seq }: { seq: number }) {
      const [query, setQuery] = useState("");
      return (
        <>
          <TitleRow {...base} onEverything={onEverything} findSeq={seq}
            title={{ title: "All recordings", count: 219, search: { placeholder: "Search recordings", scope: "All recordings", query, setQuery } }} />
          <p data-testid="list">{query || "everything"}</p>
        </>
      );
    }
    const { rerender } = render(<Page seq={0} />);
    const field = screen.getByRole("searchbox", { name: "Search recordings" });
    expect(field).toHaveAttribute("placeholder", "Search recordings");
    expect(row().querySelector(".el-titlerow__scope")).toBeNull();
    // ⌘F (the shell counts the presses): focus in the field, the chip says what it searches.
    rerender(<Page seq={1} />);
    expect(field).toHaveFocus();
    expect(row().querySelector(".el-titlerow__scope")!.textContent).toBe("In: All recordings");
    fireEvent.change(field, { target: { value: "invoice" } });
    expect(screen.getByTestId("list").textContent).toBe("invoice");
    // Esc clears it first, then leaves the field.
    fireEvent.keyDown(field, { key: "Escape" });
    expect(screen.getByTestId("list").textContent).toBe("everything");
    fireEvent.change(field, { target: { value: "invoice" } });
    fireEvent.click(within(row()).getByRole("button", { name: "Search everything instead" }));
    expect(onEverything).toHaveBeenCalledWith("invoice");
    expect(screen.getByTestId("list").textContent).toBe("everything");
  });

  it("⌘F on a page without a search of its own opens the search of everything", () => {
    const onEverything = vi.fn();
    const { rerender } = render(<TitleRow {...base} onEverything={onEverything} />);
    rerender(<TitleRow {...base} onEverything={onEverything} findSeq={1} />);
    expect(onEverything).toHaveBeenCalledWith("");
  });

  it("the field sits at the row's end and needs 180 px and 16 px of drag room beside the whole title, else it is a ⌕ button", () => {
    // `row` is the row's inner width, `start` ⊟ ← → and the whole title with its count, `end` the status items; the
    // two gaps between them are 8 px each.
    expect(searchFits(1000, 300, 100)).toBe(true);
    expect(searchFits(300 + 16 + 180 + 16, 300, 0)).toBe(true, "exactly room for the field's least width and the drag room");
    expect(searchFits(300 + 16 + 180 + 16 - 1, 300, 0)).toBe(false, "a pixel less: the ⌕ button, the title whole");
    // The owner's windows ("All recordi… 253" in 2.0.14): the content column of a 720, 900, 1280 and 1440 px window (the
    // 72 px rail; a docked 240 px panel from 1200 px), less the row's 20 px of padding. The title's group is about 230 px
    // (⊟, ← →, "All recordings 253"); a long project's, about 450 px. The field fits from 720 px with the short title;
    // with the long one at 720 px the ⌕ button keeps its words whole.
    const inner = (window: number) => window - 72 - (window >= 1200 ? 240 : 0) - 20;
    for (const window of [720, 900, 1280, 1440]) expect(searchFits(inner(window), 230, 0), `${window}`).toBe(true);
    expect(searchFits(inner(720), 450, 0)).toBe(false);
    expect(searchFits(inner(1280), 450, 0)).toBe(true);
  });

  it("with no room the field becomes a ⌕ button at the row's end that opens the same search; a cut title says itself", () => {
    // A narrow row: 400 px, the title's group 300 px wide with its long name cut by 120 px (a window under the least
    // width): the field can't fit beside the whole title, so it is the ⌕ button, and the name's tooltip says it all.
    const observers: (() => void)[] = [];
    const Real = globalThis.ResizeObserver;
    globalThis.ResizeObserver = class { constructor(cb: () => void) { observers.push(cb); } observe() {} disconnect() {} } as unknown as typeof ResizeObserver;
    const width = Object.getOwnPropertyDescriptor(HTMLElement.prototype, "clientWidth");
    const scroll = Object.getOwnPropertyDescriptor(HTMLElement.prototype, "scrollWidth");
    const rect = Element.prototype.getBoundingClientRect;
    Object.defineProperty(HTMLElement.prototype, "clientWidth", { configurable: true, get() {
      return this.classList?.contains("el-titlerow") ? 400 : this.classList?.contains("el-titlerow__name") ? 200 : 0;
    } });
    Object.defineProperty(HTMLElement.prototype, "scrollWidth", { configurable: true, get() { return this.classList?.contains("el-titlerow__name") ? 320 : 0; } });
    Element.prototype.getBoundingClientRect = function (this: Element) {
      return { width: this.classList.contains("el-titlerow__start") ? 300 : 0 } as DOMRect;
    };
    try {
      const onEverything = vi.fn();
      const long = "A project with a very long name";
      render(<TitleRow {...base} place="projects" title={{ title: long, count: 12 }} onEverything={onEverything} />);
      expect(row()).toHaveClass("el-titlerow--compact");
      expect(row().querySelector(".el-titlerow__name")).toHaveAttribute("title", long);
      expect(row().querySelector(".el-titlerow__count")).toHaveTextContent("12");
      const button = within(row()).getByRole("button", { name: "Search projects" });
      expect(button).toHaveClass("el-button");
      // At the row's end: after the title's group.
      expect(row().querySelector(".el-titlerow__start")!.compareDocumentPosition(button) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
      fireEvent.click(button);
      expect(onEverything).toHaveBeenCalledWith("");
    } finally {
      globalThis.ResizeObserver = Real;
      Element.prototype.getBoundingClientRect = rect;
      if (width) Object.defineProperty(HTMLElement.prototype, "clientWidth", width);
      if (scroll) Object.defineProperty(HTMLElement.prototype, "scrollWidth", scroll);
    }
  });

  it("a field under 220 px says \"Search\"; its full words stay its name, never cut", () => {
    // The owner's ask: no label loses its words. A narrow field's placeholder is the short word; the page's words
    // ("Search recordings") stay the field's accessible name. Under right-to-left (an Urdu reader) it is the same: the
    // desktop's own words are English.
    const Real = globalThis.ResizeObserver;
    globalThis.ResizeObserver = class { observe() {} disconnect() {} } as unknown as typeof ResizeObserver;
    const width = Object.getOwnPropertyDescriptor(HTMLElement.prototype, "clientWidth");
    let field = SHORT_FIELD - 20;
    Object.defineProperty(HTMLElement.prototype, "clientWidth", { configurable: true, get() {
      return this.classList?.contains("el-titlerow") ? 600 : this.classList?.contains("el-titlerow__middle") ? field : 0;
    } });
    try {
      const props = { ...base, place: "library" as const, title: { title: "All recordings", count: 253, search: { query: "", setQuery: () => undefined, placeholder: "Search recordings", scope: "All recordings" } } };
      const narrow = render(<TitleRow {...props} />);
      expect(within(row()).getByRole("searchbox", { name: "Search recordings" })).toHaveAttribute("placeholder", "Search");
      narrow.unmount();
      field = SHORT_FIELD;
      render(<TitleRow {...props} />);
      expect(within(row()).getByRole("searchbox", { name: "Search recordings" })).toHaveAttribute("placeholder", "Search recordings");
    } finally {
      globalThis.ResizeObserver = Real;
      if (width) Object.defineProperty(HTMLElement.prototype, "clientWidth", width);
    }
  });

  it("the drag regions stay: the row drags (deep), the field never does", () => {
    render(<TitleRow {...base} place="library" title={{ title: "All recordings", count: 253, search: { query: "", setQuery: () => undefined, placeholder: "Search recordings", scope: "All recordings" } }} />);
    expect(row()).toHaveAttribute("data-tauri-drag-region", "deep");
    expect(row().querySelector(".el-titlerow__search")).toHaveAttribute("data-tauri-drag-region", "false");
    expect(row().querySelector(".el-titlerow__name")).not.toHaveAttribute("title");
  });

  it("the ⌘K hint reads left to right in an Urdu window too (never \"K⌘\")", () => {
    render(<div dir="rtl"><TitleRow {...base} /></div>);
    expect(row().querySelector(".el-titlerow__keys")).toHaveAttribute("dir", "ltr");
  });

  it("a hairline under it only once the page has scrolled", () => {
    const { rerender } = render(<TitleRow {...base} />);
    expect(row()).not.toHaveClass("el-titlerow--scrolled");
    rerender(<TitleRow {...base} scrolled />);
    expect(row()).toHaveClass("el-titlerow--scrolled");
  });
});
