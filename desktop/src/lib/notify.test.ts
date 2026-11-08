import { beforeEach, describe, expect, it, vi } from "vitest";
import { notifyWhenAway } from "./notify";
import { callsOf, resetTauriMock, respond } from "../test/tauri";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../test/tauri")).eventModule);

beforeEach(() => resetTauriMock());

describe("a page's notification goes through its category", () => {
  it("is sent by the core as Enhancement unless it says otherwise, only while the window is away", async () => {
    respond("notification_send", true);
    const focus = vi.spyOn(document, "hasFocus").mockReturnValue(false);
    expect(await notifyWhenAway("Brief ready", "Acme's brief was written again.")).toBe(true);
    expect(await notifyWhenAway("Shared", "Omar sent you a call", "sharing")).toBe(true);
    expect(callsOf("notification_send")).toEqual([
      { category: "enhancement", title: "Brief ready", body: "Acme's brief was written again." },
      { category: "sharing", title: "Shared", body: "Omar sent you a call" },
    ]);
    focus.mockReturnValue(true);
    expect(await notifyWhenAway("Brief ready", "again")).toBe(false);
    expect(callsOf("notification_send")).toHaveLength(2);
    focus.mockRestore();
  });
});
