// v1.6, P1.2/P1.4: one badge on paid features the plan leaves out, the upgrade sheet, Plan & usage and the Free
// plan's own Groq key. An older server (no entitlements) shows none of it.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { LockedFeatureCard, PlanBadge, PlanUsageCard, TranscriptionKeyCard, UpgradeSheet } from "./Upgrade";
import { resetEntitlementsForTests } from "../../lib/entitlements";
import { callsOf, fail, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeEntitlements } from "../../test/fixtures";
import type { Entitlements } from "../../lib/ipc";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const PENDING = {
  id: "01JF", plan: "pro", status: "pending", message: "For my calls", payment_instructions: "Pay Rs 2,000 to Example Bank 0000-1111.\nReference: your email.",
  created_at: "2026-11-09T09:00:00+00:00", decided_at: null, reply: null,
};

function withEntitlements(e: Entitlements | null) {
  resetEntitlementsForTests(e);
}

describe("the one badge (v1.6)", () => {
  beforeEach(() => {
    resetTauriMock();
  });

  it("shows the lowest plan's badge with its colour and a lock on a feature the plan leaves out", () => {
    withEntitlements(makeEntitlements());
    render(<><PlanBadge feature="views" /><PlanBadge feature="live_translate" /></>);
    const pro = screen.getByRole("button", { name: "Transcript views is in the Pro plan. See what it does" });
    expect(pro).toHaveTextContent("PRO ✦");
    expect(pro.style.getPropertyValue("--badge")).toBe("#7c3aed");
    expect(pro.querySelector("svg")).not.toBeNull();
    expect(screen.getByRole("button", { name: /Live Translate is in the Business plan/ })).toHaveTextContent("BUSINESS ✦");
  });

  it("shows nothing when the plan has the feature, or on an older server", () => {
    withEntitlements(makeEntitlements());
    const { container, unmount } = render(<PlanBadge feature="record" />);
    expect(container).toBeEmptyDOMElement();
    unmount();
    withEntitlements(null);
    const older = render(<><PlanBadge feature="views" /><LockedFeatureCard feature="live_translate" /></>);
    expect(older.container).toBeEmptyDOMElement();
  });

  it("opens the sheet: what it does, the plans side by side, then Request upgrade with a message", async () => {
    withEntitlements(makeEntitlements());
    respond("upgrade_request_create", PENDING);
    render(<><PlanBadge feature="views" /><UpgradeSheet /></>);
    fireEvent.click(screen.getByRole("button", { name: /Transcript views is in the Pro plan/ }));

    const sheet = screen.getByRole("dialog", { name: "Transcript views" });
    expect(sheet).toHaveTextContent("Read any transcript in Roman Urdu, English or Urdu script.");
    expect(sheet).toHaveTextContent("In the Pro plan and above. You are on Free.");
    const plans = within(sheet).getByRole("radiogroup", { name: "Plans" });
    const pro = within(plans).getByRole("radio", { name: /PRO ✦/ });
    expect(pro).toHaveAttribute("aria-checked", "true");
    expect(pro).toHaveTextContent("Rs 2,000 a month");
    expect(pro).toHaveTextContent("Devices: 5");
    expect(within(plans).getByRole("radio", { name: /BUSINESS ✦/ })).toHaveTextContent("Transcription on EchoLedger: No limit");

    fireEvent.change(within(sheet).getByLabelText(/Message for the owner/), { target: { value: " For my calls " } });
    fireEvent.click(within(sheet).getByRole("button", { name: "Request upgrade" }));
    expect(await within(sheet).findByText("Your request for Pro is pending")).toBeInTheDocument();
    expect(callsOf("upgrade_request_create")).toEqual([{ plan: "pro", message: "For my calls" }]);
    expect(sheet).toHaveTextContent("Pay Rs 2,000 to Example Bank 0000-1111.");
    expect(sheet).toHaveTextContent("We'll activate it once confirmed.");
    expect(within(sheet).queryByRole("button", { name: "Request upgrade" })).not.toBeInTheDocument();
    fireEvent.click(within(sheet).getByRole("button", { name: "Done" }));
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
  });

  it("asks for another plan when chosen, and says why a request did not go", async () => {
    withEntitlements(makeEntitlements());
    fail("upgrade_request_create", "This server does not take upgrade requests yet.");
    render(<><PlanBadge feature="views" /><UpgradeSheet /></>);
    fireEvent.click(screen.getByRole("button", { name: /Transcript views is in the Pro plan/ }));
    const sheet = screen.getByRole("dialog");
    fireEvent.click(within(sheet).getByRole("radio", { name: /BUSINESS ✦/ }));
    fireEvent.click(within(sheet).getByRole("button", { name: "Request upgrade" }));
    expect(await within(sheet).findByRole("alert")).toHaveTextContent("does not take upgrade requests yet");
    expect(callsOf("upgrade_request_create")[0]).toEqual({ plan: "business", message: null });
  });

  it("shows a pending request at once instead of asking again", () => {
    withEntitlements(makeEntitlements({ upgrade_request: PENDING }));
    render(<><PlanBadge feature="screen_moments" /><UpgradeSheet /></>);
    fireEvent.click(screen.getByRole("button", { name: /Screen moments is in the Pro plan/ }));
    const sheet = screen.getByRole("dialog", { name: "Screen moments" });
    expect(sheet).toHaveTextContent("Your request for Pro is pending");
    expect(within(sheet).queryByRole("button", { name: "Request upgrade" })).not.toBeInTheDocument();
  });

  it("puts a locked feature's card where the feature would be", () => {
    withEntitlements(makeEntitlements());
    render(<LockedFeatureCard feature="live_translate" />);
    expect(screen.getByTestId("locked-live_translate")).toHaveTextContent("Live Translate");
    expect(screen.getByRole("button", { name: /Live Translate is in the Business plan/ })).toBeInTheDocument();
  });
});

describe("Account → Plan & usage (v1.6)", () => {
  beforeEach(() => {
    resetTauriMock();
  });

  it("shows the plan, the limits used, and each paid feature with its badge", () => {
    const e = makeEntitlements();
    withEntitlements(e);
    render(<PlanUsageCard e={e} />);
    const card = screen.getByTestId("plan-usage");
    expect(card).toHaveTextContent("You are on Free.");
    expect(card).toHaveTextContent("Devices1 of 2");
    expect(screen.getByRole("meter", { name: "Devices used" })).toHaveAttribute("aria-valuenow", "50");
    expect(card).toHaveTextContent("Storage50.0 MB of 1.0 GB");
    expect(card).toHaveTextContent("Transcription on EchoLedgerNot in your plan");
    expect(card).toHaveTextContent("Recordings kept30 days");
    const features = screen.getByRole("list", { name: "Paid features" });
    expect(within(features).getAllByRole("listitem")).toHaveLength(9);
    expect(within(features).getByRole("button", { name: /Transcript views is in the Pro plan/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "See plans" })).toBeInTheDocument();
  });

  it.each([
    [PENDING, "Your request for Pro is pending. We'll activate it once confirmed."],
    [{ ...PENDING, status: "approved", decided_at: "2026-11-10T09:00:00+00:00" }, "Your request for Pro was approved on 10 November 2026."],
    [{ ...PENDING, status: "declined", reply: "Payment not received yet" }, "Your request for Pro was declined: Payment not received yet"],
  ])("shows the latest request: %#", (request, text) => {
    const e = makeEntitlements({ upgrade_request: request });
    withEntitlements(e);
    render(<PlanUsageCard e={e} />);
    expect(screen.getByTestId("upgrade-request")).toHaveTextContent(text);
  });

  it("opens the plans from See plans", () => {
    const e = makeEntitlements();
    withEntitlements(e);
    render(<><PlanUsageCard e={e} /><UpgradeSheet /></>);
    fireEvent.click(screen.getByRole("button", { name: "See plans" }));
    expect(screen.getByRole("dialog", { name: "Plans" })).toHaveTextContent("You are on Free.");
  });
});

describe("Account → Transcription key (v1.6, the Free plan)", () => {
  beforeEach(() => {
    resetTauriMock();
    respond("groq_console_open", undefined);
  });

  it("guides through the three steps and saves the pasted key, then shows its last four characters", async () => {
    const e = makeEntitlements();
    withEntitlements(e);
    respond("transcription_key_set", { provider: "groq", set: true, last4: "3f9a" });
    respond("entitlements_get", makeEntitlements({ transcription_key: { provider: "groq", set: true, last4: "3f9a" } }));
    render(<TranscriptionKeyCard e={e} />);
    const card = screen.getByTestId("transcription-key");
    const steps = within(card).getAllByRole("listitem");
    expect(steps).toHaveLength(3);
    expect(steps[0]).toHaveTextContent("Open console.groq.com");
    expect(steps[1]).toHaveTextContent("Choose API Keys, then Create API key");
    fireEvent.click(within(card).getByRole("button", { name: "Open console.groq.com" }));
    expect(callsOf("groq_console_open")).toHaveLength(1);

    const field = within(card).getByLabelText("Paste your Groq key") as HTMLInputElement;
    expect(field).toHaveAttribute("type", "password");
    expect(within(card).getByRole("button", { name: "Save" })).toBeDisabled();
    fireEvent.change(field, { target: { value: "gsk_example_key_3f9a" } });
    fireEvent.click(within(card).getByRole("button", { name: "Save" }));
    expect(await within(card).findByText("Key saved. New recordings are transcribed with it.")).toBeInTheDocument();
    expect(callsOf("transcription_key_set")).toEqual([{ key: "gsk_example_key_3f9a" }]);
    expect(field.value).toBe("");
    await waitFor(() => expect(callsOf("entitlements_get")).toHaveLength(1));
  });

  it("says when Groq refused the key and keeps it in the field", async () => {
    const e = makeEntitlements();
    withEntitlements(e);
    fail("transcription_key_set", "Groq did not accept this key. Copy it again from console.groq.com → API Keys, then paste it here.");
    render(<TranscriptionKeyCard e={e} />);
    fireEvent.change(screen.getByLabelText("Paste your Groq key"), { target: { value: "gsk_wrong" } });
    fireEvent.click(screen.getByRole("button", { name: "Save" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("Groq did not accept this key.");
    expect((screen.getByLabelText("Paste your Groq key") as HTMLInputElement).value).toBe("gsk_wrong");
  });

  it("shows a key that is set and removes it after asking", async () => {
    const e = makeEntitlements({ transcription_key: { provider: "groq", set: true, last4: "3f9a" } });
    withEntitlements(e);
    respond("transcription_key_delete", undefined);
    respondWith("entitlements_get", () => makeEntitlements());
    vi.spyOn(window, "confirm").mockReturnValue(true);
    render(<TranscriptionKeyCard e={e} />);
    expect(screen.getByText("Key added · ending 3f9a")).toBeInTheDocument();
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "Remove key" }));
    });
    await waitFor(() => expect(callsOf("transcription_key_delete")).toHaveLength(1));
  });
});
