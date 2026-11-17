// The classic permission step keeps its look while the new screens are off: which button is filled, ghost or plain
// does not follow stepPlan's "main" (only Setup & health in the new look fills it).
import { describe, expect, it, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import type { PermissionsStatus } from "../../lib/ipc";
import { PermissionStep } from "./Permissions";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const mac = (over: Partial<PermissionsStatus>): PermissionsStatus => ({ microphone: "allowed", systemAudio: "allowed", screen: "allowed", browserAutomation: true, platform: "macos", ...over });
const win = (over: Partial<PermissionsStatus>): PermissionsStatus => ({ microphone: "allowed", browserAutomation: false, platform: "windows", ...over });
const look = (kind: "microphone" | "system_audio" | "screen", permissions: PermissionsStatus) => {
  render(<PermissionStep kind={kind} permissions={permissions} onChange={() => undefined} />);
  return within(screen.getByTestId(`permission-${kind}`)).queryAllByRole("button").map((b) => `${b.textContent} [${b.className}]`);
};

describe("the classic permission step's look", () => {
  it.each([
    ["not asked yet", "system_audio", mac({ systemAudio: "unknown" }), ["Allow [btn]", "Open System Settings [btn ghost]"]],
    ["off", "microphone", mac({ microphone: "denied" }), ["Open System Settings [btn]"]],
    ["allowed before", "system_audio", mac({ systemAudio: "unknown", allowedBefore: ["system_audio"] }), ["Open System Settings [btn primary]", "Allow [btn]"]],
    ["cannot tell", "screen", mac({ screen: "unknown", canTellNotAsked: false }), ["Open System Settings [btn primary]", "Allow [btn]"]],
    ["reopen", "screen", mac({ screen: "reopen" }), ["Quit & reopen [btn primary]"]],
    ["stuck after reopening", "screen", mac({ screen: "reopen", reopenDidNotApply: true }), ["Open System Settings [btn primary]"]],
    ["allowed", "microphone", mac({}), []],
    ["Windows: off", "microphone", win({ microphone: "denied" }), ["Open Windows Settings [btn]"]],
    ["Windows: doesn't say", "microphone", win({ microphone: "unknown" }), ["Open Windows Settings [btn ghost]"]],
  ] as const)("%s", (_name, kind, permissions, buttons) => {
    expect(look(kind, permissions)).toEqual(buttons);
  });
});
