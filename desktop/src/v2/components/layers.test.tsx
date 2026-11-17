import { afterEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { useState } from "react";
import { Button } from "./Button";
import { Dialog, Sheet } from "./Dialog";
import { Toast, ToastRegion, toastDuration } from "./Toast";
import { Chip, FilterBar } from "./FilterBar";
import { List, ListRow } from "./ListRow";
import { Segmented, Tabs } from "./Tabs";
import { Player, Transcript, clock } from "./media";
import { MicIcon } from "./icons";

afterEach(() => vi.useRealTimers());

describe.each([["Dialog", Dialog], ["Sheet", Sheet]] as const)("%s", (_name, Layer) => {
  function Host({ onClose = () => undefined }: { onClose?: () => void }) {
    const [open, setOpen] = useState(false);
    return (
      <>
        <Button onClick={() => setOpen(true)}>Delete recording</Button>
        <Layer open={open} title="Delete this recording?" description="It stays in Trash for 48 hours."
          onClose={() => { setOpen(false); onClose(); }}
          actions={<><Button onClick={() => setOpen(false)}>Cancel</Button><Button variant="danger">Delete</Button></>} />
      </>
    );
  }

  it("is a modal named by its title and described by its sentence", () => {
    render(<Host />);
    fireEvent.click(screen.getByRole("button", { name: "Delete recording" }));
    const dialog = screen.getByRole("dialog", { name: "Delete this recording?" });
    expect(dialog).toHaveAttribute("aria-modal", "true");
    expect(dialog).toHaveAccessibleDescription("It stays in Trash for 48 hours.");
  });

  it("moves focus in, past the close button, and keeps Tab inside", () => {
    render(<Host />);
    fireEvent.click(screen.getByRole("button", { name: "Delete recording" }));
    const cancel = screen.getByRole("button", { name: "Cancel" });
    const close = screen.getByRole("button", { name: "Close" });
    const del = screen.getByRole("button", { name: "Delete" });
    expect(cancel).toHaveFocus();
    del.focus();
    fireEvent.keyDown(del, { key: "Tab" });
    expect(close).toHaveFocus();
    fireEvent.keyDown(close, { key: "Tab", shiftKey: true });
    expect(del).toHaveFocus();
  });

  it("closes on Esc and gives focus back to the button that opened it", () => {
    const onClose = vi.fn();
    render(<Host onClose={onClose} />);
    const opener = screen.getByRole("button", { name: "Delete recording" });
    opener.focus();
    fireEvent.click(opener);
    fireEvent.keyDown(screen.getByRole("dialog"), { key: "Escape" });
    expect(onClose).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole("dialog")).toBeNull();
    expect(opener).toHaveFocus();
  });

  it("closes from the close button and the scrim", () => {
    const onClose = vi.fn();
    render(<Host onClose={onClose} />);
    fireEvent.click(screen.getByRole("button", { name: "Delete recording" }));
    fireEvent.click(screen.getByRole("button", { name: "Close" }));
    fireEvent.click(screen.getByRole("button", { name: "Delete recording" }));
    fireEvent.mouseDown(screen.getByRole("dialog").parentElement!);
    expect(onClose).toHaveBeenCalledTimes(2);
  });
});

describe("Toast", () => {
  it("is announced politely and Undo fires once, then closes", () => {
    const onUndo = vi.fn();
    const onClose = vi.fn();
    render(<ToastRegion><Toast message="Voice note moved to Trash" onAction={onUndo} onClose={onClose} /></ToastRegion>);
    expect(screen.getByRole("status")).toHaveAttribute("aria-live", "polite");
    expect(screen.getByRole("status")).toHaveTextContent("Voice note moved to Trash");
    fireEvent.click(screen.getByRole("button", { name: "Undo" }));
    expect(onUndo).toHaveBeenCalledTimes(1);
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it("closes by itself after its time, but not while focus is on it", () => {
    vi.useFakeTimers();
    const onClose = vi.fn();
    render(<Toast message="Saved" duration={6000} onAction={() => undefined} onClose={onClose} />);
    act(() => screen.getByRole("button", { name: "Undo" }).focus());
    act(() => vi.advanceTimersByTime(10000));
    expect(onClose).not.toHaveBeenCalled();
    act(() => screen.getByRole("button", { name: "Undo" }).blur());
    act(() => vi.advanceTimersByTime(6000));
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it("closes on Esc and from its dismiss button; the time comes from the token", () => {
    const onClose = vi.fn();
    render(<Toast message="Saved" duration={0} onClose={onClose} />);
    fireEvent.keyDown(screen.getByRole("button", { name: "Dismiss" }), { key: "Escape" });
    fireEvent.click(screen.getByRole("button", { name: "Dismiss" }));
    expect(onClose).toHaveBeenCalledTimes(2);
    document.documentElement.style.setProperty("--el-component-toast-duration", "4000ms");
    expect(toastDuration()).toBe(4000);
    document.documentElement.style.removeProperty("--el-component-toast-duration");
    expect(toastDuration()).toBe(6000);
  });
});

describe("Toast from the keyboard", () => {
  const platform = (name: string) => vi.spyOn(navigator, "platform", "get").mockReturnValue(name);
  afterEach(() => vi.restoreAllMocks());

  it("Ctrl+Z on Windows (Cmd+Z on a Mac) runs Undo once and closes the toast", () => {
    platform("Win32");
    const onUndo = vi.fn();
    const onClose = vi.fn();
    const { unmount } = render(<Toast message="Voice note moved to Trash" duration={0} onAction={onUndo} onClose={onClose} />);
    expect(screen.getByRole("button", { name: "Undo" })).toHaveAttribute("aria-keyshortcuts", "Control+Z");
    fireEvent.keyDown(document.body, { key: "z", metaKey: true });
    fireEvent.keyDown(document.body, { key: "z", ctrlKey: true, shiftKey: true });
    expect(onUndo).not.toHaveBeenCalled();
    fireEvent.keyDown(document.body, { key: "z", ctrlKey: true });
    expect(onUndo).toHaveBeenCalledTimes(1);
    expect(onClose).toHaveBeenCalledTimes(1);
    unmount();
    platform("MacIntel");
    const onMacUndo = vi.fn();
    render(<Toast message="Voice note moved to Trash" duration={0} onAction={onMacUndo} onClose={() => undefined} />);
    fireEvent.keyDown(document.body, { key: "z", ctrlKey: true });
    expect(onMacUndo).not.toHaveBeenCalled();
    fireEvent.keyDown(document.body, { key: "Z", metaKey: true });
    expect(onMacUndo).toHaveBeenCalledTimes(1);
  });

  it("leaves Cmd/Ctrl+Z to a text field, and to a toast whose action isn't Undo", () => {
    platform("Win32");
    const onAction = vi.fn();
    render(<><input aria-label="Title" /><Toast message="Restored" actionLabel="Open" duration={0} onAction={onAction} onClose={() => undefined} /></>);
    fireEvent.keyDown(document.body, { key: "z", ctrlKey: true });
    expect(onAction).not.toHaveBeenCalled();
    expect(screen.getByRole("button", { name: "Open" })).not.toHaveAttribute("aria-keyshortcuts");
  });

  it("doesn't take Ctrl+Z from a field being typed in", () => {
    platform("Win32");
    const onUndo = vi.fn();
    render(<><input aria-label="Title" /><Toast message="Moved to Trash" duration={0} onAction={onUndo} onClose={() => undefined} /></>);
    fireEvent.keyDown(screen.getByRole("textbox", { name: "Title" }), { key: "z", ctrlKey: true });
    expect(onUndo).not.toHaveBeenCalled();
  });

  it("F6 moves focus to the toast and F6 again back to where it was", () => {
    render(<><Button>Delete</Button><ToastRegion><Toast message="Moved to Trash" duration={0} onAction={() => undefined} onClose={() => undefined} /></ToastRegion></>);
    const from = screen.getByRole("button", { name: "Delete" });
    from.focus();
    fireEvent.keyDown(from, { key: "F6" });
    expect(screen.getByRole("button", { name: "Undo" })).toHaveFocus();
    fireEvent.keyDown(screen.getByRole("button", { name: "Undo" }), { key: "F6" });
    expect(from).toHaveFocus();
  });

  it("only the newest toast answers", () => {
    platform("Win32");
    const first = vi.fn();
    const second = vi.fn();
    render(<><Toast message="One" duration={0} onAction={first} onClose={() => undefined} /><Toast message="Two" duration={0} onAction={second} onClose={() => undefined} /></>);
    fireEvent.keyDown(document.body, { key: "z", ctrlKey: true });
    expect(first).not.toHaveBeenCalled();
    expect(second).toHaveBeenCalledTimes(1);
  });
});

describe("FilterBar and Chip", () => {
  it("quick filters are pressed toggles in a named group; applied filters have a named remove button", () => {
    const onToggle = vi.fn();
    const onRemove = vi.fn();
    render(
      <FilterBar label="Filter recordings">
        <Chip label="Today" selected onToggle={onToggle} count={4} />
        <Chip label="Untranscribed" onToggle={onToggle} />
        <Chip label="Source: WhatsApp" onRemove={onRemove} />
      </FilterBar>,
    );
    const group = screen.getByRole("group", { name: "Filter recordings" });
    expect(group).toContainElement(screen.getByRole("button", { name: "Today 4", pressed: true }));
    fireEvent.click(screen.getByRole("button", { name: "Untranscribed", pressed: false }));
    expect(onToggle).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByRole("button", { name: "Remove filter: Source: WhatsApp" }));
    expect(onRemove).toHaveBeenCalledTimes(1);
  });
});

describe("ListRow", () => {
  it("a row with onClick is one button named by its title and described by the rest", () => {
    const onClick = vi.fn();
    render(
      <List label="Recordings">
        <ListRow icon={MicIcon} title="Voice note" meta="Call the customs agent" endTop="9:18 am" endBottom="2:14" onClick={onClick} />
      </List>,
    );
    expect(screen.getByRole("list", { name: "Recordings" })).toBeInTheDocument();
    const row = screen.getByRole("button", { name: "Voice note" });
    expect(row).toHaveAccessibleDescription("Call the customs agent 9:18 am2:14");
    fireEvent.click(row);
    expect(onClick).toHaveBeenCalledTimes(1);
    // A time and a length keep their own order inside a right-to-left row.
    expect(screen.getByText("9:18 am")).toHaveAttribute("dir", "auto");
    expect(screen.getByText("2:14")).toHaveAttribute("dir", "auto");
  });

  it("a row with a control is not a button; the value sits on the end side", () => {
    render(<List label="Settings"><ListRow title="Theme" value="System" chevron /><ListRow title="Live captions" control={<input type="checkbox" aria-label="Live captions" />} onClick={() => undefined} /></List>);
    expect(screen.queryByRole("button")).toBeNull();
    expect(screen.getByText("System")).toBeInTheDocument();
  });
});

describe("Tabs and Segmented", () => {
  function T({ dir = "ltr" }: { dir?: "ltr" | "rtl" }) {
    const [v, setV] = useState<"transcript" | "summary" | "details">("transcript");
    return (
      <div dir={dir} style={{ direction: dir }}>
        <Tabs label="Recording" value={v} onChange={setV}
          tabs={[{ value: "transcript", label: "Transcript" }, { value: "summary", label: "Summary" }, { value: "details", label: "Details" }]}>
          <p>{v} panel</p>
        </Tabs>
      </div>
    );
  }

  it("has tablist, tabs and a labelled panel; arrows move and select", () => {
    render(<T />);
    expect(screen.getByRole("tablist", { name: "Recording" })).toBeInTheDocument();
    const first = screen.getByRole("tab", { name: "Transcript", selected: true });
    expect(screen.getByRole("tabpanel", { name: "Transcript" })).toHaveTextContent("transcript panel");
    first.focus();
    fireEvent.keyDown(first, { key: "ArrowRight" });
    expect(screen.getByRole("tab", { name: "Summary", selected: true })).toHaveFocus();
    fireEvent.keyDown(screen.getByRole("tab", { name: "Summary" }), { key: "End" });
    expect(screen.getByRole("tab", { name: "Details", selected: true })).toHaveFocus();
    expect(screen.getByRole("tab", { name: "Transcript" })).toHaveAttribute("tabindex", "-1");
  });

  it("in right-to-left, ArrowLeft moves forward", () => {
    render(<T dir="rtl" />);
    const first = screen.getByRole("tab", { name: "Transcript" });
    first.focus();
    fireEvent.keyDown(first, { key: "ArrowLeft" });
    expect(screen.getByRole("tab", { name: "Summary", selected: true })).toHaveFocus();
  });

  it("segmented is a named radio group", () => {
    function S() {
      const [v, setV] = useState<"system" | "light" | "dark">("system");
      return <Segmented label="Theme" value={v} onChange={setV} options={[{ value: "system", label: "System" }, { value: "light", label: "Light" }, { value: "dark", label: "Dark" }]} />;
    }
    render(<S />);
    expect(screen.getByRole("radiogroup", { name: "Theme" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("radio", { name: "Dark" }));
    expect(screen.getByRole("radio", { name: "Dark" })).toHaveAttribute("aria-checked", "true");
    fireEvent.keyDown(screen.getByRole("radio", { name: "Dark" }), { key: "ArrowRight" });
    expect(screen.getByRole("radio", { name: "System" })).toHaveAttribute("aria-checked", "true");
  });
});

describe("Player and Transcript", () => {
  it("names play and the slider, with the time as words", () => {
    const onToggle = vi.fn();
    const onSeek = vi.fn();
    render(<Player playing={false} position={38} duration={1104} onToggle={onToggle} onSeek={onSeek} />);
    fireEvent.click(screen.getByRole("button", { name: "Play" }));
    expect(onToggle).toHaveBeenCalled();
    const slider = screen.getByRole("slider", { name: "Position" });
    expect(slider).toHaveAttribute("aria-valuetext", "0:38 of 18:24");
    fireEvent.change(slider, { target: { value: "62" } });
    expect(onSeek).toHaveBeenCalledWith(62);
  });

  it("lists who spoke when, and marks the line being played", () => {
    render(<Transcript label="Transcript" lang="ur" activeIndex={1} segments={[{ speaker: "آپ", start: 0, text: "السلام علیکم" }, { speaker: "بلال قریشی", start: 6, text: "وعلیکم السلام" }]} />);
    const items = screen.getAllByRole("listitem");
    expect(screen.getByRole("list", { name: "Transcript" })).toHaveAttribute("lang", "ur");
    expect(items[1]).toHaveAttribute("aria-current", "true");
    expect(items[1]).toHaveTextContent("بلال قریشی0:06وعلیکم السلام");
    expect(clock(4000)).toBe("1:06:40");
  });
});
