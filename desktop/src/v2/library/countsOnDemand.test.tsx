// The filter panel's counts are worked out only while something shows them (the panel, or a pill being edited), never
// on every key typed in the search (review LF-desktop item 9).
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeItem } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";
import * as facets from "./facets";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);
vi.mock("./facets", async (real) => {
  const module = await real<typeof import("./facets")>();
  return { ...module, facetCounts: vi.fn(module.facetCounts) };
});

const ITEMS = [
  makeItem("a", { title: "Ali", source_app: "whatsapp", labels: ["Q4"] }),
  makeItem("b", { title: "Bina", source_app: "zoom" }),
];

describe("the options' counts", () => {
  beforeEach(() => {
    resetTauriMock();
    window.localStorage.clear();
    Object.defineProperty(window, "innerWidth", { value: 1440, configurable: true, writable: true });
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("library_folder", []);
    respond("bin_list", { data: [] });
  });

  it("are worked out only while the panel or a pill editor shows them, not as the search is typed", async () => {
    const counted = vi.mocked(facets.facetCounts);
    render(<LibraryV2 />);
    await act(flushAsync);
    const search = screen.getByRole("searchbox", { name: "Search recordings" });
    for (const q of ["a", "al", "ali"]) fireEvent.change(search, { target: { value: q } });
    await act(flushAsync);
    expect(counted).not.toHaveBeenCalled();
    // A filter on (its pill shows) still counts nothing until the pill is opened.
    fireEvent.change(search, { target: { value: "" } });
    fireEvent.click(screen.getByRole("button", { name: "Show only label Q4" }));
    await act(flushAsync);
    expect(counted).not.toHaveBeenCalled();
    fireEvent.click(within(screen.getByRole("group", { name: "Filters on" })).getByRole("button", { name: "Labels: Q4" }));
    expect(counted).toHaveBeenCalledTimes(1);
    fireEvent.keyDown(screen.getByRole("dialog", { name: "Edit Labels" }), { key: "Escape" });
    // The panel open with nothing changed since: the same counts, not worked out again.
    fireEvent.click(screen.getByRole("button", { name: /^Filters/ }));
    expect(counted).toHaveBeenCalledTimes(1);
    // A change while it shows: counted once more.
    fireEvent.click(screen.getByRole("button", { name: "Clear all" }));
    await act(flushAsync);
    expect(counted).toHaveBeenCalledTimes(2);
    fireEvent.click(screen.getByRole("button", { name: "Close filters" }));
    counted.mockClear();
    for (const q of ["b", "bi", "bin"]) fireEvent.change(search, { target: { value: q } });
    await act(flushAsync);
    expect(counted).not.toHaveBeenCalled();
  });
});
