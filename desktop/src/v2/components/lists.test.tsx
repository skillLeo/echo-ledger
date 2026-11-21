import { afterEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { useState } from "react";
import { LIST_ROW_WRAP_FROM, ListChip, ListChipRow } from "./ListChip";
import { COLOUR_NAMES, COLOUR_ORDER, ColourPicker } from "./ColourPicker";
import { NewListDialog, nextListColour } from "./NewListDialog";
import { addToListItems, deleteListWords, listChipMenuItems, listDotIcon } from "./listMenus";
import { ContextMenu, Menu } from "./Menu";
import { LABEL_COLOURS, type LabelColour } from "./Label";
import { PlusIcon, TrashIcon } from "./icons";
import * as Components from "./index";

const here = dirname(fileURLToPath(import.meta.url));
const css = readFileSync(join(here, "..", "styles", "v2.css"), "utf8");

afterEach(() => {
  vi.restoreAllMocks();
  Object.assign(window, { innerWidth: 1024 });
});

describe("ListChip", () => {
  it("is a toggle button with the list's dot and name; selected, it shows a check", () => {
    const { rerender } = render(<ListChip name="Clients" colour={LABEL_COLOURS.teal} selected={false} onClick={() => undefined} />);
    const chip = screen.getByRole("button", { name: "Clients" });
    expect(chip).toHaveAttribute("aria-pressed", "false");
    expect(chip).toHaveClass("el-chip", "el-listchip");
    const dot = chip.querySelector(".el-label-dot");
    expect(dot).toHaveClass("el-label--teal");
    expect(dot).toHaveAttribute("aria-hidden", "true");
    expect(chip.querySelector(".el-icon")).toBeNull();
    rerender(<ListChip name="Clients" colour={LABEL_COLOURS.teal} selected onClick={() => undefined} />);
    expect(chip).toHaveAttribute("aria-pressed", "true");
    expect(chip.querySelector(".el-icon")).not.toBeNull();
    expect(chip.querySelector(".el-label-dot")).not.toBeNull();
  });

  it("the pressed look is the filter chip's: accent-soft fill and accent text", () => {
    expect(css).toMatch(/\.el-chip\[aria-pressed="true"\] \{\s*background: var\(--el-role-accent-soft\);[^}]*color: var\(--el-role-accent-text\);/);
  });

  it("a fixed chip has no dot and no menu; only it shows a count, and only above 0", () => {
    const menu = vi.fn();
    const { rerender } = render(<ListChip name="Unread" fixed count={2} selected={false} onClick={() => undefined} onMenu={menu} />);
    const chip = screen.getByRole("button", { name: "Unread 2" });
    expect(chip.querySelector(".el-label-dot")).toBeNull();
    fireEvent.contextMenu(chip);
    fireEvent.keyDown(chip, { key: "F10", shiftKey: true });
    expect(menu).not.toHaveBeenCalled();
    rerender(<ListChip name="Unread" fixed count={0} selected={false} onClick={() => undefined} />);
    expect(screen.getByRole("button", { name: "Unread" })).toBeInTheDocument();
    rerender(<ListChip name="Clients" colour={LABEL_COLOURS.teal} count={7} selected={false} onClick={() => undefined} />);
    expect(screen.getByRole("button", { name: "Clients" }).querySelector(".el-chip__count")).toBeNull();
  });

  it("a press selects; a right-click opens the menu at the pointer; Shift+F10 and the context-menu key under the chip", () => {
    const click = vi.fn();
    const menu = vi.fn();
    render(<ListChip name="Home" colour={LABEL_COLOURS.amber} selected={false} onClick={click} onMenu={menu} />);
    const chip = screen.getByRole("button", { name: "Home" });
    fireEvent.click(chip);
    expect(click).toHaveBeenCalledTimes(1);
    const right = fireEvent.contextMenu(chip, { clientX: 140, clientY: 60 });
    expect(right).toBe(false); // the browser's own menu is held back
    expect(menu).toHaveBeenLastCalledWith({ x: 140, y: 60 });
    vi.spyOn(chip, "getBoundingClientRect").mockReturnValue({ left: 100, top: 40, width: 80, height: 28, bottom: 68, right: 180, x: 100, y: 40, toJSON: () => ({}) });
    fireEvent.keyDown(chip, { key: "F10", shiftKey: true });
    expect(menu).toHaveBeenLastCalledWith({ x: 120, y: 68 });
    fireEvent.keyDown(chip, { key: "ContextMenu" });
    expect(menu).toHaveBeenCalledTimes(3);
    fireEvent.keyDown(chip, { key: "F10" });
    expect(menu).toHaveBeenCalledTimes(3);
    expect(click).toHaveBeenCalledTimes(1);
  });

  it("draws a colour outside the eight as a dot only, and no colour as the neutral dot", () => {
    render(<>
      <ListChip name="Older" colour={`#${"5B7C99"}`} selected={false} onClick={() => undefined} />
      <ListChip name="Plain" colour={null} selected={false} onClick={() => undefined} />
    </>);
    const older = screen.getByRole("button", { name: "Older" }).querySelector(".el-label-dot") as HTMLElement;
    expect(older).toHaveClass("el-label--other");
    expect(older.style.getPropertyValue("--label-own")).toBe(`#${"5B7C99"}`);
    expect(screen.getByRole("button", { name: "Plain" }).querySelector(".el-label-dot")).toHaveClass("el-label--other");
  });
});

describe("ListChipRow", () => {
  function Row({ lists = ["Clients", "Home", "Family"], initial = "All", onAdd = () => undefined }: { lists?: string[]; initial?: string; onAdd?: () => void }) {
    const [on, setOn] = useState(initial);
    return (
      <ListChipRow label="Lists" onAdd={onAdd} addLabel="New list">
        {["All", "Unread", "Important"].map((n) => <ListChip key={n} name={n} fixed count={n === "Unread" ? 2 : undefined} selected={on === n} onClick={() => setOn(n)} />)}
        {lists.map((n) => <ListChip key={n} name={n} colour={LABEL_COLOURS.teal} selected={on === n} onClick={() => setOn(n)} />)}
      </ListChipRow>
    );
  }

  it("is a named group: the fixed chips, the lists in their order, then +", () => {
    const add = vi.fn();
    render(<Row onAdd={add} />);
    const row = screen.getByRole("group", { name: "Lists" });
    const names = within(row).getAllByRole("button").map((b) => b.getAttribute("aria-label") ?? b.textContent);
    expect(names).toEqual(["All", "Unread 2", "Important", "Clients", "Home", "Family", "New list"]);
    const plus = within(row).getByRole("button", { name: "New list" });
    expect(plus).toHaveAttribute("title", "New list");
    expect(plus.parentElement).toBe(row); // outside the scroller: always in view, always last
    fireEvent.click(plus);
    expect(add).toHaveBeenCalledTimes(1);
  });

  it("one chip is selected at a time", () => {
    render(<Row />);
    fireEvent.click(screen.getByRole("button", { name: "Home" }));
    const pressed = screen.getAllByRole("button", { pressed: true });
    expect(pressed.map((b) => b.textContent)).toEqual(["Home"]);
  });

  it("scrolls below 1280 px and wraps from 1280 px, following the window", () => {
    expect(LIST_ROW_WRAP_FROM).toBe(1280);
    Object.assign(window, { innerWidth: 1279 });
    render(<Row />);
    const row = screen.getByRole("group", { name: "Lists" });
    expect(row).not.toHaveClass("el-listchips--wrap");
    act(() => {
      Object.assign(window, { innerWidth: 1280 });
      window.dispatchEvent(new Event("resize"));
    });
    expect(row).toHaveClass("el-listchips--wrap");
    act(() => {
      Object.assign(window, { innerWidth: 800 });
      window.dispatchEvent(new Event("resize"));
    });
    expect(row).not.toHaveClass("el-listchips--wrap");
  });

  it("the CSS: one line that scrolls without a fade or scrollbar; from 1280 px chips and + wrap together", () => {
    expect(css).toMatch(/\.el-listchips__scroll \{[^}]*flex-wrap: nowrap;[^}]*overflow-x: auto;[^}]*scrollbar-width: none;/);
    expect(css).toContain(".el-listchips--wrap { flex-wrap: wrap; }");
    expect(css).toContain(".el-listchips--wrap > .el-listchips__scroll { display: contents; }");
    expect(css).not.toMatch(/\.el-listchips[^{]*\{[^}]*mask/);
  });

  it("brings the selected chip into the row's view when the selection changes", () => {
    render(<Row lists={["Clients", "Home", "Family", "Suppliers", "Lahore office"]} />);
    const row = screen.getByRole("group", { name: "Lists" });
    const scroller = row.querySelector(".el-listchips__scroll") as HTMLElement;
    const far = screen.getByRole("button", { name: "Lahore office" });
    vi.spyOn(scroller, "getBoundingClientRect").mockReturnValue({ left: 0, right: 400, top: 0, bottom: 28, width: 400, height: 28, x: 0, y: 0, toJSON: () => ({}) });
    vi.spyOn(far, "getBoundingClientRect").mockReturnValue({ left: 520, right: 640, top: 0, bottom: 28, width: 120, height: 28, x: 520, y: 0, toJSON: () => ({}) });
    scroller.scrollLeft = 0;
    fireEvent.click(far);
    expect(scroller.scrollLeft).toBe(240);
  });

  it("right to left the order mirrors with the direction, and + is still the last button", () => {
    render(<div dir="rtl" lang="ur"><Row lists={["کلائنٹس", "گھر", "خاندان"]} /></div>);
    const names = within(screen.getByRole("group", { name: "Lists" })).getAllByRole("button").map((b) => b.getAttribute("aria-label") ?? b.textContent);
    expect(names.at(-1)).toBe("New list");
    expect(names.slice(3, 6)).toEqual(["کلائنٹس", "گھر", "خاندان"]);
    // Logical properties only: nothing in the row's CSS names a left or right side.
    const rules = css.slice(css.indexOf("/* ---- Lists (D14)"), css.indexOf("/* Urdu never goes below"));
    expect(rules).not.toMatch(/\b(left|right)\b/);
  });
});

describe("ColourPicker", () => {
  function Picker({ initial = "teal" as LabelColour, names }: { initial?: LabelColour; names?: Record<LabelColour, string> }) {
    const [value, setValue] = useState<LabelColour>(initial);
    return <ColourPicker label="Colour" value={value} onChange={setValue} names={names} />;
  }

  it("is one radio group of the eight colours, each named for its colour", () => {
    render(<Picker />);
    const group = screen.getByRole("radiogroup", { name: "Colour" });
    const radios = within(group).getAllByRole("radio");
    expect(radios.map((r) => r.getAttribute("aria-label"))).toEqual(["Grey", "Red", "Orange", "Amber", "Green", "Teal", "Blue", "Pink"]);
    expect(COLOUR_ORDER).toEqual(Object.keys(LABEL_COLOURS));
    expect(Object.values(COLOUR_NAMES)).toEqual(["Grey", "Red", "Orange", "Amber", "Green", "Teal", "Blue", "Pink"]);
    expect(radios.filter((r) => r.getAttribute("aria-checked") === "true").map((r) => r.getAttribute("aria-label"))).toEqual(["Teal"]);
    expect(radios.map((r) => r.querySelector(".el-label-dot")?.className.match(/el-label--(\w+)/)?.[1])).toEqual(COLOUR_ORDER);
  });

  it("is one tab stop on the chosen colour; the chosen dot has a check", () => {
    render(<Picker initial="amber" />);
    const radios = screen.getAllByRole("radio");
    expect(radios.map((r) => r.tabIndex)).toEqual([-1, -1, -1, 0, -1, -1, -1, -1]);
    expect(screen.getByRole("radio", { name: "Amber" }).querySelector(".el-icon")).not.toBeNull();
    expect(screen.getByRole("radio", { name: "Red" }).querySelector(".el-icon")).toBeNull();
  });

  it("a press chooses; arrow keys move and choose, wrapping; Home and End go to the ends", () => {
    render(<Picker initial="grey" />);
    fireEvent.click(screen.getByRole("radio", { name: "Blue" }));
    expect(screen.getByRole("radio", { name: "Blue" })).toHaveAttribute("aria-checked", "true");
    const key = (k: string) => fireEvent.keyDown(document.activeElement ?? document.body, { key: k });
    screen.getByRole("radio", { name: "Blue" }).focus();
    key("ArrowRight");
    expect(document.activeElement).toBe(screen.getByRole("radio", { name: "Pink", checked: true }));
    key("ArrowRight");
    expect(document.activeElement).toBe(screen.getByRole("radio", { name: "Grey", checked: true }));
    key("ArrowLeft");
    expect(document.activeElement).toBe(screen.getByRole("radio", { name: "Pink", checked: true }));
    key("ArrowUp");
    expect(document.activeElement).toBe(screen.getByRole("radio", { name: "Blue", checked: true }));
    key("ArrowDown");
    expect(document.activeElement).toBe(screen.getByRole("radio", { name: "Pink", checked: true }));
    key("Home");
    expect(document.activeElement).toBe(screen.getByRole("radio", { name: "Grey", checked: true }));
    key("End");
    expect(document.activeElement).toBe(screen.getByRole("radio", { name: "Pink", checked: true }));
    expect(screen.getAllByRole("radio").filter((r) => r.tabIndex === 0)).toHaveLength(1);
  });

  it("right to left, ArrowLeft moves on and ArrowRight moves back", () => {
    render(<div dir="rtl"><Picker initial="red" /></div>);
    screen.getByRole("radio", { name: "Red" }).focus();
    fireEvent.keyDown(document.activeElement!, { key: "ArrowLeft" });
    expect(document.activeElement).toBe(screen.getByRole("radio", { name: "Orange", checked: true }));
    fireEvent.keyDown(document.activeElement!, { key: "ArrowRight" });
    fireEvent.keyDown(document.activeElement!, { key: "ArrowRight" });
    expect(document.activeElement).toBe(screen.getByRole("radio", { name: "Grey", checked: true }));
  });

  it("takes the colour names in the page's language", () => {
    const ur = { grey: "سرمئی", red: "سرخ", orange: "نارنجی", amber: "کہربائی", green: "سبز", teal: "فیروزی", blue: "نیلا", pink: "گلابی" };
    render(<Picker names={ur} />);
    expect(screen.getByRole("radio", { name: "فیروزی", checked: true })).toHaveAttribute("title", "فیروزی");
  });

  it("the CSS: 32 px dots in 44 px targets, the chosen one ringed 2 px in the primary text colour", () => {
    expect(css).toMatch(/\.el-colour-picker__option \{[^}]*inline-size: calc\(var\(--el-space-40\) \+ var\(--el-space-4\)\); block-size: calc\(var\(--el-space-40\) \+ var\(--el-space-4\)\);/);
    expect(css).toMatch(/\.el-colour-picker__dot\.el-label-dot \{[^}]*inline-size: var\(--el-space-32\); block-size: var\(--el-space-32\);/);
    expect(css).toContain('.el-colour-picker__option[aria-checked="true"] .el-colour-picker__dot { outline: var(--el-border-strong) solid var(--el-role-text-primary);');
  });
});

describe("NewListDialog", () => {
  function Host({ onCreate = () => undefined, existing = ["Clients", "Home", "Family"], used = [LABEL_COLOURS.teal, LABEL_COLOURS.amber, LABEL_COLOURS.pink] as (string | null)[], words }: {
    onCreate?: (name: string, colour: string) => void; existing?: string[]; used?: (string | null)[]; words?: Parameters<typeof NewListDialog>[0]["words"];
  }) {
    const [open, setOpen] = useState(true);
    return (
      <>
        <button type="button" onClick={() => setOpen(true)}>New list</button>
        <NewListDialog open={open} onClose={() => setOpen(false)} onCreate={onCreate} existing={existing} usedColours={used} words={words} />
      </>
    );
  }

  it("opens on the name field, up to 40 characters, with the next unused colour chosen", () => {
    render(<Host />);
    const dialog = screen.getByRole("dialog", { name: "New list" });
    const field = within(dialog).getByRole("textbox", { name: "List name" });
    expect(field).toHaveFocus();
    expect(field).toHaveAttribute("maxLength", "40");
    expect(within(dialog).getByRole("radio", { checked: true })).toHaveAccessibleName("Grey");
  });

  it("picks the first colour no list has, then the least used, from names or stored values in any case", () => {
    expect(nextListColour([])).toBe("grey");
    expect(nextListColour([LABEL_COLOURS.grey, LABEL_COLOURS.red.toLowerCase(), "orange", null, `#${"123456"}`])).toBe("amber");
    const all = Object.values(LABEL_COLOURS);
    expect(nextListColour([...all, LABEL_COLOURS.grey])).toBe("red");
  });

  it("Create is the main button, off until there is a name; it gives the trimmed name and the stored colour", () => {
    const create = vi.fn();
    render(<Host onCreate={create} />);
    const button = screen.getByRole("button", { name: "Create" });
    expect(button).toHaveClass("el-button--primary");
    expect(button).toBeDisabled();
    fireEvent.change(screen.getByRole("textbox", { name: "List name" }), { target: { value: "   " } });
    expect(button).toBeDisabled();
    fireEvent.change(screen.getByRole("textbox", { name: "List name" }), { target: { value: "  Suppliers " } });
    fireEvent.click(screen.getByRole("radio", { name: "Blue" }));
    expect(button).toBeEnabled();
    fireEvent.click(button);
    expect(create).toHaveBeenCalledWith("Suppliers", LABEL_COLOURS.blue);
  });

  it("a name in use, in any letter case and with spaces, says so under the field and blocks Create", () => {
    const create = vi.fn();
    render(<Host onCreate={create} />);
    const field = screen.getByRole("textbox", { name: "List name" });
    fireEvent.change(field, { target: { value: " clients  " } });
    expect(field).toHaveAttribute("aria-invalid", "true");
    expect(field).toHaveAccessibleDescription("You already have a list called Clients.");
    expect(screen.getByRole("button", { name: "Create" })).toBeDisabled();
    fireEvent.keyDown(field, { key: "Enter" });
    expect(create).not.toHaveBeenCalled();
    fireEvent.change(field, { target: { value: "Clients 2" } });
    expect(field).not.toHaveAttribute("aria-invalid");
  });

  it("Enter creates, from the name or from a colour; not while an input method is composing", () => {
    const create = vi.fn();
    render(<Host onCreate={create} />);
    const field = screen.getByRole("textbox", { name: "List name" });
    fireEvent.change(field, { target: { value: "Suppliers" } });
    fireEvent.keyDown(field, { key: "Enter", isComposing: true });
    expect(create).not.toHaveBeenCalled();
    fireEvent.keyDown(field, { key: "Enter" });
    expect(create).toHaveBeenLastCalledWith("Suppliers", LABEL_COLOURS.grey);
    fireEvent.keyDown(screen.getByRole("radio", { name: "Green" }), { key: "Enter" });
    expect(create).toHaveBeenCalledTimes(2);
  });

  it("Esc and Cancel close; it opens empty again", () => {
    render(<Host />);
    fireEvent.change(screen.getByRole("textbox", { name: "List name" }), { target: { value: "Suppliers" } });
    fireEvent.keyDown(screen.getByRole("dialog"), { key: "Escape" });
    expect(screen.queryByRole("dialog")).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "New list" }));
    expect(screen.getByRole("textbox", { name: "List name" })).toHaveValue("");
    fireEvent.click(screen.getByRole("button", { name: "Cancel" }));
    expect(screen.queryByRole("dialog")).toBeNull();
  });

  it("every word can be given", () => {
    render(<Host existing={["کلائنٹس"]} words={{
      title: "نئی فہرست", name: "فہرست کا نام", colour: "رنگ", create: "بنائیں", cancel: "منسوخ کریں", close: "بند کریں",
      duplicate: (n) => `آپ کی ${n} نام کی فہرست پہلے سے موجود ہے۔`,
      colourNames: { grey: "سرمئی", red: "سرخ", orange: "نارنجی", amber: "کہربائی", green: "سبز", teal: "فیروزی", blue: "نیلا", pink: "گلابی" },
    }} />);
    const dialog = screen.getByRole("dialog", { name: "نئی فہرست" });
    fireEvent.change(within(dialog).getByRole("textbox", { name: "فہرست کا نام" }), { target: { value: "کلائنٹس" } });
    expect(within(dialog).getByText("آپ کی کلائنٹس نام کی فہرست پہلے سے موجود ہے۔")).toBeInTheDocument();
    for (const name of ["بنائیں", "منسوخ کریں", "بند کریں"]) expect(within(dialog).getByRole("button", { name })).toBeInTheDocument();
    expect(within(dialog).getByRole("radiogroup", { name: "رنگ" })).toBeInTheDocument();
    expect(within(dialog).getByRole("radio", { name: "سرمئی" })).toBeInTheDocument();
  });
});

describe("Add to list", () => {
  const lists = [{ name: "Clients", colour: LABEL_COLOURS.teal }, { name: "Home", colour: LABEL_COLOURS.amber }, { name: "Family", colour: LABEL_COLOURS.pink }];

  it("gives each list as a tick that stays open, with its dot, in order, then New list… set apart", () => {
    const toggle = vi.fn();
    const onNew = vi.fn();
    const items = addToListItems({ lists, checked: new Set(["Clients", "Family"]), onToggle: toggle, onNew });
    expect(items.map((i) => i.label)).toEqual(["Clients", "Home", "Family", "New list…"]);
    expect(items.map((i) => i.checked)).toEqual([true, false, true, undefined]);
    expect(items.map((i) => !!i.keepOpen)).toEqual([true, true, true, false]);
    expect(items.map((i) => !!i.separator)).toEqual([false, false, false, true]);
    expect(items[0].icon).toBe(listDotIcon(LABEL_COLOURS.teal));
    expect(items[3].icon).toBe(PlusIcon);
    items[1].onPick?.();
    expect(toggle).toHaveBeenCalledWith("Home");
    items[3].onPick?.();
    expect(onNew).toHaveBeenCalledTimes(1);
    expect(addToListItems({ lists: [], checked: new Set(), onToggle: toggle, onNew, words: { newList: "نئی فہرست…" } }))
      .toEqual([expect.objectContaining({ label: "نئی فہرست…", separator: false })]);
  });

  it("in a menu: ticks toggle and keep it open, each row shows its dot, and New list… closes it", () => {
    function Host({ onNew }: { onNew: () => void }) {
      const [on, setOn] = useState(new Set(["Clients", "Family"]));
      const toggle = (n: string) => setOn((s) => { const next = new Set(s); if (next.has(n)) next.delete(n); else next.add(n); return next; });
      return <Menu label="Add to list" items={() => addToListItems({ lists, checked: on, onToggle: toggle, onNew })} button={{ text: "Add to list" }} />;
    }
    const onNew = vi.fn();
    render(<Host onNew={onNew} />);
    fireEvent.click(screen.getByRole("button", { name: "Add to list" }));
    const menu = screen.getByRole("menu", { name: "Add to list" });
    const boxes = within(menu).getAllByRole("menuitemcheckbox");
    expect(boxes.map((b) => [b.textContent, b.getAttribute("aria-checked")])).toEqual([["Clients", "true"], ["Home", "false"], ["Family", "true"]]);
    expect(boxes[0].querySelector(".el-label-dot.el-label--teal")).not.toBeNull();
    expect(boxes[1].querySelector(".el-label-dot.el-label--amber")).not.toBeNull();
    expect(menu.querySelectorAll('[role="separator"]')).toHaveLength(1);
    fireEvent.click(boxes[1]);
    expect(screen.getByRole("menu", { name: "Add to list" })).toBeInTheDocument();
    fireEvent.click(within(menu).getByRole("menuitem", { name: "New list…" }));
    expect(screen.queryByRole("menu")).toBeNull();
    expect(onNew).toHaveBeenCalledTimes(1);
  });

  it("a separator is skipped by the arrow keys", () => {
    render(<ContextMenu label="Add to list" at={{ x: 10, y: 10 }} onClose={() => undefined}
      items={addToListItems({ lists: lists.slice(0, 1), checked: new Set(), onToggle: () => undefined, onNew: () => undefined })} />);
    const menu = screen.getByRole("menu", { name: "Add to list" });
    expect(document.activeElement).toBe(within(menu).getByRole("menuitemcheckbox", { name: "Clients" }));
    fireEvent.keyDown(menu, { key: "ArrowDown" });
    expect(document.activeElement).toBe(within(menu).getByRole("menuitem", { name: "New list…" }));
  });
});

describe("a list chip's menu", () => {
  const handlers = () => ({ onRename: vi.fn(), onRecolour: vi.fn(), onMoveEarlier: vi.fn(), onMoveLater: vi.fn(), onDelete: vi.fn() });

  it("Rename…, Change colour…, the moves there is room for, then Delete list… in red, set apart and last", () => {
    const on = handlers();
    const items = listChipMenuItems({ name: "Home", canMoveEarlier: true, canMoveLater: true, ...on });
    expect(items.map((i) => i.label)).toEqual(["Rename…", "Change colour…", "Move earlier", "Move later", "Delete list…"]);
    const last = items.at(-1)!;
    expect(last).toMatchObject({ danger: true, separator: true, icon: TrashIcon });
    expect(items.slice(0, -1).some((i) => i.danger || i.separator)).toBe(false);
    for (const [i, fn] of [[0, on.onRename], [1, on.onRecolour], [2, on.onMoveEarlier], [3, on.onMoveLater], [4, on.onDelete]] as const) {
      items[i].onPick?.();
      expect(fn).toHaveBeenCalledTimes(1);
    }
    expect(listChipMenuItems({ name: "Clients", canMoveEarlier: false, canMoveLater: true, ...handlers() }).map((i) => i.label))
      .toEqual(["Rename…", "Change colour…", "Move later", "Delete list…"]);
    expect(listChipMenuItems({ name: "Family", canMoveEarlier: true, canMoveLater: false, ...handlers() }).map((i) => i.label))
      .toEqual(["Rename…", "Change colour…", "Move earlier", "Delete list…"]);
    expect(listChipMenuItems({ name: "Only", canMoveEarlier: false, canMoveLater: false, ...handlers() }).map((i) => i.label))
      .toEqual(["Rename…", "Change colour…", "Delete list…"]);
  });

  it("opens from the chip at the pointer and draws Delete list… after a hairline, in red", () => {
    function Host() {
      const [at, setAt] = useState<{ x: number; y: number } | null>(null);
      return (
        <>
          <ListChip name="Home" colour={LABEL_COLOURS.amber} selected={false} onClick={() => undefined} onMenu={setAt} />
          {at && <ContextMenu label="Home" at={at} onClose={() => setAt(null)} items={listChipMenuItems({ name: "Home", canMoveEarlier: true, canMoveLater: true, ...handlers() })} />}
        </>
      );
    }
    render(<Host />);
    fireEvent.contextMenu(screen.getByRole("button", { name: "Home" }), { clientX: 200, clientY: 90 });
    const menu = screen.getByRole("menu", { name: "Home" });
    const items = within(menu).getAllByRole("menuitem");
    expect(items.map((i) => i.textContent)).toEqual(["Rename…", "Change colour…", "Move earlier", "Move later", "Delete list…"]);
    expect(items.at(-1)).toHaveClass("el-menu__option--danger");
    const separator = menu.querySelector('[role="separator"]')!;
    expect(separator.nextElementSibling).toContainElement(items.at(-1)!);
  });

  it("takes its words, and gives the delete confirmation's", () => {
    expect(listChipMenuItems({ name: "گھر", canMoveEarlier: true, canMoveLater: true, ...handlers(), words: { moveEarlier: "پہلے لے جائیں", moveLater: "بعد میں لے جائیں" } })
      .map((i) => i.label)).toEqual(["Rename…", "Change colour…", "پہلے لے جائیں", "بعد میں لے جائیں", "Delete list…"]);
    expect(deleteListWords("Clients")).toEqual({
      title: "Delete Clients?", body: "Its recordings stay in your Library; only the list goes.", confirm: "Delete", cancel: "Cancel",
    });
    expect(deleteListWords("گھر", { title: (n) => `${n} حذف کریں؟`, confirm: "حذف کریں" })).toMatchObject({ title: "گھر حذف کریں؟", confirm: "حذف کریں", cancel: "Cancel" });
  });
});

describe("the lists parts are exported", () => {
  it("from components/index.ts", () => {
    for (const name of ["ListChip", "ListChipRow", "ColourPicker", "NewListDialog", "addToListItems", "listChipMenuItems", "deleteListWords", "listDotIcon", "nextListColour", "COLOUR_NAMES"]) {
      expect(Components, name).toHaveProperty(name);
    }
  });
});
