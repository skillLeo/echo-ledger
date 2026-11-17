import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, screen } from "@testing-library/react";
import { flushAsync, resetTauriMock, respond } from "../test/tauri";
import { TRASH_KEEP_HOURS_FALLBACK, hoursWords, resetTrashKeepHoursForTests, trashKeepHours } from "./trash";
import { BinPanel } from "../views/main/LibraryOrganise";
import { LibraryV2 } from "../v2/library/LibraryV2";
import { binHours, binSentence } from "../v2/library/model";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../test/tauri")).eventModule);

beforeEach(() => {
  resetTauriMock();
  resetTrashKeepHoursForTests();
});

describe("how long Trash keeps a recording", () => {
  it("is the server's number from the core; 48 while the core has no such command", async () => {
    expect(await trashKeepHours()).toBeNull();
    expect(hoursWords(null)).toBe("48 hours");
    expect(TRASH_KEEP_HOURS_FALLBACK).toBe(48);
    respond("trash_keep_hours", 72);
    expect(await trashKeepHours()).toBe(72);
    expect(hoursWords(72)).toBe("3 days");
    expect(hoursWords(720)).toBe("30 days");
    expect(hoursWords(48)).toBe("48 hours");
    expect(hoursWords(24)).toBe("24 hours");
    expect(hoursWords(60)).toBe("60 hours");
    expect(hoursWords(1)).toBe("1 hour");
  });

  it("ignores an answer that is not a whole number of hours", async () => {
    respond("trash_keep_hours", "two days");
    expect(await trashKeepHours()).toBeNull();
    resetTrashKeepHoursForTests();
    respond("trash_keep_hours", 0);
    expect(await trashKeepHours()).toBeNull();
  });

  it("v2 Trash takes the server's number before what the items show", () => {
    const items = [{ deleted_at: "2026-11-16T12:00:00Z", erase_at: "2026-11-18T12:00:00Z" }];
    expect(binHours(items, 72)).toBe(72);
    expect(binHours(items, null)).toBe(48);
    expect(binHours([], null)).toBe(48);
    expect(binSentence(72)).toBe("Deleted recordings stay here for 3 days, then their audio and transcripts are erased.");
  });
});

describe("the sentences keep their words; only the number is the server's", () => {
  it("classic Recently deleted", async () => {
    respond("trash_keep_hours", 72);
    respond("bin_list", { data: [] });
    render(<BinPanel notify={vi.fn()} onClose={vi.fn()} />);
    await act(flushAsync);
    expect(screen.getByText("Deleted recordings stay here for 3 days, then their audio and transcripts are erased.")).toBeInTheDocument();
    expect(screen.getByText("Nothing deleted in the last 3 days.")).toBeInTheDocument();
  });

  it("classic Recently deleted with an older core says 48 hours", async () => {
    respond("bin_list", { data: [] });
    render(<BinPanel notify={vi.fn()} onClose={vi.fn()} />);
    await act(flushAsync);
    expect(screen.getByText("Deleted recordings stay here for 48 hours, then their audio and transcripts are erased.")).toBeInTheDocument();
  });

  it("v2 Trash", async () => {
    respond("trash_keep_hours", 72);
    respond("library_list", []);
    respond("library_refresh", []);
    respond("bin_list", { data: [] });
    render(<LibraryV2 />);
    await act(flushAsync);
    act(() => screen.getByRole("button", { name: "Trash" }).click());
    await act(flushAsync);
    expect(screen.getByRole("region", { name: "Trash is empty" })).toHaveTextContent("Deleted recordings stay here for 3 days, then their audio and transcripts are erased.");
  });
});
