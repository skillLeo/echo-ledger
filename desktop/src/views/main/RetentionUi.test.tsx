import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { BinPanel, BulkBar, eraseIn, OrganisePanel } from "./LibraryOrganise";
import { fileSize, StorageCard } from "./SecuritySettings";
import { callsOf, fail, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

beforeEach(() => resetTauriMock());

describe("keep, the undo bin and storage (B17)", () => {
  it("says when a binned recording is erased and sizes files", () => {
    const now = Date.parse("2026-10-28T10:00:00Z");
    expect(eraseIn("2026-10-28T15:30:00Z", now)).toBe("erased in 5 hours");
    expect(eraseIn("2026-10-28T11:10:00Z", now)).toBe("erased in 1 hour");
    expect(eraseIn("2026-10-28T10:20:00Z", now)).toBe("erased soon");
    expect(fileSize(512)).toBe("512 B");
    expect(fileSize(1536)).toBe("1.5 KB");
    expect(fileSize(5 * 1024 ** 3)).toBe("5.0 GB");
  });

  it("keeps a recording forever and back", async () => {
    respondWith("session_organise", () => ({ data: {} }));
    render(<OrganisePanel item={makeItem("a", { keep: false })} clients={[]} knownLabels={[]} notify={vi.fn()} onClientCreated={vi.fn()} />);
    fireEvent.click(screen.getByRole("button", { name: "Keep forever" }));
    await waitFor(() => expect(callsOf("session_organise")).toEqual([{ id: "a", patch: { keep: true } }]));
  });

  it("keeps and stops keeping the selection", async () => {
    respond("sessions_bulk", 2);
    render(<BulkBar ids={["a", "b"]} clients={[]} knownLabels={[]} notify={vi.fn()} onDone={vi.fn()} onClientCreated={vi.fn()} />);
    fireEvent.click(screen.getByRole("button", { name: "Keep forever" }));
    await waitFor(() => expect(callsOf("sessions_bulk")).toHaveLength(1));
    fireEvent.click(screen.getByRole("button", { name: "Stop keeping" }));
    await waitFor(() => expect(callsOf("sessions_bulk").map((c) => c.action)).toEqual(["keep", "unkeep"]));
    expect(screen.getByRole("button", { name: "Delete" })).toBeInTheDocument();
  });

  it("restores and erases from Recently deleted", async () => {
    let bin = [
      { id: "s1", title: "Acme kickoff", client: null, started_at: "2026-10-27T09:00:00Z", duration_ms: 1000, deleted_at: "2026-10-28T09:00:00Z", erase_at: new Date(Date.now() + 5 * 3_600_000).toISOString() },
      { id: "s2", title: "Old call", client: { id: "c1", name: "Acme" }, started_at: "2026-10-20T09:00:00Z", duration_ms: 1000, deleted_at: "2026-10-28T09:00:00Z", erase_at: new Date(Date.now() + 3_600_000 * 30).toISOString() },
    ];
    respondWith("bin_list", () => ({ data: bin }));
    respondWith("session_restore", ({ id }) => { bin = bin.filter((b) => b.id !== id); return { data: {} }; });
    respondWith("bin_erase", ({ id }) => { bin = bin.filter((b) => b.id !== id); return undefined; });
    vi.spyOn(window, "confirm").mockReturnValue(true);
    const notify = vi.fn();
    render(<BinPanel notify={notify} onClose={vi.fn()} />);
    const region = screen.getByRole("region", { name: "Recently deleted" });
    const row = await within(region).findByText("Acme kickoff");
    expect(within(region).getByText(/erased in 4 hours|erased in 5 hours/)).toBeInTheDocument();
    fireEvent.click(within(row.closest("[role=listitem]") as HTMLElement).getByRole("button", { name: "Restore" }));
    await waitFor(() => expect(within(region).queryByText("Acme kickoff")).not.toBeInTheDocument());
    fireEvent.click(within(region).getByRole("button", { name: "Delete now" }));
    await waitFor(() => expect(within(region).getByText("Nothing deleted in the last 48 hours.")).toBeInTheDocument());
    expect(callsOf("session_restore")).toEqual([{ id: "s1" }]);
    expect(callsOf("bin_erase")).toEqual([{ id: "s2" }]);
    expect(notify.mock.calls.map((c) => c[0])).toEqual(["Restored", "Erased"]);
  });

  it("says why a restore failed", async () => {
    respond("bin_list", { data: [{ id: "s1", title: "Late", client: null, started_at: "2026-10-27T09:00:00Z", duration_ms: 1, deleted_at: "x", erase_at: new Date().toISOString() }] });
    fail("session_restore", "This recording can no longer be restored.");
    const notify = vi.fn();
    render(<BinPanel notify={notify} onClose={vi.fn()} />);
    fireEvent.click(await screen.findByRole("button", { name: "Restore" }));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("This recording can no longer be restored.", true));
  });

  it("shows the storage meter and the retention", async () => {
    respond("storage_info", { recordings_bytes: 3 * 1024 ** 3, recordings_count: 42, disk_free_bytes: 25 * 1024 ** 3, disk_total_bytes: 100 * 1024 ** 3, retention_days: 14 });
    render(<StorageCard />);
    expect(await screen.findByText("Recordings on the server: 3.0 GB in 42 files.")).toBeInTheDocument();
    expect(screen.getByRole("meter", { name: "Server disk used" })).toHaveAttribute("aria-valuenow", "75");
    expect(screen.getByText(/deleted after 14 days unless kept/)).toBeInTheDocument();
  });
});
