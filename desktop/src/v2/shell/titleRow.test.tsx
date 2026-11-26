// UX-20: the title row's parts (TitleRow); the shell around it is in shellTitleRow.test.tsx.
import { describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
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
    // The keys' hint is drawn (FIX-06, label in name); they are the field's aria-keyshortcuts.
    expect(field.querySelector(".el-titlerow__keys")).toHaveAttribute("data-keys", expect.stringMatching(/K$/));
    expect(readFileSync(join(dirname(fileURLToPath(import.meta.url)), "../styles/v2.css"), "utf8")).toContain(".el-titlerow__keys::before { content: attr(data-keys); }");
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

  it("with no room the field becomes a ⌕ button at the row's end that opens the same search; the title is never cut", () => {
    // A narrow row: 400 px, the title's group 300 px wide on one line: the field can't fit beside the whole title, so it
    // is the ⌕ button, and the title keeps every word (it wraps, or takes a line of its own; no ellipsis, so no
    // tooltip to say a cut title: the rule changed in review, the title used to be cut with its words in a tooltip).
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
      expect(row().querySelector(".el-titlerow__name")).toHaveTextContent(long);
      expect(row().querySelector(".el-titlerow__name")).not.toHaveAttribute("title");
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

  it("beside the scope's chip, a placeholder that would be cut says \"Search\"; with room, and without the chip, its full words", () => {
    // The field's own room is less than its box's once the chip shows ("ریکارڈنگز میں تلاش کریں" was cut to
    // "ریکارڈنگز میں ت"): its words are measured in its font against that room. Its name keeps the full words.
    const Real = globalThis.ResizeObserver;
    globalThis.ResizeObserver = class { observe() {} disconnect() {} } as unknown as typeof ResizeObserver;
    const width = Object.getOwnPropertyDescriptor(HTMLElement.prototype, "clientWidth");
    const context = vi.spyOn(HTMLCanvasElement.prototype, "getContext")
      .mockReturnValue({ font: "", measureText: (words: string) => ({ width: words.length * 8 }) } as unknown as CanvasRenderingContext2D);
    let room = 100;
    Object.defineProperty(HTMLElement.prototype, "clientWidth", { configurable: true, get() {
      return this.classList?.contains("el-titlerow") ? 900 : this.classList?.contains("el-titlerow__middle") ? 400
        : this.classList?.contains("el-titlerow__field") ? room : 0;
    } });
    try {
      const props = { ...base, place: "library" as const, title: { title: "All recordings", count: 253, search: { query: "", setQuery: () => undefined, placeholder: "Search recordings", scope: "All recordings" } } };
      const view = render(<TitleRow {...props} />);
      const field = within(row()).getByRole("searchbox", { name: "Search recordings" });
      expect(field).toHaveAttribute("placeholder", "Search recordings");
      act(() => field.focus());
      expect(row().querySelector(".el-titlerow__scope")).not.toBeNull();
      expect(field).toHaveAttribute("placeholder", "Search");
      act(() => field.blur());
      expect(field).toHaveAttribute("placeholder", "Search recordings");
      view.unmount();
      room = 300;
      render(<TitleRow {...props} />);
      const roomy = within(row()).getByRole("searchbox", { name: "Search recordings" });
      act(() => roomy.focus());
      expect(roomy).toHaveAttribute("placeholder", "Search recordings");
    } finally {
      context.mockRestore();
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

  it("narrow (decision 18's narrow case, under 720 CSS px): Search (Ctrl+F) opens the field across the row, the title aside only while it is open; Esc or leaving it empty closes it", () => {
    const wide = window.innerWidth;
    Object.defineProperty(window, "innerWidth", { value: 360, configurable: true, writable: true });
    try {
      const onEverything = vi.fn();
      function Page({ seq }: { seq: number }) {
        const [query, setQuery] = useState("");
        return (
          <>
            <TitleRow {...base} onEverything={onEverything} findSeq={seq}
              title={{ title: "All recordings", count: 11, search: { placeholder: "Search recordings", scope: "All recordings", query, setQuery } }} />
            <button type="button">Elsewhere</button>
          </>
        );
      }
      const { rerender } = render(<Page seq={0} />);
      expect(row()).toHaveClass("el-titlerow--narrow");
      expect(row().querySelector(".el-titlerow__name")).toHaveTextContent("All recordings");
      expect(row().querySelector(".el-titlerow__name")).not.toHaveAttribute("title");
      expect(within(row()).queryByRole("searchbox")).toBeNull();
      const search = within(row()).getByRole("button", { name: "Search" });
      expect(search).toHaveAttribute("title", "Search (Ctrl+F)");
      expect(search).toHaveAttribute("aria-keyshortcuts", "Meta+F Control+F");
      // Open: the field across the row, focused; the title steps aside.
      fireEvent.click(search);
      const field = within(row()).getByRole("searchbox", { name: "Search recordings" });
      expect(field).toHaveFocus();
      expect(row()).toHaveClass("el-titlerow--searching");
      // Esc on an empty field closes it, and focus goes back to Search.
      fireEvent.keyDown(field, { key: "Escape" });
      expect(within(row()).queryByRole("searchbox")).toBeNull();
      expect(row()).not.toHaveClass("el-titlerow--searching");
      expect(within(row()).getByRole("button", { name: "Search" })).toHaveFocus();
      // Ctrl+F (the shell counts the presses) opens it too; with words in it, leaving keeps it open.
      rerender(<Page seq={1} />);
      expect(within(row()).getByRole("searchbox", { name: "Search recordings" })).toHaveFocus();
      fireEvent.change(within(row()).getByRole("searchbox"), { target: { value: "invoice" } });
      fireEvent.blur(within(row()).getByRole("searchbox"));
      expect(within(row()).getByRole("searchbox")).toHaveValue("invoice");
      // Emptied and left: closed (focus stays where it went).
      fireEvent.change(within(row()).getByRole("searchbox"), { target: { value: "" } });
      fireEvent.blur(within(row()).getByRole("searchbox"));
      expect(within(row()).queryByRole("searchbox")).toBeNull();
      // Wide again: the field as before.
      Object.defineProperty(window, "innerWidth", { value: 1280, configurable: true, writable: true });
      act(() => { window.dispatchEvent(new Event("resize")); });
      expect(row()).not.toHaveClass("el-titlerow--narrow");
      expect(within(row()).getByRole("searchbox", { name: "Search recordings" })).toBeInTheDocument();
    } finally {
      Object.defineProperty(window, "innerWidth", { value: wide, configurable: true, writable: true });
    }
  });

  it("no room beside the whole title at 720 px or wider, with the page's search: ⌕ opens the field across the row (Ctrl+F); Esc closes it back", () => {
    // The row is 400 px and the title's group 300 px on one line: no room for the field, so the ⌕ button, the title whole
    // beside it. The field then opens across the row as in the narrow case, never beside a squeezed title.
    const Real = globalThis.ResizeObserver;
    globalThis.ResizeObserver = class { observe() {} disconnect() {} } as unknown as typeof ResizeObserver;
    const width = Object.getOwnPropertyDescriptor(HTMLElement.prototype, "clientWidth");
    const rect = Element.prototype.getBoundingClientRect;
    Object.defineProperty(HTMLElement.prototype, "clientWidth", { configurable: true, get() { return this.classList?.contains("el-titlerow") ? 400 : 0; } });
    Element.prototype.getBoundingClientRect = function (this: Element) {
      return { width: this.classList.contains("el-titlerow__start") ? 300 : 0 } as DOMRect;
    };
    try {
      function Page() {
        const [query, setQuery] = useState("");
        return <TitleRow {...base} place="library" title={{ title: "Follow up with Qureshi Textiles about the March shipment", count: 12, search: { placeholder: "Search recordings", scope: "All recordings", query, setQuery } }} />;
      }
      render(<Page />);
      expect(row()).toHaveClass("el-titlerow--compact");
      expect(row()).not.toHaveClass("el-titlerow--narrow");
      expect(row().querySelector(".el-titlerow__name")).not.toHaveAttribute("title");
      const button = within(row()).getByRole("button", { name: "Search recordings" });
      expect(button).toHaveAttribute("title", "Search recordings (Ctrl+F)");
      expect(button).toHaveAttribute("aria-keyshortcuts", "Meta+F Control+F");
      fireEvent.click(button);
      const field = within(row()).getByRole("searchbox", { name: "Search recordings" });
      expect(field).toHaveFocus();
      expect(row()).toHaveClass("el-titlerow--searching");
      // Esc with words clears them and keeps it open; Esc again closes it back to the button.
      fireEvent.change(field, { target: { value: "invoice" } });
      fireEvent.keyDown(field, { key: "Escape" });
      expect(within(row()).getByRole("searchbox")).toHaveValue("");
      fireEvent.keyDown(within(row()).getByRole("searchbox"), { key: "Escape" });
      expect(within(row()).queryByRole("searchbox")).toBeNull();
      expect(row()).not.toHaveClass("el-titlerow--searching");
      expect(within(row()).getByRole("button", { name: "Search recordings" })).toHaveFocus();
    } finally {
      globalThis.ResizeObserver = Real;
      Element.prototype.getBoundingClientRect = rect;
      if (width) Object.defineProperty(HTMLElement.prototype, "clientWidth", width);
    }
  });

  it("words typed stay in sight: the field stays when the window narrows while it has them", () => {
    const wide = window.innerWidth;
    Object.defineProperty(window, "innerWidth", { value: 1280, configurable: true, writable: true });
    try {
      function Page() {
        const [query, setQuery] = useState("");
        return <TitleRow {...base} title={{ title: "All recordings", count: 11, search: { placeholder: "Search recordings", scope: "All recordings", query, setQuery } }} />;
      }
      render(<Page />);
      fireEvent.change(within(row()).getByRole("searchbox", { name: "Search recordings" }), { target: { value: "invoice" } });
      fireEvent.blur(within(row()).getByRole("searchbox"));
      // Zoomed in: the window is now under the least width, and the list is still filtered by "invoice".
      Object.defineProperty(window, "innerWidth", { value: 360, configurable: true, writable: true });
      act(() => { window.dispatchEvent(new Event("resize")); });
      expect(row()).toHaveClass("el-titlerow--narrow");
      expect(within(row()).getByRole("searchbox", { name: "Search recordings" })).toHaveValue("invoice");
      // Emptied and left: back to Search.
      fireEvent.change(within(row()).getByRole("searchbox"), { target: { value: "" } });
      fireEvent.blur(within(row()).getByRole("searchbox"));
      expect(within(row()).queryByRole("searchbox")).toBeNull();
      expect(within(row()).getByRole("button", { name: "Search" })).toBeInTheDocument();
    } finally {
      Object.defineProperty(window, "innerWidth", { value: wide, configurable: true, writable: true });
    }
  });

  it("typed fast, every key stays: the page's query comes back late (through the title slot) and never undoes one", async () => {
    vi.useFakeTimers();
    try {
      // The page answers a moment later, as the shell's title slot does (a publish, then a render); each view keeps its
      // own words (Needs you has "invo"), and the page can clear them itself (the no-match state's Clear search).
      function Page({ view }: { view: string }) {
        const [queries, setQueries] = useState<Record<string, string>>({ "Needs you": "invo" });
        const late = (q: string) => window.setTimeout(() => setQueries((all) => ({ ...all, [view]: q })), 30);
        return (
          <>
            <TitleRow {...base} title={{ title: view, count: 11, search: { placeholder: "Search recordings", scope: view, query: queries[view] ?? "", setQuery: late } }} />
            <button type="button" onClick={() => setQueries((all) => ({ ...all, [view]: "" }))}>Clear search</button>
          </>
        );
      }
      const { rerender } = render(<Page view="All recordings" />);
      const field = within(row()).getByRole("searchbox", { name: "Search recordings" });
      let typed = "";
      for (const letter of "invoice") {
        typed += letter;
        fireEvent.change(field, { target: { value: typed } });
        // The page answers with an older word while the next key is pressed.
        act(() => { vi.advanceTimersByTime(10); });
      }
      expect(field).toHaveValue("invoice");
      act(() => { vi.advanceTimersByTime(200); });
      expect(field).toHaveValue("invoice");
      // A change from the page itself is taken at once: Clear search, and another view with its own words (even words
      // the field sent a moment ago, "invo").
      fireEvent.click(screen.getByRole("button", { name: "Clear search" }));
      expect(field).toHaveValue("");
      fireEvent.change(field, { target: { value: "invoice" } });
      act(() => { vi.advanceTimersByTime(200); });
      rerender(<Page view="Needs you" />);
      expect(within(row()).getByRole("searchbox", { name: "Search recordings" })).toHaveValue("invo");
      // Esc empties it.
      fireEvent.keyDown(within(row()).getByRole("searchbox"), { key: "Escape" });
      act(() => { vi.advanceTimersByTime(200); });
      expect(within(row()).getByRole("searchbox")).toHaveValue("");
    } finally {
      vi.useRealTimers();
    }
  });

  it("narrow, without a search of the page's own: Search opens the search of everything", () => {
    const wide = window.innerWidth;
    Object.defineProperty(window, "innerWidth", { value: 360, configurable: true, writable: true });
    try {
      const onEverything = vi.fn();
      render(<TitleRow {...base} title={{ title: "Activity", count: null }} onEverything={onEverything} />);
      fireEvent.click(within(row()).getByRole("button", { name: "Search" }));
      expect(onEverything).toHaveBeenCalledWith("");
    } finally {
      Object.defineProperty(window, "innerWidth", { value: wide, configurable: true, writable: true });
    }
  });
});
