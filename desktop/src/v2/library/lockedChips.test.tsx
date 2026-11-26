// What the plan leaves out, on the new design's recording page and project page, as classic shows it
// (docs/v2/audit/parity.csv DT-REC-043, DT-REC-073, DT-REC-083, DT-PRJ-012): each locked feature as a chip with the
// plan that has it (its badge opens the upgrade), and none of them when the plan has everything.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem } from "../../lib/ipc";
import { flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeEntitlements, makeItem, makeTranscript, sampleEntitlements } from "../../test/fixtures";
import { resetEntitlementsForTests, useUpgradeSheet } from "../../lib/entitlements";
import { LibraryV2 } from "./LibraryV2";
import { Tabs } from "../components";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const ITEM: LibraryItem = makeItem("bilal", {
  title: "Bilal Qureshi", started_at: "2026-11-17T06:00:00Z", duration_ms: 60_000, screen_count: 0,
  transcript: { final_status: "completed", final_transcript_id: "tr-1", live_available: false, preview: "Hello" },
});

async function open() {
  respond("library_list", [ITEM]);
  respond("library_refresh", [ITEM]);
  respond("bin_list", { data: [] });
  respond("unsure_markers", null);
  respond("session_detail", { playback_url: null });
  respond("transcript_detail", makeTranscript({ id: "tr-1" }));
  render(<LibraryV2 />);
  await act(flushAsync);
  fireEvent.click(screen.getByRole("button", { name: /^Bilal Qureshi/ }));
  await act(flushAsync);
}

describe("locked features", () => {
  beforeEach(() => resetTauriMock());
  afterEach(() => resetEntitlementsForTests());

  it("DT-REC-043, DT-REC-073 and DT-REC-083: a Free plan shows the five chips, each with the plan that has it", async () => {
    resetEntitlementsForTests(makeEntitlements());
    await open();
    const chips = screen.getByTestId("locked-chips");
    expect([...chips.querySelectorAll(".locked-chip")].map((c) => c.firstElementChild!.textContent)).toEqual([
      "Premium engines", "Roman Urdu · English · اردو", "Double-check", "Screens", "Ask an AI assistant",
    ]);
    expect(within(chips).getByRole("button", { name: /^Double-check, in / })).toBeInTheDocument();
  });

  it("a chip's plan badge opens the upgrade for that feature", async () => {
    resetEntitlementsForTests(makeEntitlements());
    let sheet: ReturnType<typeof useUpgradeSheet> = null;
    function Watch() {
      sheet = useUpgradeSheet();
      return null;
    }
    render(<Watch />);
    await open();
    fireEvent.click(within(screen.getByTestId("locked-chips")).getByRole("button", { name: /^Premium engines, in / }));
    await act(flushAsync);
    expect(sheet).not.toBeNull();
  });

  it("a plan with everything shows no chip", async () => {
    resetEntitlementsForTests(sampleEntitlements("ultra"));
    await open();
    expect(screen.getByTestId("locked-chips").querySelectorAll(".locked-chip")).toHaveLength(0);
  });

  it("DT-PRJ-012: a locked tab carries the plan that has it", () => {
    render(<Tabs label="Project" value="recordings" onChange={() => undefined}
      tabs={[{ value: "recordings", label: "Recordings" }, { value: "screens", label: "Screens", badge: "Pro" }]} />);
    expect(screen.getByRole("tab", { name: "Screens Pro" })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Recordings" })).toBeInTheDocument();
  });
});
