// The main window is never left empty (the owner's report: a fully dark window with only the menu bar): the page paints
// before any script runs, and a window that hits an error while drawing says so with a way back.
import { afterEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import indexHtml from "../../index.html?raw";
import { WindowErrorBoundary } from "./WindowErrorBoundary";

function Broken(): never {
  throw new Error("Cannot read properties of undefined (reading 'plan')");
}

afterEach(() => vi.restoreAllMocks());

describe("the first paint", () => {
  it("index.html draws Starting EchoLedger… in the root before any script, in the window's light or dark background", () => {
    const doc = new DOMParser().parseFromString(indexHtml, "text/html");
    expect(doc.getElementById("root")?.textContent?.trim()).toBe("Starting EchoLedger…");
    const css = doc.querySelector("head style")?.textContent ?? "";
    expect(css).toMatch(/\.boot \{[^}]*background: #f9f9fb/);
    expect(css).toMatch(/prefers-color-scheme: dark\) \{ \.boot \{ background: #111113/);
    // The overlay windows load the same page: theirs never shows it.
    expect(css).toContain('html[data-window]:not([data-window="main"]) .boot { display: none; }');
  });
});

describe("a window that can't draw", () => {
  it("the main window says so and offers Reload, never an empty window", () => {
    vi.spyOn(console, "error").mockImplementation(() => undefined);
    const reload = vi.fn();
    vi.stubGlobal("location", { ...location, reload });
    render(<WindowErrorBoundary label="main"><Broken /></WindowErrorBoundary>);
    expect(screen.getByRole("alert")).toHaveTextContent("EchoLedger couldn't draw this window.");
    expect(screen.getByRole("alert")).toHaveTextContent("Cannot read properties of undefined (reading 'plan')");
    fireEvent.click(screen.getByRole("button", { name: "Reload" }));
    expect(reload).toHaveBeenCalled();
    vi.unstubAllGlobals();
  });

  it("an overlay window (captions, the pill) draws nothing rather than a message over the person's screen", () => {
    vi.spyOn(console, "error").mockImplementation(() => undefined);
    const { container } = render(<WindowErrorBoundary label="overlay"><Broken /></WindowErrorBoundary>);
    expect(container).toBeEmptyDOMElement();
  });

  it("draws its window as usual when nothing goes wrong", () => {
    render(<WindowErrorBoundary label="main"><p>Library</p></WindowErrorBoundary>);
    expect(screen.getByText("Library")).toBeInTheDocument();
  });
});
