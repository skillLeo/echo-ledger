// The v2 Library's ways in that several tests share. Since v2.1 a row's mark no longer filters by its Source (the
// checkbox takes its place on hover), so a test that needs "Source is X" sets it where people do: Filters › Source.
import { act, fireEvent, screen, within } from "@testing-library/react";
import { FILTER_DEBOUNCE_MS } from "../v2/library/LibraryV2";

/**
 * Filters › Source › <name> (the option's words, "WhatsApp", without its count), then the 150 ms the list takes to
 * follow. The panel is closed again when it was closed before, so the page is as it was but for the filter.
 */
export async function filterBySource(name: string): Promise<void> {
  const wasClosed = !screen.queryByTestId("filter-panel");
  if (wasClosed) fireEvent.click(screen.getByRole("button", { name: /^Filters/ }));
  const section = within(within(screen.getByTestId("filter-panel")).getByRole("region", { name: "Source" }));
  const option = section.getAllByRole("checkbox").find((c) => c.closest("label")?.textContent?.replace(/\d+$/, "").trim() === name);
  if (!option) throw new Error(`Filters › Source has no "${name}"`);
  fireEvent.click(option);
  await act(() => new Promise((r) => setTimeout(r, FILTER_DEBOUNCE_MS + 30)));
  if (wasClosed) fireEvent.click(screen.getByRole("button", { name: "Close filters" }));
}
