// The design switch, both ways, through the whole app (docs/PLAN_V2_PARITY.md 0, docs/API.md "The design switch (since
// 2.0.0-g)"): classic › Settings › Appearance has "Try the new design", the new design's Settings › Appearance has
// "Switch back to classic"; on an older server the choice is kept on this computer, on a 2.0.0-g one it is the server's.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import MainApp from "../views/main/MainApp";
import { callsOf, fail, flushAsync, resetTauriMock, respond } from "../test/tauri";
import { makeItem, makeSettings, makeStatus } from "../test/fixtures";
import { DESIGN_KEY } from "./flags";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../test/tauri")).eventModule);

beforeEach(() => {
  resetTauriMock();
  localStorage.clear();
  respond("app_status", makeStatus());
  respond("library_list", [makeItem("a")]);
  respond("library_refresh", [makeItem("a")]);
  respond("list_clients", { data: [] });
  respond("mic_users", []);
  respond("session_detail", {});
  respond("get_settings", makeSettings());
  respond("security_overview", { devices: [], events: [] });
  respond("input_devices", []);
  respond("account_get", null);
  respond("storage_info", null);
});

const settle = async () => {
  await act(flushAsync);
  await act(flushAsync);
};
/** The v2 shell is on screen (its sidebar), or the classic one. */
const isV2 = () => document.querySelector("[data-v2]") !== null;

/** Classic: Settings › Appearance. */
async function classicAppearance() {
  await screen.findByRole("navigation", { name: "Main" });
  fireEvent.click(within(document.querySelector(".sidebar") as HTMLElement).getByRole("button", { name: "Settings" }));
  await settle();
  fireEvent.click(await screen.findByRole("button", { name: /^Appearance/ }));
  await settle();
}
/** The new design: Settings › Appearance. */
async function v2Appearance() {
  fireEvent.click(within(await screen.findByRole("navigation", { name: "Main" })).getByRole("button", { name: "Settings" }));
  await settle();
  // The Settings page's Appearance (the Settings panel offers it too).
  fireEvent.click((await screen.findAllByRole("button", { name: "Appearance" })).find((b) => !b.closest("[data-testid=nav-panel]"))!);
  await settle();
}

describe("the design switch, an older server (the choice kept on this computer)", () => {
  it("classic › Appearance › Try the new design opens the new design; its Appearance › Switch back to classic returns", async () => {
    respond("ui_flags", { ui_flags: {}, ui_classic: false });
    render(<MainApp />);
    await settle();
    expect(isV2()).toBe(false);
    await classicAppearance();
    fireEvent.click(screen.getByRole("button", { name: "Try the new design" }));
    await settle();
    expect(localStorage.getItem(DESIGN_KEY)).toBe("v2");
    await waitFor(() => expect(isV2()).toBe(true));
    expect(callsOf("ui_design_set")).toHaveLength(0);

    await v2Appearance();
    fireEvent.click(within(document.getElementById("setting-appearance.design")!).getByRole("button", { name: "Switch back to classic" }));
    await settle();
    expect(localStorage.getItem(DESIGN_KEY)).toBe("classic");
    await waitFor(() => expect(isV2()).toBe(false));
    expect(screen.queryByRole("button", { name: "Switch back to classic" })).toBeNull();
  });
});

describe("the design switch, a 2.0.0-g server (the person's choice is the server's)", () => {
  const answer = (current: "v2" | "classic", flags: Record<string, boolean>) =>
    ({ ui_flags: flags, ui_classic: current === "classic", ui_design: { choice: current, current, can_try: current === "classic", can_switch_back: current === "v2" } });
  const V2_FLAGS = { "ui_v2.shell": true, "ui_v2.library": true, "ui_v2.settings": true };

  it("Try the new design sends the choice and shows what the server then says; Switch back to classic sends it back", async () => {
    respond("ui_flags", { ...answer("classic", {}), ui_design: { choice: null, current: "classic", can_try: true, can_switch_back: false } });
    render(<MainApp />);
    await settle();
    await classicAppearance();
    respond("ui_design_set", answer("v2", V2_FLAGS));
    respond("ui_flags", answer("v2", V2_FLAGS));
    fireEvent.click(screen.getByRole("button", { name: "Try the new design" }));
    await settle();
    expect(callsOf("ui_design_set")).toEqual([{ design: "v2" }]);
    expect(localStorage.getItem(DESIGN_KEY)).toBeNull();
    await waitFor(() => expect(isV2()).toBe(true));

    await v2Appearance();
    respond("ui_design_set", answer("classic", {}));
    respond("ui_flags", answer("classic", {}));
    fireEvent.click(within(document.getElementById("setting-appearance.design")!).getByRole("button", { name: "Switch back to classic" }));
    await settle();
    expect(callsOf("ui_design_set")).toEqual([{ design: "v2" }, { design: "classic" }]);
    await waitFor(() => expect(isV2()).toBe(false));
  });

  it("nothing open to try (can_try false): no Try the new design", async () => {
    respond("ui_flags", { ...answer("classic", {}), ui_design: { choice: null, current: "classic", can_try: false, can_switch_back: false } });
    render(<MainApp />);
    await settle();
    await classicAppearance();
    expect(screen.getByRole("heading", { name: "Appearance" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Try the new design" })).toBeNull();
  });

  it("a refusal (422 design_not_available) says why and stays on classic", async () => {
    respond("ui_flags", { ...answer("classic", {}), ui_design: { choice: null, current: "classic", can_try: true, can_switch_back: false } });
    fail("ui_design_set", "The new design isn't available to try yet.");
    render(<MainApp />);
    await settle();
    await classicAppearance();
    fireEvent.click(screen.getByRole("button", { name: "Try the new design" }));
    await settle();
    expect(screen.getByRole("alert")).toHaveTextContent("The new design isn't available to try yet.");
    expect(isV2()).toBe(false);
  });
});
