// v1.6, P1.2/P1.4: one badge on paid features the plan leaves out, the upgrade sheet, Plan & usage and the Free
// plan's own Groq key. An older server (no entitlements) shows none of it.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { AllFeaturesCard, LockedFeatureCard, PlanBadge, PlanUsageCard, TranscriptionKeyCard, UpgradeSheet } from "./Upgrade";
import { OPEN_SECTION_EVENT, promoCodesMissing, promoPriceLine, resetEntitlementsForTests, useUpgradeSheet } from "../../lib/entitlements";
import { callsOf, fail, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { FEATURE_REGISTRY, makeEntitlements, sampleEntitlements } from "../../test/fixtures";
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
    expect(callsOf("upgrade_request_create")).toEqual([{ plan: "pro", message: "For my calls", promoCode: null }]);
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
    expect(callsOf("upgrade_request_create")[0]).toEqual({ plan: "business", message: null, promoCode: null });
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
    expect(screen.getByTestId("plan-features-summary")).toHaveTextContent("Your plan has 1 of 10 features.");
    expect(screen.getByRole("button", { name: "See all features" })).toBeInTheDocument();
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

describe("Account → Plan & usage → All features (v1.6.2)", () => {
  beforeEach(() => {
    resetTauriMock();
  });

  const rows = () => within(screen.getByRole("list", { name: "All features" })).getAllByRole("listitem");

  it("lists all fifteen features in the registry's order, with what each does, for a Free plan: six ticks and nine badges", () => {
    const e = sampleEntitlements("free");
    withEntitlements(e);
    render(<AllFeaturesCard e={e} />);
    expect(screen.getByTestId("all-features-summary")).toHaveTextContent("Your plan has 6 of 15 features");
    expect(rows().map((li) => li.dataset.testid)).toEqual(FEATURE_REGISTRY.map(([key]) => `feature-${key}`));
    expect(screen.getByTestId("feature-record")).toHaveTextContent("Record and upload");
    expect(screen.getByTestId("feature-record")).toHaveTextContent("Record calls, meetings and voice notes on your phone and computer, and upload files.");
    for (const [key, name, , lowest] of FEATURE_REGISTRY) {
      const row = screen.getByTestId(`feature-${key}`);
      if (!lowest) {
        expect(within(row).getByRole("img", { name: `${name} is in your plan` })).toBeInTheDocument();
        expect(within(row).queryByRole("button")).not.toBeInTheDocument();
      } else {
        expect(within(row).queryByRole("img", { name: /is in your plan/ })).not.toBeInTheDocument();
        expect(within(row).getByRole("button", { name: `${name} is in the ${lowest === "pro" ? "Pro" : "Ultra"} plan. See what it does` }))
          .toHaveTextContent(lowest === "pro" ? "PRO ✦" : "ULTRA ✦");
      }
    }
    expect(within(screen.getByTestId("feature-views")).getByRole("button").style.getPropertyValue("--badge")).toBe("#7c3aed");
    expect(within(screen.getByTestId("feature-live_translate")).getByRole("button").style.getPropertyValue("--badge")).toBe("#0369a1");
  });

  it("ticks every feature on the Ultra plan, with no badge", () => {
    const e = sampleEntitlements("ultra");
    withEntitlements(e);
    render(<AllFeaturesCard e={e} />);
    expect(screen.getByTestId("all-features-summary")).toHaveTextContent("Your plan has 15 of 15 features");
    expect(rows()).toHaveLength(15);
    expect(screen.getAllByRole("img", { name: /is in your plan/ })).toHaveLength(15);
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  it("shows only the Ultra features locked on Pro", () => {
    const e = sampleEntitlements("pro");
    withEntitlements(e);
    render(<AllFeaturesCard e={e} />);
    expect(screen.getByTestId("all-features-summary")).toHaveTextContent("Your plan has 11 of 15 features");
    expect(screen.getAllByRole("button").map((b) => b.textContent)).toEqual(["ULTRA ✦", "ULTRA ✦", "ULTRA ✦", "ULTRA ✦"]);
  });

  it("opens the upgrade sheet for that feature from its badge, never the feature", () => {
    const e = sampleEntitlements("free");
    withEntitlements(e);
    let open: string | null | undefined;
    function Probe() {
      const sheet = useUpgradeSheet();
      open = sheet ? sheet.feature : undefined;
      return null;
    }
    render(<><AllFeaturesCard e={e} /><UpgradeSheet /><Probe /></>);
    fireEvent.click(within(screen.getByTestId("feature-screen_moments")).getByRole("button", { name: /Screen moments is in the Pro plan/ }));
    expect(open).toBe("screen_moments");
    const sheet = screen.getByRole("dialog");
    expect(sheet).toHaveTextContent("Screen moments");
    expect(sheet).toHaveTextContent("What was on the screen during a meeting, next to the words that were said.");
    expect(callsOf("entitlements_get").length + callsOf("upgrade_request_create").length).toBe(0);
  });

  it("follows the server's words and count: a sixteenth feature is listed too", () => {
    const e = sampleEntitlements("free");
    e.features.night_mode = { enabled: false, name: "Night mode", description: "Something new.", cost: "paid", lowest_plan: "pro" };
    withEntitlements(e);
    render(<AllFeaturesCard e={e} />);
    expect(screen.getByTestId("all-features-summary")).toHaveTextContent("Your plan has 6 of 16 features");
    expect(rows().at(-1)).toHaveTextContent("Night modeSomething new.PRO ✦");
  });

  it("goes from Plan & usage's summary to All features", () => {
    const e = sampleEntitlements("free");
    withEntitlements(e);
    const opened = vi.fn();
    window.addEventListener(OPEN_SECTION_EVENT, opened);
    render(<PlanUsageCard e={e} />);
    expect(screen.getByTestId("plan-features-summary")).toHaveTextContent("Your plan has 6 of 15 features.");
    fireEvent.click(screen.getByRole("button", { name: "See all features" }));
    window.removeEventListener(OPEN_SECTION_EVENT, opened);
    expect((opened.mock.calls[0][0] as CustomEvent).detail).toBe("all-features");
  });
});

describe("the upgrade sheet's promo code (v1.6.2)", () => {
  beforeEach(() => {
    resetTauriMock();
  });

  // From docs/API.md "Promo codes"; docs/api-samples/promo_check.json once the server's test writes it.
  const SAVE50 = { code: "SAVE50", plan: "pro", plan_name: "Pro", percent: 50, price: "Rs 2,999 / month", final_price: "Rs 1,500 / month", free: false, months: 1 };
  const FREEPRO = { ...SAVE50, code: "FREEPRO", percent: 100, final_price: "Rs 0 / month", free: true, months: 3 };

  function openSheet(feature = "views") {
    const e = makeEntitlements();
    e.plans[0].price = "Rs 2,999 / month";
    withEntitlements(e);
    render(<><PlanBadge feature={feature} /><UpgradeSheet /></>);
    fireEvent.click(screen.getAllByRole("button", { name: /is in the/ })[0]);
    return screen.getByRole("dialog");
  }
  function typeCode(sheet: HTMLElement, code: string) {
    fireEvent.click(within(sheet).getByRole("button", { name: "Have a promo code?" }));
    fireEvent.change(within(sheet).getByLabelText("Promo code"), { target: { value: code } });
    fireEvent.click(within(sheet).getByRole("button", { name: "Apply" }));
  }

  it("checks the code for the plan shown, shows the new price, and sends the code with the request", async () => {
    respondWith("promo_code_check", () => ({ result: "valid", check: SAVE50 }));
    respond("upgrade_request_create", { ...PENDING, plan: "pro", promo_code: "SAVE50", percent: 50, amount_due: "Rs 1,500 / month" });
    const sheet = openSheet();
    expect(within(sheet).queryByLabelText("Promo code")).not.toBeInTheDocument();
    typeCode(sheet, "save50");
    expect(await within(sheet).findByTestId("promo-price-pro")).toHaveTextContent("Rs 2,999 → Rs 1,500 / month with SAVE50");
    expect(callsOf("promo_code_check")).toEqual([{ code: "SAVE50", plan: "pro" }]);
    expect(within(sheet).getByRole("status")).toHaveTextContent("SAVE50 applied: 50 % off Pro.");
    fireEvent.click(within(sheet).getByRole("button", { name: "Request upgrade" }));
    expect(await within(sheet).findByText("Your request for Pro is pending")).toBeInTheDocument();
    expect(callsOf("upgrade_request_create")).toEqual([{ plan: "pro", message: null, promoCode: "SAVE50" }]);
    expect(within(sheet).getByTestId("amount-due")).toHaveTextContent("To pay: Rs 1,500 / month with SAVE50");
    expect(sheet).toHaveTextContent("We'll activate it once confirmed.");
  });

  it("says plainly when a code does not work, and sends no code", async () => {
    respond("promo_code_check", { result: "invalid", message: "This promo code does not work: it is not known, it has ended or been used up, or you have used it already." });
    respond("upgrade_request_create", PENDING);
    const sheet = openSheet();
    typeCode(sheet, "NOPE-2026");
    expect(await within(sheet).findByRole("alert")).toHaveTextContent("This promo code does not work: it is not known, it has ended or been used up, or you have used it already.");
    expect(within(sheet).getByRole("radio", { name: /PRO ✦/ })).toHaveTextContent("Rs 2,999 / month");
    fireEvent.click(within(sheet).getByRole("button", { name: "Request upgrade" }));
    await within(sheet).findByText("Your request for Pro is pending");
    expect(callsOf("upgrade_request_create")).toEqual([{ plan: "pro", message: null, promoCode: null }]);
  });

  it("offers the plan a code is for, switches the sheet to it and checks again", async () => {
    respondWith("promo_code_check", ({ plan }) => (plan === "pro"
      ? { result: "valid", check: SAVE50 }
      : { result: "other_plan", plan: "pro", message: "This code is for Pro." }));
    const sheet = openSheet("live_translate");
    expect(within(sheet).getByRole("radio", { name: /BUSINESS ✦/ })).toHaveAttribute("aria-checked", "true");
    typeCode(sheet, "SAVE50");
    const other = await within(sheet).findByText("This code is for Pro.");
    fireEvent.click(within(other.parentElement!).getByRole("button", { name: "Switch to Pro" }));
    expect(await within(sheet).findByTestId("promo-price-pro")).toHaveTextContent("Rs 2,999 → Rs 1,500 / month with SAVE50");
    expect(within(sheet).getByRole("radio", { name: /PRO ✦/ })).toHaveAttribute("aria-checked", "true");
    expect(callsOf("promo_code_check")).toEqual([{ code: "SAVE50", plan: "business" }, { code: "SAVE50", plan: "pro" }]);
    // Choosing another plan by hand drops the code: it is for Pro only.
    fireEvent.click(within(sheet).getByRole("radio", { name: /BUSINESS ✦/ }));
    expect(within(sheet).queryByTestId("promo-price-pro")).not.toBeInTheDocument();
  });

  it("switches the plan on at once with a 100 % code: no payment instructions, and the plan is read again", async () => {
    respond("promo_code_check", { result: "valid", check: FREEPRO });
    respond("upgrade_request_create", {
      id: "01JG", plan: "pro", status: "approved", message: null, payment_instructions: null, created_at: "2026-11-09T12:00:00+00:00", decided_at: "2026-11-09T12:00:00+00:00",
      reply: null, promo_code: "FREEPRO", percent: 100, amount_due: "Rs 0 / month", approved_plan: "pro", approved_until: "2027-02-09T12:00:00+00:00",
    });
    respond("entitlements_get", makeEntitlements({ plan: { key: "pro", name: "Pro", badge: "PRO", color: "#7c3aed", expires_at: "2027-02-09T12:00:00+00:00", trial_minutes_left: null } }));
    const sheet = openSheet();
    typeCode(sheet, "FREEPRO");
    expect(await within(sheet).findByTestId("promo-price-pro")).toHaveTextContent("Rs 2,999 / month → Free for 3 months with FREEPRO");
    fireEvent.click(within(sheet).getByRole("button", { name: "Request upgrade" }));
    expect(await within(sheet).findByTestId("upgrade-approved")).toHaveTextContent("Your Pro plan is on until 9 February 2027");
    expect(sheet).not.toHaveTextContent("We'll activate it once confirmed.");
    expect(sheet).not.toHaveTextContent("Pay ");
    await waitFor(() => expect(callsOf("entitlements_get")).toHaveLength(1));
  });

  it("hides the field on an older server (no promo codes) and keeps the request working", async () => {
    respond("promo_code_check", { result: "unsupported" });
    respond("upgrade_request_create", PENDING);
    const sheet = openSheet();
    typeCode(sheet, "SAVE50");
    await waitFor(() => expect(within(sheet).queryByLabelText("Promo code")).not.toBeInTheDocument());
    expect(within(sheet).queryByRole("button", { name: "Have a promo code?" })).not.toBeInTheDocument();
    fireEvent.click(within(sheet).getByRole("button", { name: "Request upgrade" }));
    await within(sheet).findByText("Your request for Pro is pending");
    expect(callsOf("upgrade_request_create")).toEqual([{ plan: "pro", message: null, promoCode: null }]);
    // Until sign-out the sheet no longer offers it.
    expect(promoCodesMissing()).toBe(true);
  });
});

describe("the price with a promo code (v1.6.2)", () => {
  it.each([
    [{ price: "Rs 2,999 / month", final_price: "Rs 1,500 / month", free: false, percent: 50, months: 1 }, "Rs 2,999 → Rs 1,500 / month with SAVE50"],
    [{ price: "Rs 30,000 a year", final_price: "Rs 15,000 a year", free: false, percent: 50, months: 12 }, "Rs 30,000 a year → Rs 15,000 a year with SAVE50"],
    [{ price: null, final_price: null, free: false, percent: 20, months: 1 }, "20 % off with SAVE50"],
    [{ price: "Rs 2,999 / month", final_price: "Rs 0 / month", free: true, percent: 100, months: 1 }, "Rs 2,999 / month → Free for 1 month with SAVE50"],
  ])("%#", (prices, line) => {
    expect(promoPriceLine({ code: "SAVE50", plan: "pro", plan_name: "Pro", ...prices })).toBe(line);
  });
});
