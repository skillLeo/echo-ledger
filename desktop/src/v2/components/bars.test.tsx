import { afterEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { useRef, useState } from "react";
import { SelectionBar, type SelectionAction } from "./SelectionBar";
import { SORT_GROUP_OPTIONS, SortGroupMenu, type GroupKey, type SortGroupValue, type SortKey } from "./SortGroupMenu";
import { ITEM_BAR_HEIGHT, StickyItemBar } from "./StickyItemBar";
import { copyMenuItems } from "./copyMenu";
import { BellButton, unreadBadge } from "./BellButton";
import { LABEL_COLOURS, LabelChip, LabelDot, labelColourName } from "./Label";
import { ClockIcon, CopyIcon, LabelIcon, MoveIcon, ShareIcon, SparkleIcon, TranscriptIcon, TrashIcon } from "./icons";

const here = dirname(fileURLToPath(import.meta.url));

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe("SelectionBar", () => {
  const actions = (on: Record<string, () => void> = {}): SelectionAction[] => [
    { id: "move", label: "Move to project", icon: MoveIcon, onPick: on.move },
    { id: "label", label: "Label", icon: LabelIcon, onPick: on.label },
    { id: "share", label: "Share or export", icon: ShareIcon, items: [{ label: "Export as PDF", onPick: on.pdf }, { label: "Export as Word", onPick: on.word }] },
    { id: "delete", label: "Delete", icon: TrashIcon, danger: true, onPick: on.delete },
    { id: "pin", label: "Pin", onPick: on.pin },
    { id: "keep", label: "Keep forever", onPick: on.keep },
    { id: "ai", label: "Copy for AI", icon: SparkleIcon, onPick: on.ai },
  ];
  const MAIN = ["move", "label", "share", "delete"];

  function Host({ total = 5, onExit = () => undefined, on }: { total?: number; onExit?: () => void; on?: Record<string, () => void> }) {
    const [picked, setPicked] = useState(2);
    return (
      <>
        <SelectionBar label="Selected recordings" count={picked} allSelected={picked === total} onExit={onExit}
          onToggleAll={() => setPicked(picked === total ? 0 : total)} actions={actions(on)} main={MAIN} />
        <button type="button" onClick={() => setPicked(picked + 1)}>Pick one more</button>
        <input aria-label="Search recordings" />
      </>
    );
  }

  it("is a named toolbar: ×, the count, Select all, the four main actions and ⋮, in that order", () => {
    render(<Host />);
    const bar = screen.getByRole("toolbar", { name: "Selected recordings" });
    const names = within(bar).getAllByRole("button").map((b) => b.getAttribute("aria-label") ?? b.textContent);
    expect(names).toEqual(["Cancel selection", "Select all", "Move to project", "Label", "Share or export", "Delete", "More actions"]);
    expect(within(bar).getByRole("heading", { level: 1 })).toHaveTextContent("2 selected");
    expect(within(bar).getByRole("button", { name: "Delete" })).toHaveClass("el-selection-bar__danger");
  });

  it("announces the count politely as it changes", () => {
    render(<Host />);
    const title = screen.getByRole("heading", { name: "2 selected" });
    expect(title).toHaveAttribute("aria-live", "polite");
    expect(title).toHaveAttribute("aria-atomic", "true");
    fireEvent.click(screen.getByRole("button", { name: "Pick one more" }));
    expect(title).toHaveTextContent("3 selected");
    fireEvent.click(screen.getByRole("button", { name: "Select all" }));
    expect(title).toHaveTextContent("5 selected");
    expect(screen.getByRole("button", { name: "Unselect all" })).toBeInTheDocument();
  });

  it("⋮ holds only the actions without their own button", () => {
    const pin = vi.fn();
    render(<Host on={{ pin }} />);
    fireEvent.click(screen.getByRole("button", { name: "More actions" }));
    const menu = screen.getByRole("menu", { name: "More actions" });
    expect(within(menu).getAllByRole("menuitem").map((i) => i.textContent)).toEqual(["Pin", "Keep forever", "Copy for AI"]);
    fireEvent.click(within(menu).getByRole("menuitem", { name: "Pin" }));
    expect(pin).toHaveBeenCalledTimes(1);
  });

  it("gives at most four actions a button, and a main action with items opens its own menu", () => {
    const pdf = vi.fn();
    render(<SelectionBar label="Selected" count={1} allSelected={false} onToggleAll={() => undefined} onExit={() => undefined}
      actions={actions({ pdf })} main={["move", "label", "share", "delete", "pin"]} />);
    expect(screen.queryByRole("button", { name: "Pin" })).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Share or export" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Export as PDF" }));
    expect(pdf).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByRole("button", { name: "More actions" }));
    expect(within(screen.getByRole("menu", { name: "More actions" })).getAllByRole("menuitem")[0]).toHaveTextContent("Pin");
  });

  it("Esc leaves selection mode, from the bar or the page; not while a menu or a field has the key", () => {
    const onExit = vi.fn();
    render(<Host onExit={onExit} />);
    fireEvent.keyDown(screen.getByRole("button", { name: "Pick one more" }), { key: "Escape" });
    expect(onExit).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByRole("button", { name: "More actions" }));
    fireEvent.keyDown(screen.getByRole("menu"), { key: "Escape" });
    expect(screen.queryByRole("menu")).toBeNull();
    expect(onExit).toHaveBeenCalledTimes(1);
    fireEvent.keyDown(screen.getByRole("textbox", { name: "Search recordings" }), { key: "Escape" });
    expect(onExit).toHaveBeenCalledTimes(1);
    fireEvent.keyDown(screen.getByRole("button", { name: "Delete" }), { key: "Escape" });
    expect(onExit).toHaveBeenCalledTimes(2);
    fireEvent.click(screen.getByRole("button", { name: "Cancel selection" }));
    expect(onExit).toHaveBeenCalledTimes(3);
  });

  it("the arrow keys, Home and End move along the toolbar", () => {
    render(<Host />);
    const close = screen.getByRole("button", { name: "Cancel selection" });
    close.focus();
    fireEvent.keyDown(close, { key: "ArrowRight" });
    expect(screen.getByRole("button", { name: "Select all" })).toHaveFocus();
    fireEvent.keyDown(document.activeElement!, { key: "End" });
    expect(screen.getByRole("button", { name: "More actions" })).toHaveFocus();
    fireEvent.keyDown(document.activeElement!, { key: "ArrowLeft" });
    expect(screen.getByRole("button", { name: "Delete" })).toHaveFocus();
    fireEvent.keyDown(document.activeElement!, { key: "Home" });
    expect(close).toHaveFocus();
  });

  it("with nothing picked the title asks for a pick and the actions can't be pressed", () => {
    render(<SelectionBar label="Selected" count={0} allSelected={false} onToggleAll={() => undefined} onExit={() => undefined} actions={actions()} main={MAIN} />);
    expect(screen.getByRole("heading")).toHaveTextContent("Select items");
    for (const name of ["Move to project", "Label", "Share or export", "Delete", "More actions"]) expect(screen.getByRole("button", { name })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Select all" })).toBeEnabled();
  });
});

describe("SortGroupMenu", () => {
  function Host({ onChange = () => undefined }: { onChange?: (v: SortGroupValue<SortKey, GroupKey>) => void }) {
    const [value, setValue] = useState<SortGroupValue<SortKey, GroupKey>>({ sort: "newest", group: "date", density: "comfortable" });
    return <SortGroupMenu value={value} options={SORT_GROUP_OPTIONS} onChange={(v) => { setValue(v); onChange(v); }} />;
  }

  it("is a named button whose menu has three radio sets under their headers, the current choice ticked in each", () => {
    render(<Host />);
    fireEvent.click(screen.getByRole("button", { name: "Sort and group" }));
    const menu = screen.getByRole("menu", { name: "Sort and group" });
    expect(within(menu).getAllByRole("menuitemradio").map((i) => i.textContent)).toEqual(
      ["Newest", "Oldest", "Longest", "Name A–Z", "Date", "Source", "Project", "None", "Comfortable", "Compact"]);
    for (const header of ["Sort", "Group by", "Density"]) expect(within(menu).getByText(header)).toBeInTheDocument();
    const checked = within(menu).getAllByRole("menuitemradio").filter((i) => i.getAttribute("aria-checked") === "true").map((i) => i.textContent);
    expect(checked).toEqual(["Newest", "Date", "Comfortable"]);
  });

  it("calls onChange with the whole new value and stays open for the next pick", () => {
    const onChange = vi.fn();
    render(<Host onChange={onChange} />);
    fireEvent.click(screen.getByRole("button", { name: "Sort and group" }));
    fireEvent.click(screen.getByRole("menuitemradio", { name: "Longest" }));
    expect(onChange).toHaveBeenLastCalledWith({ sort: "longest", group: "date", density: "comfortable" });
    expect(screen.getByRole("menuitemradio", { name: "Longest" })).toHaveAttribute("aria-checked", "true");
    expect(screen.getByRole("menuitemradio", { name: "Newest" })).toHaveAttribute("aria-checked", "false");
    fireEvent.click(screen.getByRole("menuitemradio", { name: "Compact" }));
    expect(onChange).toHaveBeenLastCalledWith({ sort: "longest", group: "date", density: "compact" });
    fireEvent.click(screen.getByRole("menuitemradio", { name: "Compact" }));
    expect(onChange).toHaveBeenCalledTimes(2);
  });

  it("takes a screen's own option lists", () => {
    render(<SortGroupMenu value={{ sort: "newest", group: "none", density: "compact" }} onChange={() => undefined}
      options={{ ...SORT_GROUP_OPTIONS, group: SORT_GROUP_OPTIONS.group.filter((o) => o.value !== "project") }} />);
    fireEvent.click(screen.getByRole("button", { name: "Sort and group" }));
    expect(screen.queryByRole("menuitemradio", { name: "Project" })).toBeNull();
    expect(screen.getByRole("menuitemradio", { name: "None" })).toHaveAttribute("aria-checked", "true");
  });
});

describe("StickyItemBar", () => {
  /** An IntersectionObserver that the test drives: report(true) while the header is in view, report(false) once it is out. */
  function fakeObserver() {
    const made: { cb: IntersectionObserverCallback; options?: IntersectionObserverInit; target?: Element }[] = [];
    class IO {
      constructor(cb: IntersectionObserverCallback, options?: IntersectionObserverInit) { made.push({ cb, options }); }
      observe(el: Element) { made[made.length - 1].target = el; }
      disconnect() { /* nothing to undo */ }
    }
    vi.stubGlobal("IntersectionObserver", IO);
    const report = (inView: boolean, top = inView ? 100 : -80) => act(() => {
      const last = made[made.length - 1];
      last.cb([{ isIntersecting: inView, boundingClientRect: { top } as DOMRectReadOnly, rootBounds: { top: 0 } as DOMRectReadOnly } as IntersectionObserverEntry], {} as IntersectionObserver);
    });
    return { made, report };
  }

  function Page({ withMenu = false }: { withMenu?: boolean }) {
    const head = useRef<HTMLElement>(null);
    return (
      <>
        <StickyItemBar title="Payment terms for the March shipment" onBack={() => undefined} sentinel={head}
          menu={withMenu ? { label: "Recording menu", items: [{ label: "Rename" }, { label: "Delete", danger: true }] } : undefined} />
        <header ref={head}><h1>Payment terms for the March shipment</h1></header>
      </>
    );
  }

  it("shows only once the item's own header has scrolled out above", () => {
    const io = fakeObserver();
    render(<Page />);
    expect(io.made[0].target?.tagName).toBe("HEADER");
    expect(screen.queryByRole("button", { name: "Back" })).toBeNull();
    io.report(false);
    expect(screen.getByRole("button", { name: "Back" })).toBeVisible();
    io.report(true);
    expect(screen.queryByRole("button", { name: "Back" })).toBeNull();
    // Out of sight below the view (not reached yet) is not scrolled past.
    io.report(false, 900);
    expect(screen.queryByRole("button", { name: "Back" })).toBeNull();
  });

  it("takes the top bar's height off the top of the view", () => {
    document.documentElement.style.setProperty("--topbar-h", "48px");
    const io = fakeObserver();
    render(<Page />);
    expect(io.made[0].options?.rootMargin).toBe("-48px 0% 0% 0%");
    document.documentElement.style.removeProperty("--topbar-h");
  });

  it("always shows where IntersectionObserver is missing, with Back, the title and the ⋮ menu", () => {
    vi.stubGlobal("IntersectionObserver", undefined);
    const onBack = vi.fn();
    render(<StickyItemBar title="ادائیگی کا منصوبہ، قرض کی واپسی" onBack={onBack} menu={{ label: "Recording menu", items: [{ label: "Rename" }] }} />);
    fireEvent.click(screen.getByRole("button", { name: "Back" }));
    expect(onBack).toHaveBeenCalledTimes(1);
    const title = screen.getByText("ادائیگی کا منصوبہ، قرض کی واپسی");
    expect(title).toHaveAttribute("dir", "auto");
    expect(title).toHaveClass("el-itembar__title");
    expect(screen.getByRole("button", { name: "Back" }).querySelector(".el-icon")).toHaveClass("el-icon--directional");
    fireEvent.click(screen.getByRole("button", { name: "Recording menu" }));
    expect(screen.getByRole("menuitem", { name: "Rename" })).toBeInTheDocument();
  });

  it("publishes its drawn height as --item-bar-h while mounted: 0 while hidden, cleared after", () => {
    vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(function (this: HTMLElement) {
      return { height: this.classList.contains("el-itembar__bar") ? 44 : 0 } as DOMRect;
    });
    const root = document.documentElement;
    const io = fakeObserver();
    const { unmount } = render(<Page withMenu />);
    expect(root.style.getPropertyValue(ITEM_BAR_HEIGHT)).toBe("0px");
    io.report(false);
    expect(root.style.getPropertyValue(ITEM_BAR_HEIGHT)).toBe("44px");
    io.report(true);
    expect(root.style.getPropertyValue(ITEM_BAR_HEIGHT)).toBe("0px");
    unmount();
    expect(root.style.getPropertyValue(ITEM_BAR_HEIGHT)).toBe("");
  });

  it("follows its height with a ResizeObserver when there is one", () => {
    let height = 44;
    vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(() => ({ height } as DOMRect));
    const watchers: ResizeObserverCallback[] = [];
    vi.stubGlobal("ResizeObserver", class {
      constructor(cb: ResizeObserverCallback) { watchers.push(cb); }
      observe() { /* driven by the test */ }
      disconnect() { /* nothing to undo */ }
    });
    vi.stubGlobal("IntersectionObserver", undefined);
    render(<StickyItemBar title="Weekly sync with Northwind" onBack={() => undefined} />);
    expect(document.documentElement.style.getPropertyValue(ITEM_BAR_HEIGHT)).toBe("44px");
    height = 60;
    act(() => watchers[0]([], {} as ResizeObserver));
    expect(document.documentElement.style.getPropertyValue(ITEM_BAR_HEIGHT)).toBe("60px");
  });
});

describe("copyMenuItems", () => {
  it("makes the Copy menu's four items with icons, in order, each running its own handler", () => {
    const on = { text: vi.fn(), withTimestamps: vi.fn(), withoutTimestamps: vi.fn(), forAi: vi.fn() };
    const items = copyMenuItems(on);
    expect(items.map((i) => i.label)).toEqual(["Copy text", "Copy with timestamps", "Copy without timestamps", "Copy for AI"]);
    expect(items.map((i) => i.icon)).toEqual([CopyIcon, ClockIcon, TranscriptIcon, SparkleIcon]);
    items.forEach((i) => i.onPick?.());
    for (const fn of Object.values(on)) expect(fn).toHaveBeenCalledTimes(1);
  });

  it("takes its words and a group, and leaves out an action with no handler", () => {
    const items = copyMenuItems({ text: () => undefined, forAi: () => undefined }, { text: "متن کاپی کریں" }, "Copy");
    expect(items.map((i) => i.label)).toEqual(["متن کاپی کریں", "Copy for AI"]);
    expect(items.every((i) => i.group === "Copy")).toBe(true);
  });
});

describe("BellButton", () => {
  it.each([
    [0, "Activity", null],
    [3, "Activity, 3 unread", "3"],
    [120, "Activity, 120 unread", "99+"],
  ] as const)("with %i unread is named %s and shows %s", (unread, name, badge) => {
    const { container } = render(<BellButton unread={unread} />);
    const button = screen.getByRole("button", { name });
    const shown = container.querySelector(".el-bell__badge");
    if (badge === null) expect(shown).toBeNull();
    else {
      expect(shown).toHaveTextContent(badge);
      expect(shown).toHaveClass("el-num");
      expect(shown).toHaveAttribute("aria-hidden", "true");
    }
    expect(button.querySelector("[class*=pulse], [class*=anim]")).toBeNull();
  });

  it("takes its words and passes the press on", () => {
    const onClick = vi.fn();
    render(<BellButton unread={2} label="سرگرمی" unreadLabel={(n) => `سرگرمی، ${n} نئی`} onClick={onClick} />);
    fireEvent.click(screen.getByRole("button", { name: "سرگرمی، 2 نئی" }));
    expect(onClick).toHaveBeenCalledTimes(1);
    expect(unreadBadge(99)).toBe("99");
    expect(unreadBadge(100)).toBe("99+");
  });
});

describe("LabelDot and LabelChip", () => {
  it("LABEL_COLOURS are the stored values in tokens.css (--el-label-<name>)", () => {
    const css = readFileSync(join(here, "..", "..", "tokens.css"), "utf8");
    const inTokens = Object.fromEntries([...css.matchAll(/--el-label-([a-z]+):\s*(#[0-9A-Fa-f]{6});/g)].map((m) => [m[1], m[2].toLowerCase()]));
    expect(Object.keys(inTokens).sort()).toEqual(["amber", "blue", "green", "grey", "orange", "pink", "red", "teal"]);
    expect(Object.fromEntries(Object.entries(LABEL_COLOURS).map(([k, v]) => [k, v.toLowerCase()]))).toEqual(inTokens);
    for (const name of Object.keys(LABEL_COLOURS)) {
      for (const part of ["fill", "text", "dot"]) expect(css).toContain(`--el-role-label-${name}-${part}:`);
    }
  });

  it("finds a colour's name in any letter case, and nothing for another value", () => {
    expect(labelColourName(LABEL_COLOURS.teal.toLowerCase())).toBe("teal");
    expect(labelColourName(` ${LABEL_COLOURS.pink.toUpperCase()} `)).toBe("pink");
    expect(labelColourName("#7a3fd1")).toBeNull();
    expect(labelColourName("")).toBeNull();
  });

  it("a known colour draws with its role tokens and no inline colour", () => {
    const { container } = render(<LabelChip name="Supplier" colour={LABEL_COLOURS.teal.toLowerCase()} />);
    const chip = container.querySelector(".el-label-chip")!;
    expect(chip).toHaveClass("el-label--teal");
    expect(chip.getAttribute("style")).toBeNull();
    expect(chip).toHaveTextContent("Supplier");
  });

  it("an unknown colour is only the dot's, on the neutral chip; never text or fill", () => {
    const own = "#7a3fd1";
    const { container } = render(<LabelChip name="Old label" colour={own} />);
    const chip = container.querySelector<HTMLElement>(".el-label-chip")!;
    expect(chip).toHaveClass("el-label--other");
    expect(chip.style.getPropertyValue("--label-own")).toBe(own);
    expect(chip.style.color).toBe("");
    expect(chip.style.background).toBe("");
    const css = readFileSync(join(here, "..", "styles", "v2.css"), "utf8");
    const uses = css.split("\n").filter((l) => l.includes("--label-own"));
    expect(uses).toEqual([expect.stringMatching(/^\.el-label--other \{.*--label-dot: var\(--label-own, /)]);
  });

  it("a value that isn't #rrggbb is not used at all", () => {
    const { container } = render(<LabelChip name="Odd" colour="url(x)" />);
    const chip = container.querySelector(".el-label-chip")!;
    expect(chip).toHaveClass("el-label--other");
    expect(chip.getAttribute("style")).toBeNull();
  });

  it("the dot alone is named by its label; a chip can be removed with its own button", () => {
    const onRemove = vi.fn();
    render(<><LabelDot name="Supplier" colour={LABEL_COLOURS.green} /><LabelChip name="Urgent" colour={LABEL_COLOURS.red} onRemove={onRemove} /></>);
    expect(screen.getByRole("img", { name: "Supplier" })).toHaveClass("el-label-dot", "el-label--green");
    fireEvent.click(screen.getByRole("button", { name: "Remove label: Urgent" }));
    expect(onRemove).toHaveBeenCalledTimes(1);
  });
});
