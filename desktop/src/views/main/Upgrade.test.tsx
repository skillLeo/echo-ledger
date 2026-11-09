// Plans and paid features (v1.7): the neutral tag, Settings › Plan & usage for each plan and each request stage, and
// Transcription key.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, renderHook, screen, waitFor, within } from "@testing-library/react";
import { LockedFeatureCard, PlanBadge, PlanUsagePage, RequestCard, TranscriptionKeyPage, limitText, planHighlights, requestShown, usageText } from "./Upgrade";
import { AccountHeader, planLine, stateRow } from "./ProfileCard";
import { callsOf, fail, resetTauriMock, respond } from "../../test/tauri";
import { makeEntitlements, makeStatus, makeUpgradeRequest, sampleEntitlements } from "../../test/fixtures";
import { closeUpgradeSheet, mergeRequest, plansAbove, priceText, promoPriceLine, requestStage, resetEntitlementsForTests, useUpgradeSheet } from "../../lib/entitlements";
import type { Entitlements } from "../../lib/ipc";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

beforeEach(() => {
  resetTauriMock();
  respond("upgrade_request_latest", null);
  localStorage.clear();
});
afterEach(() => {
  act(() => {
    resetEntitlementsForTests();
    closeUpgradeSheet();
  });
});

const page = (e: Entitlements, role: string | null = "owner") => render(<PlanUsagePage e={e} role={role} workspace="Acme" onKey={vi.fn()} />);

describe("the plan's tag", () => {
  it("is the plan's name, neutral: no colour, sparkle, lock or capitals; a click opens the upgrade flow", () => {
    resetEntitlementsForTests(makeEntitlements());
    const sheet = renderHook(() => useUpgradeSheet());
    render(<PlanBadge feature="views" />);
    const tag = screen.getByRole("button", { name: "Transcript views, in Pro" });
    expect(tag).toHaveTextContent(/^Pro$/);
    expect(tag.getAttribute("style")).toBeNull();
    expect(tag.querySelector("svg")).toBeNull();
    fireEvent.click(tag);
    expect(sheet.result.current?.feature).toBe("views");
  });

  it("shows nothing when the plan has the feature, or on an older server", () => {
    resetEntitlementsForTests(null);
    const { container } = render(<><PlanBadge feature="views" /><LockedFeatureCard feature="views" /></>);
    expect(container).toBeEmptyDOMElement();
  });
});

describe("words and numbers", () => {
  it("writes limits as people read them, in one unit", () => {
    expect(limitText("retention_days", 365)).toBe("1 year");
    expect(limitText("retention_days", 30)).toBe("30 days");
    expect(limitText("max_file_minutes", 180)).toBe("3 hours");
    expect(limitText("uploads_per_day", 50)).toBe("Up to 50");
    expect(limitText("seats", 1)).toBe("Just you");
    expect(limitText("seats", 10)).toBe("Up to 10 people");
    expect(usageText("monthly_minutes", { limit: 600, used: 230 })).toBe("230 of 600 min");
    expect(usageText("storage_bytes", { limit: 1024 ** 3, used: 120 * 1024 ** 2 })).toBe("120 MB of 1 GB");
    expect(usageText("devices", { limit: 2, used: 1 })).toBe("1 of 2");
  });

  it("offers only the plans above the current one, with prices in rupees", () => {
    expect(plansAbove(sampleEntitlements("free")).map((p) => p.key)).toEqual(["pro", "ultra"]);
    expect(plansAbove(sampleEntitlements("pro")).map((p) => p.key)).toEqual(["ultra"]);
    expect(plansAbove(sampleEntitlements("ultra"))).toEqual([]);
    expect(priceText({ price: null, price_pkr: 2999, price_text: null })).toBe("Rs 2,999 a month");
    expect(priceText({ price: "Rs 2,000 / month", price_pkr: null, price_text: null })).toBe("Rs 2,000 a month");
    expect(priceText({ price: "Ask for the price", price_pkr: null, price_text: null })).toBeNull();
  });

  it("takes the server's highlights, or says what the plan adds", () => {
    const e = sampleEntitlements("free");
    expect(planHighlights(e, e.plans[1], null)[0]).toBe("600 transcription minutes a month, no key needed");
    const plain = { ...e.plans[2], highlights: [] };
    expect(planHighlights(e, plain, e.plans[1])).toContain("A team of up to 10 people");
  });

  it("reads where a request stands, and keeps its payment part when a slimmer copy arrives", () => {
    expect(requestStage(makeUpgradeRequest("in_review"))).toBe("in_review");
    expect(requestStage({ ...makeUpgradeRequest("awaiting_payment"), payment: null })).toBe("awaiting_payment");
    expect(requestStage({ ...makeUpgradeRequest("cancelled"), payment: null })).toBe("cancelled");
    const full = makeUpgradeRequest("awaiting_payment");
    const slim = { ...full, payment: null, reference_code: null };
    expect(mergeRequest(full, slim)?.payment?.accounts).toHaveLength(1);
    expect(mergeRequest(full, slim)?.reference_code).toBe("EL-DZJRSB");
    expect(mergeRequest(full, { ...full, id: "other" })?.id).toBe("other");
  });

  it("shows a decided request for a while, and an open one until it is decided", () => {
    const now = Date.parse("2026-11-10T12:00:00Z");
    expect(requestShown(makeUpgradeRequest("needs_receipt"), now)).toBe(true);
    expect(requestShown({ ...makeUpgradeRequest("approved"), decided_at: "2026-11-09T12:00:00Z" }, now)).toBe(true);
    expect(requestShown({ ...makeUpgradeRequest("approved"), decided_at: "2026-10-01T12:00:00Z" }, now)).toBe(false);
    expect(requestShown(makeUpgradeRequest("cancelled"), now)).toBe(false);
  });

  it("keeps the price line with a promo code", () => {
    expect(promoPriceLine({ code: "SAVE50", plan: "pro", plan_name: "Pro", price: "Rs 2,999 / month", final_price: "Rs 1,500 / month", free: false, percent: 50, months: 1 })).toBe("Rs 2,999 → Rs 1,500 / month with SAVE50");
  });
});

describe("the profile card and the state row", () => {
  it("writes the plan's line: minutes on Pro, paid until on Ultra, nothing on Free", () => {
    expect(planLine(sampleEntitlements("free"))).toBeNull();
    expect(planLine(sampleEntitlements("pro"))).toBe("230 of 600 min this month");
    expect(planLine(sampleEntitlements("ultra"))).toBe("Paid until 9 Dec 2026");
  });

  it("gives a team's members no button, and says who manages the plan", () => {
    render(<AccountHeader account={null} e={sampleEntitlements("pro")} role="member" workspace="Acme" teamLine={null} onPlan={vi.fn()} />);
    expect(screen.queryByRole("button", { name: "Upgrade" })).not.toBeInTheDocument();
    expect(screen.getByText("Acme is on Pro. Its owner manages the plan.")).toBeInTheDocument();
  });

  it("puts the first true state in the row, in the spec's order", () => {
    const base = { account: null, accountFailed: false, status: makeStatus(), onPrivacy: vi.fn(), onKey: vi.fn(), onRetry: vi.fn(), onPlan: vi.fn(), onDismissed: vi.fn() };
    const withRequest = (stage: Parameters<typeof makeUpgradeRequest>[0]) => ({ ...sampleEntitlements("free"), upgrade_request: makeUpgradeRequest(stage, { decided_at: new Date().toISOString() }) });
    expect(stateRow({ ...base, e: withRequest("needs_receipt") })?.text).toBe("We need another receipt for Pro");
    expect(stateRow({ ...base, e: withRequest("declined") })?.text).toBe("Your Pro request wasn't approved");
    expect(stateRow({ ...base, e: withRequest("awaiting_payment") })?.text).toBe("Pro requested. Waiting for your payment");
    expect(stateRow({ ...base, e: withRequest("in_review") })?.text).toBe("Checking your payment for Pro");
    expect(stateRow({ ...base, e: sampleEntitlements("free") })?.text).toBe("Set up free transcription (2 minutes)");
    expect(stateRow({ ...base, e: sampleEntitlements("ultra") })).toBeNull();
    const ending = { ...sampleEntitlements("pro"), plan: { ...sampleEntitlements("pro").plan!, expires_at: new Date(Date.now() + 3 * 86_400_000).toISOString() } };
    expect(stateRow({ ...base, e: ending })?.key).toBe("ending");
    expect(stateRow({ ...base, accountFailed: true, e: sampleEntitlements("free") })?.text).toBe("Couldn't load your account.");
  });
});

describe("Plan & usage", () => {
  it("Free: the plan, one filled Upgrade, the Groq key instead of 0 of 0 minutes, and Pro and Ultra stacked with Choose", () => {
    page(sampleEntitlements("free"));
    expect(screen.getByText("Free plan")).toBeInTheDocument();
    expect(screen.getByText("Transcripts use your own free Groq key.")).toBeInTheDocument();
    const filled = document.querySelectorAll(".btn.primary");
    expect(filled).toHaveLength(1);
    expect(filled[0]).toHaveTextContent("Upgrade");
    expect(screen.getByTestId("meter-own-key")).toHaveTextContent("Your Groq key");
    expect(screen.getByTestId("meter-own-key")).toHaveTextContent("Not added. Add key");
    expect(screen.queryByText(/0 of 0/)).not.toBeInTheDocument();
    expect(screen.getByTestId("meter-storage_bytes")).toHaveTextContent("120 MB of 1 GB");
    expect(screen.getByTestId("meter-devices")).toHaveTextContent("1 of 2");
    expect(screen.getByText("Every plan can record, organise, search, share links and export.")).toBeInTheDocument();
    expect(screen.getByTestId("plan-pro")).toHaveTextContent("ProRs 2,999 a month");
    expect(screen.getByTestId("plan-ultra")).toHaveTextContent("Everything in Pro, plus:");
    expect(screen.getByRole("button", { name: "Choose Pro" })).toHaveClass("outline");
    expect(screen.queryByTestId("plan-free")).not.toBeInTheDocument();
    expect(screen.queryByText(/✦|Current plan/)).not.toBeInTheDocument();
    expect(document.querySelector(".plan-lines svg")).toBeNull();
  });

  it("Pro: its price, the date it's paid until, Upgrade to Ultra, minutes with their reset date, only Ultra above", () => {
    page(sampleEntitlements("pro"));
    expect(screen.getByText("Pro plan")).toBeInTheDocument();
    expect(screen.getByText("Rs 2,999 a month")).toBeInTheDocument();
    expect(screen.getByText("Paid until 9 Dec 2026")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Upgrade to Ultra" })).toHaveClass("primary");
    expect(screen.getByTestId("meter-monthly_minutes")).toHaveTextContent("230 of 600 min");
    expect(screen.getByTestId("meter-monthly_minutes")).toHaveTextContent("Resets 1 Dec");
    expect(screen.getByRole("meter", { name: /Transcription, 230 of 600 minutes used, resets 1 December/ })).toBeInTheDocument();
    expect(screen.queryByTestId("plan-pro")).not.toBeInTheDocument();
    expect(screen.getByTestId("plan-ultra")).toBeInTheDocument();
    expect(screen.getByText(/Your plan stops on 9 December 2026\. There is nothing to cancel\./)).toBeInTheDocument();
    expect(screen.queryByText(/\$|ai_usd|AI this month/)).not.toBeInTheDocument();
  });

  it("turns a meter amber from 80 % with a line to upgrade, and red with what stops at 100 %", () => {
    const e = sampleEntitlements("pro");
    e.limits.monthly_minutes = { limit: 600, used: 552, resets_at: "2026-12-01" };
    e.limits.storage_bytes = { limit: 1024 ** 3, used: 1024 ** 3 };
    page(e);
    const minutes = screen.getByTestId("meter-monthly_minutes");
    expect(minutes.querySelector(".set-meter")).toHaveClass("warn");
    expect(minutes).toHaveTextContent("You've used 92% of your minutes. Upgrade or wait until 1 Dec.");
    const storage = screen.getByTestId("meter-storage_bytes");
    expect(storage.querySelector(".set-meter")).toHaveClass("full");
    expect(storage).toHaveTextContent("New recordings can't upload until you free up space or upgrade.");
  });

  it("Ultra: no Plans and no button, the top plan's line, and a team's members meter", () => {
    page(sampleEntitlements("ultra"));
    expect(screen.getByText("You're on our top plan.")).toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "Plans" })).not.toBeInTheDocument();
    expect(document.querySelectorAll(".btn.primary")).toHaveLength(0);
    expect(screen.getByTestId("meter-seats")).toHaveTextContent("2 of 10");
  });

  it("opens the full table of features in place, with the current plan marked and ticks only there", () => {
    page(sampleEntitlements("free"));
    fireEvent.click(screen.getByRole("button", { name: /^See every feature/ }));
    const table = screen.getByRole("table", { name: "Every feature" });
    expect(within(table).getAllByRole("columnheader").map((c) => c.textContent)).toEqual(["Feature", "Free (yours)", "Pro", "Ultra"]);
    expect(within(screen.getByTestId("feature-live_translate")).getAllByRole("img", { name: "Included" })).toHaveLength(1);
  });

  it("while a request is open: its card has the only filled button and no Upgrade shows anywhere", () => {
    page({ ...sampleEntitlements("free"), upgrade_request: makeUpgradeRequest("awaiting_payment") });
    const card = screen.getByTestId("upgrade-request");
    expect(card).toHaveAttribute("data-stage", "awaiting_payment");
    expect(card).toHaveTextContent("Pro requested");
    expect(card).toHaveTextContent("Waiting for payment");
    expect(card).toHaveTextContent("Send Rs 2,999 to our bank account, then add a screenshot of the receipt. Reference EL-DZJRSB.");
    const filled = document.querySelectorAll(".btn.primary");
    expect(filled).toHaveLength(1);
    expect(filled[0]).toHaveTextContent("Payment details");
    expect(screen.queryByText(/^Upgrade/)).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^Choose/ })).not.toBeInTheDocument();
    expect(callsOf("upgrade_request_latest")).toHaveLength(1);
  });

  it("cancels an open request after asking, in the request's own words", async () => {
    respond("upgrade_request_cancel", makeUpgradeRequest("cancelled"));
    respond("entitlements_get", sampleEntitlements("free"));
    page({ ...sampleEntitlements("free"), upgrade_request: makeUpgradeRequest("awaiting_payment") });
    fireEvent.click(screen.getByRole("button", { name: "Cancel request" }));
    const dialog = screen.getByRole("dialog", { name: "Cancel this request?" });
    expect(dialog).toHaveTextContent("If you've already paid, add your receipt instead. Cancelling doesn't refund a payment.");
    expect(within(dialog).getByRole("button", { name: "Keep request" })).toBeInTheDocument();
    fireEvent.click(within(dialog).getByRole("button", { name: "Cancel request" }));
    await waitFor(() => expect(callsOf("upgrade_request_cancel")).toEqual([{ id: "01m70jccdjr5m148e49763r5w6" }]));
  });

  it("gives a team's members the plan's facts and no actions", () => {
    page(sampleEntitlements("pro"), "member");
    expect(screen.getByText("Acme is on Pro. Its owner manages the plan.")).toBeInTheDocument();
    expect(document.querySelectorAll(".btn.primary, .btn.outline")).toHaveLength(0);
    expect(screen.queryByRole("heading", { name: "Payments" })).not.toBeInTheDocument();
  });
});

describe("the request card at each stage", () => {
  const e = sampleEntitlements("free");
  const card = (stage: Parameters<typeof makeUpgradeRequest>[0]) => {
    const handlers = { onOpen: vi.fn(), onPay: vi.fn(), onReceipt: vi.fn(), onCancel: vi.fn(), onAgain: vi.fn(), onDismiss: vi.fn() };
    const view = render(<RequestCard e={e} request={makeUpgradeRequest(stage)} {...handlers} />);
    return { ...handlers, card: screen.getByTestId("upgrade-request"), view };
  };

  it("in review: when the receipt came and how long checks take", () => {
    const { card: c, onOpen } = card("in_review");
    expect(c).toHaveTextContent("Checking your payment");
    expect(c).toHaveTextContent("In review");
    expect(c).toHaveTextContent("We check receipts every day. Most are done within a few hours.");
    fireEvent.click(within(c).getByRole("button", { name: "View request" }));
    expect(onOpen).toHaveBeenCalled();
  });

  it("needs a receipt: staff's note, that nothing is paid twice, and Add receipt", () => {
    const { card: c, onReceipt } = card("needs_receipt");
    expect(c).toHaveTextContent("We need another receipt");
    expect(c).toHaveTextContent("The receipt is cut off, so we can't see the date.");
    expect(c).toHaveTextContent("Your request is still open. You don't need to pay again.");
    expect(c.querySelector(".set-tag")).toHaveClass("warning");
    fireEvent.click(within(c).getByRole("button", { name: "Add receipt" }));
    expect(onReceipt).toHaveBeenCalled();
  });

  it("declined: the reason and what to do if you've paid", () => {
    const { card: c } = card("declined");
    expect(c).toHaveTextContent("We couldn't confirm your payment");
    expect(c).toHaveTextContent("No payment arrived.");
    expect(c).toHaveTextContent("If you've paid, reply to our email with your transaction ID and we'll look again.");
    expect(within(c).getByRole("button", { name: "Request again" })).toBeInTheDocument();
  });

  it("approved: the plan and until when, in green once", () => {
    const { card: c } = card("approved");
    expect(c).toHaveTextContent("You're on Pro");
    expect(c).toHaveTextContent("Thank you. Pro is on until 9 December 2026.");
    expect(c.querySelector(".set-tag")).toHaveClass("success");
  });
});

describe("Transcription key", () => {
  const e = makeEntitlements();

  it("guides through the three steps and saves the pasted key once it's long enough", async () => {
    respond("transcription_key_set", { provider: "groq", set: true, last4: "3f9a" });
    respond("entitlements_get", { ...e, transcription_key: { provider: "groq", set: true, last4: "3f9a" } });
    render(<TranscriptionKeyPage e={e} />);
    expect(screen.getByText("Transcripts on Free use your own free Groq key. It takes about 2 minutes.")).toBeInTheDocument();
    const save = screen.getByRole("button", { name: "Save" });
    fireEvent.change(screen.getByLabelText("Groq key"), { target: { value: "gsk_short" } });
    expect(save).toBeDisabled();
    fireEvent.change(screen.getByLabelText("Groq key"), { target: { value: "gsk_abcdefghijklmnopqrstuvwxyz3f9a" } });
    fireEvent.click(save);
    await waitFor(() => expect(callsOf("transcription_key_set")).toEqual([{ key: "gsk_abcdefghijklmnopqrstuvwxyz3f9a" }]));
    expect(await screen.findByText("Key saved. New recordings are transcribed with it.")).toBeInTheDocument();
  });

  it("says inline when Groq refused the key", async () => {
    fail("transcription_key_set", "Groq did not accept this key. Copy it again from console.groq.com → API Keys, then paste it here.");
    render(<TranscriptionKeyPage e={e} />);
    fireEvent.change(screen.getByLabelText("Groq key"), { target: { value: "gsk_abcdefghijklmnopqrstuvwxyz0000" } });
    fireEvent.click(screen.getByRole("button", { name: "Save" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("Groq did not accept this key.");
  });

  it("shows a saved key by its last four characters and removes it after asking", async () => {
    respond("transcription_key_delete", undefined);
    respond("entitlements_get", e);
    vi.spyOn(window, "confirm").mockReturnValue(true);
    render(<TranscriptionKeyPage e={{ ...e, transcription_key: { provider: "groq", set: true, last4: "3f9a" } }} />);
    expect(document.getElementById("setting-key.saved")).toHaveTextContent("Ending in 3f9a");
    fireEvent.click(screen.getByRole("button", { name: "Remove key" }));
    expect(window.confirm).toHaveBeenCalledWith("Remove your Groq key? New recordings won't be transcribed until you add a key or upgrade.");
    await waitFor(() => expect(callsOf("transcription_key_delete")).toHaveLength(1));
  });
});
