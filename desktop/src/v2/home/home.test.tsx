import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import MainApp from "../../views/main/MainApp";
import { fail, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeEntitlements, makeItem, makeSettings, makeStatus } from "../../test/fixtures";
import { resetEntitlementsForTests } from "../../lib/entitlements";
import { HomeV2, checklist, needsAction } from "./HomeV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const ITEMS = [
  makeItem("bilal", { title: "Bilal Qureshi · Qureshi Textiles", source_app: "whatsapp", started_at: "2026-11-17T06:42:00Z",
    transcript: { final_status: "completed", final_transcript_id: "t1", live_available: false, preview: "Payment terms for the March shipment" } }),
  makeItem("voice", { title: "Voice note", source_app: "voice_note", started_at: "2026-11-17T04:18:00Z", status: "uploaded",
    transcript: { final_status: null, final_transcript_id: null, live_available: false, preview: null } }),
];

beforeEach(() => {
  resetTauriMock();
  resetEntitlementsForTests(makeEntitlements({ limits: { monthly_minutes: { limit: 300, used: 212 } } }));
  respond("ready_last", { ready: false, checkedAt: "2026-11-17T10:00:00Z", checks: [{ id: "other_side", label: "The other side of calls", ok: false, detail: null, fix: null }] });
  respond("permissions_status", { microphone: "allowed", systemAudio: "unknown", screen: "allowed", browserAutomation: true, platform: "macos" });
  respond("library_list", ITEMS);
  respond("skipped_list", [{ id: "s1", sourceApp: "whatsapp", title: null, callerName: "Usman", startedAt: "2026-11-17T09:00:00Z", durationMs: 4000, reason: "short", expiresAt: "2026-11-18T09:00:00Z" }]);
});

describe("Home's rules", () => {
  it("the checklist lists what is left and goes once everything is done", () => {
    expect(checklist({ permissionsOk: false, ready: false, recordings: 0 })!.map((i) => [i.id, i.done])).toEqual([["permissions", false], ["ready", false], ["first", false]]);
    expect(checklist({ permissionsOk: true, ready: true, recordings: 2 })).toBeNull();
  });

  it("needs action shows only the kinds with something in them, in words", () => {
    expect(needsAction({ failed: 1, waiting: 0, untranscribed: 3, missed: 2 }).map((a) => a.title)).toEqual([
      "1 recording didn't upload", "3 recordings are audio only", "2 short calls were skipped in the last day",
    ]);
    expect(needsAction({ failed: 0, waiting: 0, untranscribed: 0, missed: 0 })).toEqual([]);
  });
});

describe("HomeV2", () => {
  const props = () => ({
    onRecord: vi.fn(), onStop: vi.fn(), onSetup: vi.fn(), onReadyCheck: vi.fn(), onOpenRecording: vi.fn(), onLibrary: vi.fn(), onAction: vi.fn(), onPlan: vi.fn(),
  });

  it("shows the status strip, the checklist, what needs action, the recent recordings and the month", async () => {
    const p = props();
    render(<HomeV2 status={makeStatus({ platform: "macos", callsReady: false, failedUploads: 1 })} {...p} />);
    await act(flushAsync);
    expect(screen.getByRole("region", { name: "Status" })).toHaveTextContent("Needs setup");
    fireEvent.click(screen.getByRole("button", { name: "Fix setup" }));
    expect(p.onSetup).toHaveBeenCalled();

    const steps = within(screen.getByRole("list", { name: "Get started" })).getAllByRole("listitem");
    expect(steps.map((s) => s.textContent)).toEqual([
      "1Allow the microphone and system audioTo doSet up", "2Pass the Ready checkTo doCheck", "Make a test recordingDone",
    ]);

    const needs = screen.getByRole("list", { name: "Needs action" });
    fireEvent.click(within(needs).getByRole("button", { name: "1 recording is audio only" }));
    expect(p.onAction).toHaveBeenCalledWith("untranscribed");
    expect(within(needs).getByRole("button", { name: "1 recording didn't upload" })).toBeInTheDocument();
    expect(within(needs).getByRole("button", { name: "1 short call was skipped in the last day" })).toBeInTheDocument();

    fireEvent.click(within(screen.getByRole("list", { name: "Recent recordings" })).getByRole("button", { name: "Bilal Qureshi · Qureshi Textiles" }));
    expect(p.onOpenRecording).toHaveBeenCalledWith("bilal");
    expect(screen.getByRole("progressbar", { name: "Minutes used" })).toHaveAttribute("aria-valuetext", "212 of 300 minutes used this month");
  });

  it("while recording the strip says so and offers Stop", async () => {
    const p = props();
    render(<HomeV2 status={makeStatus({ recording: { clientRef: "r", mode: "call", sourceApp: "zoom", clientId: null, startedAt: "", backend: "mock" }, elapsedMs: 134_000 })} {...p} />);
    await act(flushAsync);
    expect(screen.getByRole("region", { name: "Status" })).toHaveTextContent("Recording 02:14");
    fireEvent.click(screen.getByRole("button", { name: "Stop and save" }));
    expect(p.onStop).toHaveBeenCalled();
  });
});

describe("Home's states", () => {
  const props = () => ({
    onRecord: vi.fn(), onStop: vi.fn(), onSetup: vi.fn(), onReadyCheck: vi.fn(), onOpenRecording: vi.fn(), onLibrary: vi.fn(), onAction: vi.fn(), onPlan: vi.fn(),
  });

  it("loading: still shapes for the recordings, and no checklist until its parts are read", () => {
    respond("library_list", new Promise(() => undefined));
    render(<HomeV2 status={makeStatus({ platform: "macos", callsReady: null })} {...props()} />);
    expect(screen.getByRole("status", { busy: true })).toHaveTextContent("Loading recent recordings");
    expect(screen.queryByRole("list", { name: "Get started" })).toBeNull();
  });

  it("error: the recordings say they didn't load, with Try again", async () => {
    fail("library_list", "offline");
    render(<HomeV2 status={makeStatus({ platform: "macos" })} {...props()} />);
    await act(flushAsync);
    const alert = screen.getByRole("alert");
    expect(alert).toHaveTextContent("Recent recordings didn't load");
    respond("library_list", ITEMS);
    fireEvent.click(within(alert).getByRole("button", { name: "Try again" }));
    await act(flushAsync);
    expect(screen.getByRole("list", { name: "Recent recordings" })).toBeInTheDocument();
  });

  it("empty: no recordings yet says where they will appear; nothing to act on shows no list", async () => {
    respond("library_list", []);
    respond("skipped_list", []);
    respond("permissions_status", { microphone: "allowed", systemAudio: "allowed", screen: "allowed", browserAutomation: true, platform: "macos" });
    render(<HomeV2 status={makeStatus({ platform: "macos" })} {...props()} />);
    await act(flushAsync);
    expect(screen.getByText("Your recordings appear here once you record a call or import a file.")).toBeInTheDocument();
    expect(screen.queryByRole("list", { name: "Needs action" })).toBeNull();
  });
});

describe("the app with ui_v2.home", () => {
  it("Home opens the new page", async () => {
    respond("app_status", makeStatus());
    respond("library_refresh", ITEMS);
    respond("get_settings", makeSettings());
    respond("list_clients", { data: [] });
    respond("mic_users", []);
    respond("ui_flags", { ui_flags: { "ui_v2.shell": true, "ui_v2.home": true } });
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(within(await screen.findByRole("navigation", { name: "Main" })).getByRole("button", { name: "More ways to record" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Record page" }));
    await act(flushAsync);
    await act(flushAsync);
    expect(await screen.findByRole("heading", { level: 1, name: "Home" })).toBeInTheDocument();
    expect(document.querySelector("[data-v2=home]")).not.toBeNull();
  });
});
