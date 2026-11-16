import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { useState } from "react";
import { Button } from "./Button";
import { SearchField, TextField } from "./fields";
import { Select } from "./Select";
import { Checkbox, RadioGroup, Toggle } from "./choices";
import { Badge, Status } from "./Badge";
import { EmptyState } from "./EmptyState";
import { SearchIcon, TrashIcon } from "./icons";

describe("Button", () => {
  it.each(["primary", "secondary", "quiet", "danger"] as const)("draws the %s variant as a real button", (variant) => {
    render(<Button variant={variant}>Share</Button>);
    const button = screen.getByRole("button", { name: "Share" });
    expect(button).toHaveClass("el-button", `el-button--${variant}`);
    expect(button).toHaveAttribute("type", "button");
  });

  it("takes the sizes and names an icon-only button by its aria-label", () => {
    render(<><Button size="sm">Small</Button><Button size="lg">Large</Button><Button icon={TrashIcon} aria-label="Delete recording" /></>);
    expect(screen.getByRole("button", { name: "Small" })).toHaveClass("el-button--sm");
    expect(screen.getByRole("button", { name: "Large" })).toHaveClass("el-button--lg");
    const icon = screen.getByRole("button", { name: "Delete recording" });
    expect(icon).toHaveClass("el-button--icon");
    expect(icon.querySelector("svg")).toHaveAttribute("aria-hidden", "true");
  });

  it("while loading stays focusable, says it is busy and ignores presses", () => {
    const onClick = vi.fn();
    render(<Button loading loadingLabel="Saving…" onClick={onClick}>Save</Button>);
    const button = screen.getByRole("button", { name: "Saving…" });
    expect(button).toHaveAttribute("aria-busy", "true");
    expect(button).not.toBeDisabled();
    button.focus();
    expect(button).toHaveFocus();
    fireEvent.click(button);
    expect(onClick).not.toHaveBeenCalled();
  });
});

describe("TextField and SearchField", () => {
  it("labels the input and reads the error and hint with it", () => {
    render(<TextField label="Workspace name" hint="Shown to everyone in it" error="A workspace needs a name" />);
    const input = screen.getByRole("textbox", { name: "Workspace name" });
    expect(input).toHaveAttribute("aria-invalid", "true");
    expect(input).toHaveAccessibleDescription("A workspace needs a name Shown to everyone in it");
  });

  it("search: named, clears with its button and with Esc", () => {
    function Search() {
      const [q, setQ] = useState("Qureshi");
      return <SearchField label="Search recordings" value={q} onChange={setQ} />;
    }
    render(<Search />);
    const box = screen.getByRole("searchbox", { name: "Search recordings" });
    expect(box).toHaveValue("Qureshi");
    fireEvent.click(screen.getByRole("button", { name: "Clear search" }));
    expect(box).toHaveValue("");
    expect(box).toHaveFocus();
    fireEvent.change(box, { target: { value: "Mehran" } });
    fireEvent.keyDown(box, { key: "Escape" });
    expect(box).toHaveValue("");
    expect(screen.queryByRole("button", { name: "Clear search" })).toBeNull();
  });
});

describe("Select", () => {
  const OPTIONS = [
    { value: "ur", label: "Urdu" },
    { value: "en", label: "English" },
    { value: "ur-en", label: "Urdu and English" },
  ];
  function Language({ onChange = () => undefined }: { onChange?: (v: string) => void }) {
    const [v, setV] = useState("ur");
    return <Select label="Transcript language" options={OPTIONS} value={v} onChange={(x) => { setV(x); onChange(x); }} />;
  }

  it("opens on a press anywhere on the control, the value text included, not only the arrow", () => {
    render(<Language />);
    const control = screen.getByRole("combobox", { name: /Transcript language/ });
    expect(control).toHaveAttribute("aria-expanded", "false");
    fireEvent.click(screen.getByText("Urdu"));
    expect(control).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByRole("listbox", { name: "Transcript language" })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "Urdu" })).toHaveAttribute("aria-selected", "true");
  });

  it("a press on the visible label focuses the control and opens it", () => {
    render(<Language />);
    const control = screen.getByRole("combobox");
    fireEvent.click(screen.getByText("Transcript language"));
    expect(control).toHaveFocus();
    expect(control).toHaveAttribute("aria-expanded", "true");
    fireEvent.click(screen.getByText("Transcript language"));
    expect(control).toHaveAttribute("aria-expanded", "true");
  });

  it("picks with the mouse and returns focus to the control", () => {
    const onChange = vi.fn();
    render(<Language onChange={onChange} />);
    const control = screen.getByRole("combobox");
    fireEvent.click(control);
    fireEvent.click(screen.getByRole("option", { name: "English" }));
    expect(onChange).toHaveBeenCalledWith("en");
    expect(screen.queryByRole("listbox")).toBeNull();
    expect(control).toHaveFocus();
    expect(control).toHaveAccessibleName("Transcript language English");
  });

  it("works from the keyboard: arrows move, Enter picks, Esc closes without a change", () => {
    const onChange = vi.fn();
    render(<Language onChange={onChange} />);
    const control = screen.getByRole("combobox");
    fireEvent.keyDown(control, { key: "ArrowDown" });
    expect(control).toHaveAttribute("aria-expanded", "true");
    fireEvent.keyDown(control, { key: "ArrowDown" });
    expect(control.getAttribute("aria-activedescendant")).toBe(screen.getByRole("option", { name: "English" }).id);
    fireEvent.keyDown(control, { key: "Escape" });
    expect(control).toHaveAttribute("aria-expanded", "false");
    expect(onChange).not.toHaveBeenCalled();
    fireEvent.keyDown(control, { key: "Enter" });
    fireEvent.keyDown(control, { key: "End" });
    fireEvent.keyDown(control, { key: "Enter" });
    expect(onChange).toHaveBeenCalledWith("ur-en");
  });

  it("closes on a press outside", () => {
    render(<><Language /><p>Outside</p></>);
    fireEvent.click(screen.getByRole("combobox"));
    fireEvent.pointerDown(screen.getByText("Outside"));
    expect(screen.queryByRole("listbox")).toBeNull();
  });
});

describe("Toggle, Checkbox, Radio", () => {
  it("toggle is a named switch that flips", () => {
    function T() {
      const [on, setOn] = useState(false);
      return <Toggle label="Auto-transcribe after calls" checked={on} onChange={setOn} />;
    }
    render(<T />);
    const sw = screen.getByRole("switch", { name: "Auto-transcribe after calls" });
    expect(sw).toHaveAttribute("aria-checked", "false");
    fireEvent.click(sw);
    expect(sw).toHaveAttribute("aria-checked", "true");
  });

  it("a hidden-label toggle keeps its name", () => {
    render(<Toggle label="Live captions" hideLabel checked onChange={() => undefined} />);
    expect(screen.getByRole("switch", { name: "Live captions" })).toHaveAttribute("aria-checked", "true");
  });

  it("checkbox is labelled and checks from its label", () => {
    render(<Checkbox label="Also delete the transcript" />);
    const box = screen.getByRole("checkbox", { name: "Also delete the transcript" });
    fireEvent.click(screen.getByText("Also delete the transcript"));
    expect(box).toBeChecked();
  });

  it("radio group has a legend and one choice", () => {
    function G() {
      const [v, setV] = useState<"system" | "light" | "dark">("system");
      return <RadioGroup legend="Theme" name="theme" value={v} onChange={setV}
        options={[{ value: "system", label: "System" }, { value: "light", label: "Light" }, { value: "dark", label: "Dark" }]} />;
    }
    render(<G />);
    expect(screen.getByRole("group", { name: "Theme" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("radio", { name: "Dark" }));
    expect(screen.getByRole("radio", { name: "Dark" })).toBeChecked();
    expect(screen.getByRole("radio", { name: "System" })).not.toBeChecked();
  });
});

describe("Badge, Status, EmptyState", () => {
  it("badge and status carry words, not only colour", () => {
    render(<><Badge tone="warning">Untranscribed</Badge><Status kind="recording" live>Recording 02:14</Status></>);
    expect(screen.getByText("Untranscribed")).toHaveClass("el-badge--warning");
    expect(screen.getByRole("status")).toHaveTextContent("Recording 02:14");
  });

  it("empty state has a heading, a sentence and one action", () => {
    render(<EmptyState icon={SearchIcon} title="No recordings match" body="Try another word." action={<Button>Clear filters</Button>} />);
    expect(screen.getByRole("heading", { name: "No recordings match" })).toBeInTheDocument();
    expect(screen.getByRole("region", { name: "No recordings match" })).toContainElement(screen.getByRole("button", { name: "Clear filters" }));
  });
});
