// Settings › Account (v1.7 pages): Profile, Sign-in & security and Privacy & data (consent, export, deleting the
// account); and what the plan leaves out on the Record page.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { RecordView } from "./RecordView";
import { ConsentPage, EXPORT_POLL_MS, PrivacyPage, ProfilePage, SecurityPage, workspaceLine } from "./AccountSettings";
import { errorMessage, type Account, type AppStatus } from "../../lib/ipc";
import { callsOf, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeEntitlements, makePlan, makeSettings, makeStatus } from "../../test/fixtures";
import { resetEntitlementsForTests } from "../../lib/entitlements";
import { minutesLine, percentUsed, storageLine, viewerSentence } from "../../lib/plan";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

function makeAccount(overrides: Partial<Account> = {}): Account {
  return {
    name: "Hassam Ali", email: "hassam@example.test", email_verified: true, two_factor: false, google: true,
    created_at: "2026-11-01T09:00:00+00:00", workspace: { id: 1, name: "Acme team", role: "owner" }, deletes_at: null, ...overrides,
  };
}

function makeRun() {
  const errors: string[] = [];
  const run = async (fn: () => Promise<unknown>) => {
    try {
      return await fn();
    } catch (e) {
      errors.push(errorMessage(e));
      return undefined;
    }
  };
  return { run: run as never, errors };
}

beforeEach(() => resetTauriMock());
afterEach(async () => {
  await flushAsync();
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe("Profile and Sign-in & security", () => {
  it("shows the name, email, team and member since, and edits on the website", async () => {
    respond("server_page_open", undefined);
    const status = makeStatus({ plan: makePlan({ role: "admin", workspace: "Acme team", seats: 5 }) });
    render(<ProfilePage account={makeAccount({ email_verified: false, workspace: { id: 1, name: "Acme team", role: "admin" } })} status={status} run={makeRun().run} />);
    expect(document.getElementById("setting-profile.name")).toHaveTextContent("Hassam Ali");
    expect(document.getElementById("setting-profile.email")).toHaveTextContent("hassam@example.test (not verified)");
    expect(document.getElementById("setting-profile.workspace")).toHaveTextContent("Acme team, Admin");
    expect(document.getElementById("setting-profile.since")).toHaveTextContent("1 Nov 2026");
    fireEvent.click(screen.getByRole("button", { name: "Edit profile, opens in browser" }));
    await waitFor(() => expect(callsOf("server_page_open")).toEqual([{ page: "app/profile", serverUrl: null }]));
  });

  it("names a team workspace with the role, and one's own not at all", () => {
    expect(workspaceLine(makeAccount(), makeStatus())).toBeNull();
    expect(workspaceLine(makeAccount({ workspace: { id: 1, name: "Acme", role: "member" } }), makeStatus())).toBe("Acme, Member");
  });

  it("shows two-step and Google, and signs out everywhere only after asking", async () => {
    respond("account_sign_out_everywhere", makeStatus({ paired: false }));
    const onSignedOut = vi.fn();
    render(<SecurityPage account={makeAccount({ two_factor: true })} run={makeRun().run} onSignedOut={onSignedOut} onActivity={vi.fn()} />);
    expect(document.getElementById("setting-security.two_step")).toHaveTextContent("On");
    expect(document.getElementById("setting-security.google")).toHaveTextContent("Connected");
    fireEvent.click(screen.getByRole("button", { name: "Sign out everywhere" }));
    const dialog = screen.getByRole("dialog", { name: "Sign out everywhere?" });
    expect(dialog).toHaveTextContent("You'll be signed out on every phone, computer and browser, including this one.");
    fireEvent.click(within(dialog).getByRole("button", { name: "Cancel" }));
    expect(callsOf("account_sign_out_everywhere")).toHaveLength(0);
    fireEvent.click(screen.getByRole("button", { name: "Sign out everywhere" }));
    fireEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: "Sign out everywhere" }));
    await waitFor(() => expect(onSignedOut).toHaveBeenCalled());
  });
});

describe("Privacy & data", () => {
  const privacy = (account: Account | null = makeAccount(), consent = true) => {
    const onAccount = vi.fn();
    const onConsent = vi.fn();
    render(<PrivacyPage account={account} settings={makeSettings({ consentAcknowledged: consent })} onAccount={onAccount} onConsent={onConsent} />);
    return { onAccount, onConsent };
  };

  it("says whether the consent notice was read and opens it; it is never a switch", async () => {
    const { onConsent } = privacy(makeAccount(), false);
    expect(document.getElementById("setting-privacy.consent")).toHaveTextContent("Not yet");
    fireEvent.click(screen.getByRole("button", { name: /^Recording consent/ }));
    expect(onConsent).toHaveBeenCalled();
    const patch = vi.fn(async () => true);
    render(<ConsentPage status={makeStatus()} settings={makeSettings({ consentAcknowledged: false })} patch={patch} />);
    fireEvent.click(screen.getByRole("button", { name: "I understand" }));
    expect(patch).toHaveBeenCalledWith({ consentAcknowledged: true });
    expect(screen.queryByRole("switch")).not.toBeInTheDocument();
  });

  it("makes the export, asks about it every 5 seconds and opens the link once it is ready", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    respond("account_export_start", { id: "01JFEXPORT", status: "making", url: null, expires_at: null });
    respondWith("account_export_status", ({ id }) =>
      callsOf("account_export_status").length < 2
        ? { id, status: "making", url: null, expires_at: null }
        : { id, status: "ready", url: "https://ledger.example.test/exports/x.zip", expires_at: "2026-11-07T09:00:00+00:00" });
    respond("account_export_open", undefined);
    privacy();
    fireEvent.click(screen.getByRole("button", { name: /^Export my data/ }));
    expect(await screen.findByRole("button", { name: /Making your export…/ })).toBeDisabled();
    await act(async () => void vi.advanceTimersByTime(EXPORT_POLL_MS));
    expect(callsOf("account_export_status")).toEqual([{ id: "01JFEXPORT" }]);
    await act(async () => void vi.advanceTimersByTime(EXPORT_POLL_MS));
    await flushAsync();
    expect(callsOf("account_export_open")).toEqual([{ url: "https://ledger.example.test/exports/x.zip" }]);
    await act(async () => void vi.advanceTimersByTime(EXPORT_POLL_MS * 3));
    expect(callsOf("account_export_status")).toHaveLength(2);
    fireEvent.click(screen.getByRole("button", { name: /Download your export/ }));
    await flushAsync();
    expect(callsOf("account_export_open")).toHaveLength(2);
  });

  it("stops asking when the server can't say, and says why", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    respond("account_export_start", { id: "01JFEXPORT", status: "making", url: null, expires_at: null });
    fail("account_export_status", "Couldn't reach EchoLedger. Try again.");
    privacy();
    fireEvent.click(screen.getByRole("button", { name: /^Export my data/ }));
    await screen.findByRole("button", { name: /Making your export…/ });
    await act(async () => void vi.advanceTimersByTime(EXPORT_POLL_MS));
    await flushAsync();
    expect(screen.getByRole("alert")).toHaveTextContent("Couldn't reach EchoLedger");
    expect(screen.getByRole("button", { name: /^Export my data/ })).toBeEnabled();
  });

  it("deletes the account with the password after asking, and says when", async () => {
    respond("account_delete", "2026-11-13T09:00:00+00:00");
    const { onAccount } = privacy();
    fireEvent.click(screen.getByRole("button", { name: "Delete my account" }));
    const form = screen.getByRole("form", { name: "Confirm deleting your account" });
    const submit = within(form).getByRole("button", { name: "Delete my account" });
    expect(submit).toBeDisabled();
    expect(within(form).queryByRole("button", { name: "Use a two-step code instead" })).not.toBeInTheDocument();
    fireEvent.change(within(form).getByLabelText("Your password"), { target: { value: "hunter2" } });
    fireEvent.click(submit);
    await waitFor(() => expect(callsOf("account_delete")).toEqual([{ password: "hunter2", otp: null }]));
    expect(onAccount).toHaveBeenCalledWith(expect.objectContaining({ deletes_at: "2026-11-13T09:00:00+00:00" }));
  });

  it("offers the website, and a two-step code, and shows a refusal in plain words", async () => {
    respond("server_page_open", undefined);
    fail("account_delete", "You own a workspace that has other members. Hand it over to one of them on the console's Workspace page first, then delete your account.");
    privacy(makeAccount({ two_factor: true }));
    fireEvent.click(screen.getByRole("button", { name: "Delete my account" }));
    const form = screen.getByRole("form", { name: "Confirm deleting your account" });
    fireEvent.click(within(form).getByRole("button", { name: "Delete on the website" }));
    await waitFor(() => expect(callsOf("server_page_open")).toEqual([{ page: "account/delete", serverUrl: null }]));
    fireEvent.click(within(form).getByRole("button", { name: "Use a two-step code instead" }));
    fireEvent.change(within(form).getByLabelText("Two-step code"), { target: { value: " 123456 " } });
    fireEvent.click(within(form).getByRole("button", { name: "Delete my account" }));
    expect(await within(form).findByRole("alert")).toHaveTextContent("Hand it over to one of them on the console's Workspace page first");
    expect(callsOf("account_delete")).toEqual([{ password: null, otp: "123456" }]);
  });

  it("while a deletion is scheduled, says when and offers Cancel deletion instead of Delete", () => {
    privacy(makeAccount({ deletes_at: "2026-12-09T09:00:00Z" }));
    expect(screen.queryByRole("button", { name: "Delete my account" })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: /^Cancel deletion/ })).toHaveTextContent("Deletes on 9 Dec 2026");
  });

  it("keeps only the consent notice on a server without accounts", () => {
    privacy(null);
    expect(screen.queryByRole("button", { name: /^Export my data/ })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: /^Recording consent/ })).toBeInTheDocument();
  });
});

describe("the v1.5 plan words", () => {
  it("keep the documented sentences", () => {
    expect(viewerSentence("Acme team")).toBe("You are a viewer in Acme team: you can open what is shared with you, not record.");
    expect(percentUsed(50, 100)).toBe(50);
    expect(typeof minutesLine(makePlan())).toBe("string");
    expect(storageLine(makePlan({ storageBytes: null, usedStorageBytes: null }))).toBeNull();
  });
});

describe("Plan features and the viewer on the Record page", () => {
  async function record(status: Partial<AppStatus>) {
    respond("mic_users", []);
    await act(async () => {
      render(<RecordView status={makeStatus(status)} run={vi.fn(async (fn: () => Promise<unknown>) => fn()) as never} />);
    });
  }

  it("hides Live Translate when the plan leaves it out", async () => {
    await record({ plan: makePlan({ liveTranslate: false }) });
    expect(screen.getByRole("button", { name: "Record now" })).toBeInTheDocument();
    expect(screen.queryByText("Live Translate")).not.toBeInTheDocument();
  });

  it("shows Live Translate when the plan has it", async () => {
    await record({ plan: makePlan({ liveTranslate: true }) });
    expect(screen.getByText("Live Translate")).toBeInTheDocument();
  });

  it("puts Live Translate's badge on the Record page when the entitlements leave it out (v1.6)", async () => {
    resetEntitlementsForTests(makeEntitlements());
    try {
      await record({ plan: makePlan({ liveTranslate: false }) });
      expect(screen.getByTestId("locked-live_translate")).toHaveTextContent("Live Translate");
      expect(screen.getByRole("button", { name: "Live Translate, in Business" })).toHaveTextContent("Business");
      expect(screen.queryByRole("combobox")).not.toBeInTheDocument();
    } finally {
      act(() => resetEntitlementsForTests());
    }
  });

  it("offers a viewer no recording, only the documented sentence", async () => {
    await record({ plan: makePlan({ role: "viewer", workspace: "Acme team" }) });
    expect(screen.getByTestId("viewer-notice")).toHaveTextContent("You are a viewer in Acme team: you can open what is shared with you, not record.");
    expect(screen.queryByRole("button", { name: "Record now" })).not.toBeInTheDocument();
    expect(screen.queryByRole("switch", { name: "Toggle automatic call recording" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Record a voice note" })).not.toBeInTheDocument();
  });
});
