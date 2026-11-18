// Live Assist from classic (owner, 2026-11-18: on for everyone, with no classic counterpart): classic's Record page has
// the same Live Assist section as the new design's Live tab, opening the same prep screen (and from it the same window
// and card). Nothing shows while live_assist is off, or for a viewer.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import MainApp from "../../views/main/MainApp";
import { RecordView } from "../../views/main/RecordView";
import { callsOf, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeItem, makeSettings, makeStatus } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const PREP = { id: "p1", title: "Interview at Mehran Foods", meeting_type: "interview", counterpart: "Ayesha Siddiqui", company: "Mehran Foods",
  scheduled_at: "2026-11-19T10:00:00Z", status: "scheduled", mode: "outline", client_id: null, goals: null, done: null, not_done: null,
  documents: [], created_at: null, updated_at: null };

beforeEach(() => {
  resetTauriMock();
  localStorage.clear();
  respond("app_status", makeStatus());
  respond("library_list", [makeItem("a")]);
  respond("library_refresh", [makeItem("a")]);
  respond("list_clients", { data: [{ id: "c1", name: "Acme Holdings" }] });
  respond("mic_users", []);
  respond("session_detail", {});
  respond("get_settings", makeSettings());
  respond("input_devices", []);
  respond("account_get", null);
  respond("assist_preps_list", [PREP]);
});
const settle = async () => {
  await act(flushAsync);
  await act(flushAsync);
};
const run = <T,>(fn: () => Promise<T>) => fn().catch(() => undefined);

describe("Live Assist in classic", () => {
  it("classic's Home (its Record page) lists the sessions and New session opens the same prep screen as the new design", async () => {
    respond("ui_flags", { ui_flags: { live_assist: true }, ui_classic: true });
    render(<MainApp />);
    await settle();
    await screen.findByRole("navigation", { name: "Main" });
    fireEvent.click(within(document.querySelector(".sidebar") as HTMLElement).getByRole("button", { name: /^Home/ }));
    await settle();
    const section = await screen.findByRole("region", { name: "Live Assist" });
    expect(document.querySelector("[data-shell=v2]")).toBeNull(); // still classic
    await waitFor(() => expect(within(section).getByRole("button", { name: /^Interview at Mehran Foods/ })).toBeInTheDocument());
    expect(callsOf("assist_preps_list")).toEqual([{ status: "scheduled" }]);
    fireEvent.click(within(section).getByRole("button", { name: "New session" }));
    await settle();
    expect(await screen.findByRole("heading", { level: 1, name: "New Live Assist session" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Live" }));
    await settle();
    expect(await screen.findByRole("region", { name: "Live Assist" })).toBeInTheDocument();
  });

  it("nothing while live_assist is off, or for a viewer", async () => {
    const { unmount } = render(<RecordView status={makeStatus()} run={run} assistOn={false} />);
    await settle();
    expect(screen.queryByRole("region", { name: "Live Assist" })).toBeNull();
    unmount();
    render(<RecordView status={makeStatus({ plan: { ...makeStatus().plan, role: "viewer" } })} run={run} assistOn />);
    await settle();
    expect(screen.queryByRole("region", { name: "Live Assist" })).toBeNull();
  });
});
