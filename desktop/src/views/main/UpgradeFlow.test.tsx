// The upgrade flow (v1.7): choose a plan and a promo code, request it, pay with the bank details and their Copy buttons,
// send up to three receipts a round (chosen, dropped or pasted; checked here and in the core), Send receipt, the
// request's timeline and Cancel request, and every refusal in calm words.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { UpgradeSheet, ibanGroups, receiptProblem, timelineSteps, ROUND_FULL, TOO_LARGE, WRONG_TYPE } from "./UpgradeFlow";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeUpgradeRequest, sampleEntitlements } from "../../test/fixtures";
import { closeUpgradeSheet, openUpgradeSheet, resetEntitlementsForTests } from "../../lib/entitlements";
import { fileDropClaim } from "../../lib/dropTarget";
import type { Entitlements, Proof, UpgradeRequest } from "../../lib/ipc";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const writeText = vi.fn((_t: string) => Promise.resolve());
beforeEach(() => {
  resetTauriMock();
  writeText.mockClear();
  Object.defineProperty(navigator, "clipboard", { configurable: true, value: { writeText } });
  respond("entitlements_get", null);
  respond("upgrade_request_latest", null);
  respond("upgrade_request_proof_file", { mime: "image/png", data: "iVBORw0KGgo=" });
  URL.createObjectURL = vi.fn(() => "blob:receipt");
  URL.revokeObjectURL = vi.fn();
});
afterEach(() => {
  act(() => {
    closeUpgradeSheet();
    resetEntitlementsForTests();
  });
});

function open(e: Entitlements, options: Parameters<typeof openUpgradeSheet>[1] = {}, feature: string | null = null) {
  respond("upgrade_request_latest", e.upgrade_request);
  resetEntitlementsForTests(e);
  render(<UpgradeSheet />);
  act(() => openUpgradeSheet(feature, options));
  return () => screen.getByRole("dialog");
}
const withRequest = (stage: Parameters<typeof makeUpgradeRequest>[0], overrides: Partial<UpgradeRequest> = {}) =>
  ({ ...sampleEntitlements("free"), upgrade_request: makeUpgradeRequest(stage, overrides) });
const proof = (id: string, overrides: Partial<Proof> = {}): Proof =>
  ({ id, kind: "image", mime: "image/webp", name: `${id}.png`, bytes: 1834, width: 600, height: 300, created_at: "2026-11-09T16:40:00Z", locked: false, ...overrides });

describe("step 1: Upgrade", () => {
  it("offers the plans above as radio cards, the next one chosen, with no plan colours", () => {
    const sheet = open(sampleEntitlements("free"));
    expect(sheet()).toHaveAccessibleName("Upgrade");
    expect(sheet()).toHaveTextContent("You're on Free.");
    const radios = within(sheet()).getAllByRole("radio");
    expect(radios.map((r) => r.getAttribute("aria-label"))).toEqual(["Pro, Rs 2,999 a month, selected", "Ultra, Rs 12,499 a month"]);
    expect(radios[0]).toHaveClass("chosen");
    expect(radios.every((r) => !r.getAttribute("style"))).toBe(true);
    expect(within(sheet()).getByText("To pay").parentElement).toHaveTextContent("Rs 2,999");
    expect(within(sheet()).getByRole("button", { name: "Request Pro" })).toHaveClass("primary");
    expect(sheet()).toHaveTextContent("You'll see our bank details on the next screen.");
  });

  it("opens on the plan a locked feature needs, and Pro sees only Ultra with what happens to its days", () => {
    const sheet = open(sampleEntitlements("free"), {}, "live_translate");
    expect(sheet()).toHaveTextContent("Live Translate is in Ultra. You're on Free.");
    expect(within(sheet()).getByRole("radio", { name: /^Ultra, .*selected/ })).toBeInTheDocument();
    act(() => closeUpgradeSheet());
    cleanup();
    const pro = open(sampleEntitlements("pro"));
    expect(within(pro()).getAllByRole("radio")).toHaveLength(1);
    expect(pro()).toHaveTextContent("Days left on Pro aren't refunded.");
  });

  it("requests the plan with the note and goes on to the bank details", async () => {
    respondWith("upgrade_request_create", ({ plan }) => makeUpgradeRequest("awaiting_payment", { plan: String(plan) }));
    const sheet = open(sampleEntitlements("free"));
    fireEvent.change(within(sheet()).getByLabelText(/Note for our team/), { target: { value: "Invoice for Acme" } });
    fireEvent.click(within(sheet()).getByRole("button", { name: "Request Pro" }));
    await waitFor(() => expect(callsOf("upgrade_request_create")).toEqual([{ plan: "pro", message: "Invoice for Acme", promoCode: null }]));
    expect(await screen.findByRole("dialog", { name: "Pay for Pro" })).toBeInTheDocument();
  });

  it("shows why a request didn't go, inline, and keeps the choices", async () => {
    fail("upgrade_request_create", "This plan isn't available right now. Please choose another.");
    const sheet = open(sampleEntitlements("free"));
    fireEvent.click(within(sheet()).getByRole("radio", { name: /^Ultra/ }));
    fireEvent.click(within(sheet()).getByRole("button", { name: "Request Ultra" }));
    expect(await within(sheet()).findByRole("alert")).toHaveTextContent("This plan isn't available right now. Please choose another.");
    expect(within(sheet()).getByRole("radio", { name: /^Ultra/ })).toHaveAttribute("aria-checked", "true");
  });

  it("applies a promo code: the struck price, the line under it, and the code sent; another plan's code says so", async () => {
    respondWith("promo_code_check", ({ code }) => (code === "SAVE50"
      ? { result: "valid", check: { code: "SAVE50", plan: "pro", plan_name: "Pro", percent: 50, price: "Rs 2,999 a month", final_price: "Rs 1,500 a month", free: false, months: 1 } }
      : { result: "other_plan", plan: "ultra", message: "This code is for Ultra." }));
    respond("upgrade_request_create", makeUpgradeRequest("awaiting_payment"));
    const sheet = open(sampleEntitlements("free"));
    fireEvent.click(within(sheet()).getByRole("button", { name: /^Promo code/ }));
    const code = within(sheet()).getByLabelText("Code");
    fireEvent.change(code, { target: { value: "save5" } });
    expect(within(sheet()).getByRole("button", { name: "Apply" })).toBeDisabled();
    fireEvent.change(code, { target: { value: "save50" } });
    expect(code).toHaveValue("SAVE50");
    fireEvent.click(within(sheet()).getByRole("button", { name: "Apply" }));
    expect(await within(sheet()).findByText("SAVE50: 50% off Pro for 1 month")).toBeInTheDocument();
    expect(within(sheet()).getByText("Rs 2,999").tagName).toBe("S");
    expect(sheet()).toHaveTextContent("for the first month");
    // Another plan: the code doesn't apply there and isn't sent.
    fireEvent.click(within(sheet()).getByRole("radio", { name: /^Ultra/ }));
    expect(within(sheet()).getByRole("alert")).toHaveTextContent("This code is for Pro.");
    fireEvent.click(within(sheet()).getByRole("radio", { name: /^Pro/ }));
    fireEvent.click(within(sheet()).getByRole("button", { name: "Request Pro" }));
    await waitFor(() => expect(callsOf("upgrade_request_create").at(-1)).toEqual({ plan: "pro", message: null, promoCode: "SAVE50" }));
  });

  it("switches the plan on at once with a 100 % code", async () => {
    respond("promo_code_check", { result: "valid", check: { code: "FREEPRO", plan: "pro", plan_name: "Pro", percent: 100, price: "Rs 2,999 a month", final_price: "Rs 0", free: true, months: 1 } });
    respond("upgrade_request_create", { ...makeUpgradeRequest("approved"), approved_plan: "pro", approved_until: "2026-12-09T10:00:00Z" });
    respond("entitlements_get", sampleEntitlements("pro"));
    const sheet = open(sampleEntitlements("free"));
    fireEvent.click(within(sheet()).getByRole("button", { name: /^Promo code/ }));
    fireEvent.change(within(sheet()).getByLabelText("Code"), { target: { value: "FREEPRO" } });
    fireEvent.click(within(sheet()).getByRole("button", { name: "Apply" }));
    fireEvent.click(await within(sheet()).findByRole("button", { name: "Switch on Pro" }));
    expect(await screen.findByRole("dialog", { name: "You're on Pro" })).toHaveTextContent("Thank you. Pro is on until 9 December 2026.");
  });

  it("goes to the open request instead of asking again (no second request, no Upgrade)", async () => {
    const sheet = open(withRequest("in_review"));
    expect(sheet()).toHaveAccessibleName("Pro request");
    expect(within(sheet()).queryByRole("radio")).not.toBeInTheDocument();
  });
});

describe("step 2: Pay for Pro", () => {
  it("shows the exact amount, the accounts with Copy, the IBAN in fours and the reference", async () => {
    const sheet = open(withRequest("awaiting_payment"), { step: "pay" });
    expect(sheet()).toHaveAccessibleName("Pay for Pro");
    expect(within(sheet()).getByText("Rs 2,999")).toHaveClass("flow-amount");
    expect(sheet()).toHaveTextContent("Send exactly this amount. A different amount can slow down your upgrade.");
    expect(within(sheet()).getByText("PK36 SCBL 0000 0011 2345 6702")).toBeInTheDocument();
    expect(within(sheet()).getByText("EXAMPLE PRIVATE LIMITED")).toBeInTheDocument();
    expect(within(sheet()).getByText("0123456789")).toBeInTheDocument();
    fireEvent.click(within(sheet()).getByRole("button", { name: "Copy iban" }));
    await waitFor(() => expect(writeText).toHaveBeenCalledWith("PK36SCBL0000001123456702"));
    fireEvent.click(within(sheet()).getByRole("button", { name: "Copy amount" }));
    await waitFor(() => expect(writeText).toHaveBeenLastCalledWith("2999"));
    fireEvent.click(within(sheet()).getByRole("button", { name: "Copy reference" }));
    await waitFor(() => expect(writeText).toHaveBeenLastCalledWith("EL-DZJRSB"));
    expect(sheet()).toHaveTextContent("It should say EXAMPLE PRIVATE LIMITED.");
    expect(within(sheet()).getByRole("button", { name: "Add payment receipt" })).toHaveClass("primary");
    fireEvent.click(within(sheet()).getByRole("button", { name: "I'll pay later" }));
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
  });

  it("shows the staff's text when no bank details are set", () => {
    const r = makeUpgradeRequest("awaiting_payment");
    const sheet = open({ ...sampleEntitlements("free"), upgrade_request: { ...r, payment: { ...r.payment!, accounts: [], note: "Pay to Meezan Bank\nAccount 0123" } } }, { step: "pay" });
    expect(within(sheet()).getByTestId("payment-note")).toHaveTextContent("Pay to Meezan Bank Account 0123");
    expect(within(sheet()).queryByRole("button", { name: "Copy iban" })).not.toBeInTheDocument();
  });

  it("keeps the details of a v1.6 server's request readable from its instructions", () => {
    const r = makeUpgradeRequest("awaiting_payment");
    const sheet = open({ ...sampleEntitlements("free"), upgrade_request: { ...r, payment: null } }, { step: "pay" });
    expect(within(sheet()).getByTestId("payment-note")).toHaveTextContent("Account title: EXAMPLE PRIVATE LIMITED");
  });
});

describe("step 3: Send receipt", () => {
  it("checks a file's size and type before anything is sent", () => {
    expect(receiptProblem({ name: "r.png", type: "image/png", size: 6 * 1024 * 1024 })).toBe(TOO_LARGE);
    expect(receiptProblem({ name: "r.heic", type: "image/heic", size: 100 })).toBe(WRONG_TYPE);
    expect(receiptProblem({ name: "r.docx" })).toBe(WRONG_TYPE);
    expect(receiptProblem({ name: "r.PDF" })).toBeNull();
    expect(receiptProblem({ name: "r.webp", type: "image/webp", size: 100 })).toBeNull();
  });

  it("sends chosen files through the core, lets one be removed before sending, and enables Send receipt", async () => {
    respond("upgrade_receipt_pick", ["C:\\Users\\me\\Receipt.png", "C:\\Users\\me\\Statement.pdf"]);
    respondWith("upgrade_request_proof", ({ path }) => proof(String(path).includes("pdf") ? "P2" : "P1", { kind: String(path).includes("pdf") ? "pdf" : "image" }));
    respond("upgrade_request_proof_delete", undefined);
    respond("upgrade_request_paid", makeUpgradeRequest("in_review"));
    respond("entitlements_get", withRequest("in_review"));
    const sheet = open(withRequest("awaiting_payment"), { step: "receipt" });
    expect(sheet()).toHaveAccessibleName("Send receipt");
    const send = within(sheet()).getByRole("button", { name: "Send receipt" });
    expect(send).toBeDisabled();
    fireEvent.click(within(sheet()).getByRole("button", { name: "Choose file…" }));
    await waitFor(() => expect(within(sheet()).getAllByTestId("receipt-tile")).toHaveLength(2));
    expect(callsOf("upgrade_request_proof").map((c) => c.path)).toEqual(["C:\\Users\\me\\Receipt.png", "C:\\Users\\me\\Statement.pdf"]);
    expect(within(sheet()).getAllByTestId("receipt-tile")[1]).toHaveTextContent("P2.png");
    fireEvent.click(within(sheet()).getByRole("button", { name: "Remove receipt 2" }));
    await waitFor(() => expect(callsOf("upgrade_request_proof_delete")).toEqual([{ id: "01m70jccdjr5m148e49763r5w6", proof: "P2" }]));
    expect(within(sheet()).getAllByTestId("receipt-tile")).toHaveLength(1);
    fireEvent.change(within(sheet()).getByLabelText(/Transaction ID/), { target: { value: "FT26313ABC7781" } });
    fireEvent.change(within(sheet()).getByLabelText(/Paid from/), { target: { value: "Sara Khan" } });
    expect(send).toBeEnabled();
    fireEvent.click(send);
    await waitFor(() => expect(callsOf("upgrade_request_paid")).toEqual([{ id: "01m70jccdjr5m148e49763r5w6", reference: "FT26313ABC7781", payerName: "Sara Khan" }]));
    expect(await screen.findByRole("dialog", { name: "Pro request" })).toBeInTheDocument();
  });

  it("takes a pasted screenshot as base64, and a file dropped on the window instead of importing it", async () => {
    respondWith("upgrade_request_proof_data", () => proof("P3"));
    respondWith("upgrade_request_proof", () => proof("P4"));
    const sheet = open(withRequest("awaiting_payment"), { step: "receipt" });
    const file = new File([new Uint8Array([137, 80, 78, 71])], "image.png", { type: "image/png" });
    const paste = new Event("paste", { bubbles: true, cancelable: true }) as Event & { clipboardData: unknown };
    paste.clipboardData = { files: [file] };
    await act(async () => {
      document.dispatchEvent(paste);
    });
    await waitFor(() => expect(callsOf("upgrade_request_proof_data")).toHaveLength(1));
    expect(callsOf("upgrade_request_proof_data")[0]).toEqual({ id: "01m70jccdjr5m148e49763r5w6", name: "image.png", data: "iVBORw==" });
    expect(fileDropClaim()).not.toBeNull();
    fileDropClaim()!(["/Users/me/Desktop/Receipt.jpg"]);
    await waitFor(() => expect(callsOf("upgrade_request_proof")).toEqual([{ id: "01m70jccdjr5m148e49763r5w6", path: "/Users/me/Desktop/Receipt.jpg" }]));
    await waitFor(() => expect(within(sheet()).getAllByTestId("receipt-tile")).toHaveLength(2));
    await emitEvent("tauri://drag-enter", {});
    expect(within(sheet()).getByTestId("receipt-drop")).toHaveClass("over");
    act(() => closeUpgradeSheet());
    expect(fileDropClaim()).toBeNull();
  });

  it("refuses a fourth file in a round, and a wrong type or size on its tile", async () => {
    const r = makeUpgradeRequest("awaiting_payment");
    const e = { ...sampleEntitlements("free"), upgrade_request: { ...r, payment: { ...r.payment!, proofs: [proof("A"), proof("B")] } } };
    respond("upgrade_receipt_pick", ["/a/notes.docx", "/a/r1.png", "/a/r2.png"]);
    respondWith("upgrade_request_proof", () => proof("C"));
    const sheet = open(e, { step: "receipt" });
    fireEvent.click(within(sheet()).getByRole("button", { name: "Choose file…" }));
    expect(await within(sheet()).findByRole("alert")).toHaveTextContent(ROUND_FULL);
    expect(within(sheet()).getByText(WRONG_TYPE).closest("li")).toHaveTextContent("Couldn't upload");
    expect(callsOf("upgrade_request_proof")).toHaveLength(1);
  });

  it("says on the tile why the server refused a file, with Try again", async () => {
    let n = 0;
    respondWith("upgrade_request_proof", () => (++n === 1 ? Promise.reject("We couldn't read this picture. Please try another screenshot.") : proof("P5")));
    respond("upgrade_receipt_pick", ["/a/r.png"]);
    const sheet = open(withRequest("awaiting_payment"), { step: "receipt" });
    fireEvent.click(within(sheet()).getByRole("button", { name: "Choose file…" }));
    const tile = await within(sheet()).findByText("Couldn't upload");
    expect(tile.closest("li")).toHaveTextContent("We couldn't read this picture. Please try another screenshot.");
    fireEvent.click(within(sheet()).getByRole("button", { name: "Try again" }));
    await waitFor(() => expect(within(sheet()).queryByText("Couldn't upload")).not.toBeInTheDocument());
  });

  it("asks for a receipt or a transaction ID, and checks the ID's shape", async () => {
    const sheet = open(withRequest("awaiting_payment"), { step: "receipt" });
    fireEvent.change(within(sheet()).getByLabelText(/Transaction ID/), { target: { value: "x;y" } });
    expect(within(sheet()).getByRole("button", { name: "Send receipt" })).toBeDisabled();
    fireEvent.change(within(sheet()).getByLabelText(/Transaction ID/), { target: { value: "TID 12345" } });
    expect(within(sheet()).getByRole("button", { name: "Send receipt" })).toBeEnabled();
    fail("upgrade_request_paid", "Add a screenshot, or type the transaction ID.");
    fireEvent.click(within(sheet()).getByRole("button", { name: "Send receipt" }));
    expect(await within(sheet()).findByRole("alert")).toHaveTextContent("Add a screenshot, or type the transaction ID.");
  });

  it("a new round: staff's note first, the files already sent locked with their date, a new round of three", async () => {
    const sheet = open(withRequest("needs_receipt"), { step: "receipt" });
    expect(sheet()).toHaveTextContent("The receipt is cut off, so we can't see the date.");
    const tiles = within(sheet()).getAllByTestId("receipt-tile");
    expect(tiles).toHaveLength(2);
    expect(tiles[0]).toHaveTextContent("Sent 9 Nov");
    expect(tiles[1]).toHaveAttribute("aria-label", "Receipt 2 of 2, PDF");
    expect(within(tiles[0]).queryByRole("button", { name: /Remove/ })).not.toBeInTheDocument();
    await flushAsync();
    expect(callsOf("upgrade_request_proof_file")[0]).toEqual({ id: "01m70jccemr21p6h74ena5zb44", proof: "01m70jccfhqyke5azzszjqkvbe", variant: "thumb" });
    expect(tiles[0].querySelector("img")).toHaveAttribute("src", "blob:receipt");
    act(() => closeUpgradeSheet());
    expect(URL.revokeObjectURL).toHaveBeenCalledWith("blob:receipt");
  });
});

describe("the request's status", () => {
  it("draws the timeline as far as the request has come, then the details and the receipts", async () => {
    const sheet = open(withRequest("in_review"), { step: "status" });
    const steps = within(within(sheet()).getByRole("list", { name: "Progress" })).getAllByRole("listitem");
    expect(steps.map((s) => s.getAttribute("aria-label")?.split(",").slice(0, 2).join(","))).toEqual(["Requested, done", "Receipt sent, done", "Checked by our team, current", "Pro on, not yet"]);
    expect(sheet()).toHaveTextContent("Transaction ID");
    expect(within(sheet()).getAllByTestId("receipt-tile")).toHaveLength(2);
    expect(sheet()).toHaveTextContent("FT2611097781");
  });

  it("puts a new receipt asked for under Receipt sent, and closes decided requests with one last step", () => {
    const r = makeUpgradeRequest("needs_receipt");
    expect(timelineSteps(r, "Pro").map((s) => s.label)).toEqual(["Requested", "Receipt sent", "New receipt asked for", "Checked by our team", "Pro on"]);
    expect(timelineSteps(makeUpgradeRequest("declined"), "Pro").map((s) => s.label)).toEqual(["Requested", "Receipt sent", "Not approved"]);
    expect(timelineSteps(makeUpgradeRequest("cancelled"), "Pro").map((s) => s.label)).toEqual(["Requested", "Receipt sent", "Cancelled by you"]);
    expect(timelineSteps(makeUpgradeRequest("approved"), "Pro").at(-1)).toEqual({ label: "Pro on", state: "done", at: null, note: "until 9 Dec 2026" });
  });

  it("cancels after asking, and says calmly when the request was decided meanwhile", async () => {
    fail("upgrade_request_cancel", "This request has already been decided.");
    const sheet = open(withRequest("awaiting_payment"), { step: "status" });
    fireEvent.click(within(sheet()).getAllByRole("button", { name: "Cancel request" })[0]);
    const dialog = screen.getByRole("dialog", { name: "Cancel this request?" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Cancel request" }));
    expect(await within(dialog).findByRole("alert")).toHaveTextContent("This request has already been decided.");
    expect(callsOf("upgrade_request_latest").length).toBeGreaterThan(0);
  });

  it("writes IBANs in groups of four", () => {
    expect(ibanGroups("PK36SCBL0000001123456702")).toBe("PK36 SCBL 0000 0011 2345 6702");
  });
});
