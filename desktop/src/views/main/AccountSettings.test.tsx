import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { SettingsView } from "./SettingsView";
import { RecordView } from "./RecordView";
import { EXPORT_POLL_MS, PlanCard } from "./AccountSettings";
import { StorageCard } from "./SecuritySettings";
import { errorMessage, type Account, type AppStatus } from "../../lib/ipc";
import { callsOf, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makePlan, makeSettings, makeStatus } from "../../test/fixtures";
import { minutesLine, percentUsed, storageLine, viewerSentence } from "../../lib/plan";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

function makeAccount(overrides: Partial<Account> = {}): Account {
  return {
    name: "Hassam",
    email: "hassam@example.test",
    email_verified: true,
    two_factor: false,
    google: true,
    created_at: "2026-11-01T09:00:00+00:00",
    workspace: { id: 1, name: "Acme team", role: "owner" },
    deletes_at: null,
    ...overrides,
  };
}

function makeRun() {
  const errors: string[] = [];
  const notices: string[] = [];
  const run = async (fn: () => Promise<unknown>, ok?: string) => {
    try {
      const result = await fn();
      if (ok) notices.push(ok);
      return result;
    } catch (e) {
      errors.push(errorMessage(e));
      return undefined;
    }
  };
  return { run: run as never, errors, notices };
}

async function openAccount(account: Account | null = makeAccount(), status: Partial<AppStatus> = {}) {
  respond("get_settings", makeSettings());
  respond("list_clients", { data: [] });
  respond("account_get", account);
  const helpers = makeRun();
  const onUnpaired = vi.fn();
  render(<SettingsView status={makeStatus(status)} run={helpers.run} onUnpaired={onUnpaired} />);
  await screen.findByRole("heading", { name: "Settings" });
  await flushAsync();
  return { ...helpers, onUnpaired };
}

const region = (name: string) => screen.getByRole("region", { name });

beforeEach(() => resetTauriMock());
afterEach(async () => {
  await flushAsync();
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe("Settings → Account", () => {
  it("shows who is signed in, the workspace and role, two-factor and Google", async () => {
    await openAccount(makeAccount({ two_factor: true, google: false }));
    expect(screen.getByRole("button", { name: /^Account/ })).toBeInTheDocument();
    const card = region("Your account");
    expect(within(card).getByText("Hassam")).toBeInTheDocument();
    expect(within(card).getByText("hassam@example.test")).toBeInTheDocument();
    expect(within(card).getByText("Acme team · Owner")).toBeInTheDocument();
    expect(within(card).getByText("On")).toBeInTheDocument();
    expect(within(card).getByText("Not connected")).toBeInTheDocument();
    expect(within(card).getByText("1 November 2026")).toBeInTheDocument();
    expect(callsOf("account_get")).toHaveLength(1);
  });

  it("has no Account group on a server without accounts", async () => {
    await openAccount(null);
    expect(screen.queryByRole("button", { name: /^Account/ })).not.toBeInTheDocument();
    expect(screen.queryByRole("region", { name: "Your account" })).not.toBeInTheDocument();
    expect(screen.getByRole("region", { name: "General" })).toBeInTheDocument();
  });

  it("says an address is not verified and turning two-factor on happens in the console", async () => {
    await openAccount(makeAccount({ email_verified: false, two_factor: false }));
    expect(within(region("Your account")).getByText("hassam@example.test (not verified yet)")).toBeInTheDocument();
    expect(within(region("Your account")).getByText(/Off: turn it on in the console/)).toBeInTheDocument();
  });

  it("tells a viewer, in the documented sentence, that this computer does not record", async () => {
    await openAccount(makeAccount({ workspace: { id: 1, name: "Acme team", role: "viewer" } }));
    expect(within(region("Your account")).getByRole("note")).toHaveTextContent("You are a viewer in Acme team: you can open what is shared with you, not record.");
  });

  it("signs out everywhere only after the confirmation, and then shows the sign-in", async () => {
    const signedOut = makeStatus({ paired: false });
    respond("account_sign_out_everywhere", signedOut);
    const confirm = vi.spyOn(window, "confirm").mockReturnValueOnce(false).mockReturnValueOnce(true);
    const { onUnpaired, notices } = await openAccount();

    fireEvent.click(within(region("Your account")).getByRole("button", { name: "Sign out everywhere" }));
    expect(confirm).toHaveBeenCalledWith(expect.stringContaining("Every computer, phone and browser signed in to your account is signed out, this computer too."));
    expect(callsOf("account_sign_out_everywhere")).toHaveLength(0);

    fireEvent.click(within(region("Your account")).getByRole("button", { name: "Sign out everywhere" }));
    await waitFor(() => expect(onUnpaired).toHaveBeenCalledWith(signedOut));
    expect(notices).toContain("Signed out everywhere");
  });

  it("shows when an account is being deleted", async () => {
    await openAccount(makeAccount({ deletes_at: "2026-11-13T09:00:00+00:00" }));
    expect(within(region("Your account")).getByRole("status")).toHaveTextContent("Your account will be deleted on 13 November 2026.");
    expect(within(region("Your data")).queryByRole("button", { name: "Delete my account…" })).not.toBeInTheDocument();
  });
});

describe("Settings → Account → Your data", () => {
  it("makes the export, asks about it every 5 seconds and opens the link once it is ready", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    respond("account_export_start", { id: "01JFEXPORT", status: "making", url: null, expires_at: null });
    respondWith("account_export_status", ({ id }) =>
      callsOf("account_export_status").length < 2
        ? { id, status: "making", url: null, expires_at: null }
        : { id, status: "ready", url: "https://ledger.example.test/exports/x.zip", expires_at: "2026-11-07T09:00:00+00:00" });
    respond("account_export_open", undefined);
    await openAccount();

    fireEvent.click(within(region("Your data")).getByRole("button", { name: "Export my data" }));
    expect(await within(region("Your data")).findByRole("button", { name: "Making your export…" })).toBeDisabled();
    expect(callsOf("account_export_status")).toHaveLength(0);

    await act(async () => void vi.advanceTimersByTime(EXPORT_POLL_MS));
    expect(callsOf("account_export_status")).toEqual([{ id: "01JFEXPORT" }]);
    await act(async () => void vi.advanceTimersByTime(EXPORT_POLL_MS));
    await flushAsync();
    expect(callsOf("account_export_status")).toHaveLength(2);
    expect(callsOf("account_export_open")).toEqual([{ url: "https://ledger.example.test/exports/x.zip" }]);
    expect(within(region("Your data")).getByRole("status")).toHaveTextContent("Your export is ready until 7 November 2026.");

    // Ready: no more asking, and the link opens again on request.
    await act(async () => void vi.advanceTimersByTime(EXPORT_POLL_MS * 3));
    expect(callsOf("account_export_status")).toHaveLength(2);
    fireEvent.click(within(region("Your data")).getByRole("button", { name: "Open the download" }));
    await flushAsync();
    expect(callsOf("account_export_open")).toHaveLength(2);
  });

  it("stops asking when the server cannot say, and says why", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    respond("account_export_start", { id: "01JFEXPORT", status: "making", url: null, expires_at: null });
    fail("account_export_status", "Network error: the server could not be reached");
    await openAccount();

    fireEvent.click(within(region("Your data")).getByRole("button", { name: "Export my data" }));
    await within(region("Your data")).findByRole("button", { name: "Making your export…" });
    await act(async () => void vi.advanceTimersByTime(EXPORT_POLL_MS));
    await flushAsync();
    expect(within(region("Your data")).getByRole("alert")).toHaveTextContent("Network error");
    await act(async () => void vi.advanceTimersByTime(EXPORT_POLL_MS * 3));
    expect(callsOf("account_export_status")).toHaveLength(1);
    expect(within(region("Your data")).getByRole("button", { name: "Export my data" })).toBeEnabled();
  });

  it("deletes the account with the password and shows when", async () => {
    respond("account_delete", "2026-11-13T09:00:00+00:00");
    await openAccount();

    fireEvent.click(within(region("Your data")).getByRole("button", { name: "Delete my account…" }));
    const form = screen.getByRole("form", { name: "Confirm deleting your account" });
    const submit = within(form).getByRole("button", { name: "Delete my account" });
    expect(submit).toBeDisabled();
    expect(within(form).queryByRole("button", { name: "Use a two-factor code instead" })).not.toBeInTheDocument();
    fireEvent.change(within(form).getByLabelText(/Your password/), { target: { value: "hunter2" } });
    fireEvent.click(submit);

    await waitFor(() => expect(callsOf("account_delete")).toEqual([{ password: "hunter2", otp: null }]));
    expect(await within(region("Your account")).findByRole("status")).toHaveTextContent("Your account will be deleted on 13 November 2026.");
    expect(screen.queryByRole("form", { name: "Confirm deleting your account" })).not.toBeInTheDocument();
  });

  it("offers the web page to an account that has no password to confirm with", async () => {
    respond("server_page_open", undefined);
    await openAccount(makeAccount({ google: true }));

    fireEvent.click(within(region("Your data")).getByRole("button", { name: "Delete my account…" }));
    const form = screen.getByRole("form", { name: "Confirm deleting your account" });
    expect(within(form).getByText(/Signed in with Google and no password\?/)).toBeInTheDocument();
    fireEvent.click(within(form).getByRole("button", { name: "Delete on the web page" }));

    await waitFor(() => expect(callsOf("server_page_open")).toEqual([{ page: "account/delete" }]));
    expect(callsOf("account_delete")).toEqual([]);
    // Opening the page leaves the form as it was: nothing is deleted until the person confirms there.
    expect(screen.getByRole("form", { name: "Confirm deleting your account" })).toBeInTheDocument();
  });

  it("confirms with a two-factor code instead, and shows a refusal in plain words", async () => {
    fail("account_delete", "You own a workspace that has other members. Hand it over to one of them on the console's Workspace page first, then delete your account.");
    await openAccount(makeAccount({ two_factor: true }));

    fireEvent.click(within(region("Your data")).getByRole("button", { name: "Delete my account…" }));
    const form = screen.getByRole("form", { name: "Confirm deleting your account" });
    fireEvent.click(within(form).getByRole("button", { name: "Use a two-factor code instead" }));
    fireEvent.change(within(form).getByLabelText(/Two-factor code/), { target: { value: " 123456 " } });
    fireEvent.click(within(form).getByRole("button", { name: "Delete my account" }));

    expect(await within(form).findByRole("alert")).toHaveTextContent("Hand it over to one of them on the console's Workspace page first");
    expect(callsOf("account_delete")).toEqual([{ password: null, otp: "123456" }]);
    fireEvent.click(within(form).getByRole("button", { name: "Cancel" }));
    expect(screen.queryByRole("form", { name: "Confirm deleting your account" })).not.toBeInTheDocument();
  });
});

describe("Settings → Account → Plan", () => {
  it("shows the limits next to the usage, and what the plan includes", async () => {
    await openAccount(makeAccount(), {
      plan: makePlan({ name: "Team", monthlyMinutes: 600, usedMinutes: 540, storageBytes: 10 * 1024 ** 3, usedStorageBytes: 2.5 * 1024 ** 3, retentionDays: 90, seats: 5, liveTranslate: false }),
    });
    const card = screen.getByTestId("plan-card");
    expect(within(card).getByText("Team")).toBeInTheDocument();
    expect(within(card).getByText("540 of 600 minutes this month")).toBeInTheDocument();
    expect(within(card).getByRole("meter", { name: "Transcription used" })).toHaveAttribute("aria-valuenow", "90");
    expect((within(card).getByRole("meter", { name: "Transcription used" }).firstElementChild as HTMLElement).style.transform).toBe("scaleX(0.9)");
    expect(within(card).getByText("2.5 GB of 10.0 GB stored")).toBeInTheDocument();
    expect(within(card).getByRole("meter", { name: "Storage used" })).toHaveAttribute("aria-valuenow", "25");
    expect(within(card).getByText("90 days")).toBeInTheDocument();
    expect(within(card).getByText("Up to 5")).toBeInTheDocument();
    expect(within(card).getByText("Live Translate: not in your plan")).toBeInTheDocument();
    expect(within(card).getByText("Screen moments: included")).toBeInTheDocument();
  });

  it("shows the plan's storage next to what the server holds", async () => {
    respond("storage_info", { recordings_bytes: 1536, recordings_count: 3, disk_free_bytes: null, disk_total_bytes: null, retention_days: 30 });
    render(<StorageCard limitBytes={5 * 1024 ** 3} />);
    expect(await screen.findByText("Recordings on the server: 1.5 KB in 3 files of the 5.0 GB your plan allows.")).toBeInTheDocument();
  });

  it("works without limits and on a server without plans", () => {
    expect(minutesLine(makePlan({ usedMinutes: 12.4 }))).toBe("12 minutes this month (no limit)");
    expect(storageLine(makePlan({ usedStorageBytes: null, storageBytes: null }))).toBeNull();
    expect(storageLine(makePlan({ usedStorageBytes: 1024 }))).toBe("1.0 KB stored (no limit)");
    expect(percentUsed(700, 600)).toBe(100);
    expect(percentUsed(5, null)).toBeNull();
    expect(viewerSentence(null)).toBe("You are a viewer in this workspace: you can open what is shared with you, not record.");
  });

  it("says how long the server keeps recordings when the plan sets no limit of its own", async () => {
    respond("storage_info", { recordings_bytes: 0, recordings_count: 0, disk_free_bytes: null, disk_total_bytes: null, retention_days: 30 });
    render(<PlanCard status={makeStatus({ plan: makePlan({ name: "Free", retentionDays: null }) })} />);
    expect(await within(screen.getByTestId("plan-card")).findByText("30 days")).toBeInTheDocument();
    expect(screen.queryByText("Until you delete them")).not.toBeInTheDocument();
  });

  it("names the server's setting when it cannot say how long, and asks the server only when the plan sets none", async () => {
    fail("storage_info", "Network error");
    render(<PlanCard status={makeStatus({ plan: makePlan({ name: "Free", retentionDays: null }) })} />);
    await waitFor(() => expect(callsOf("storage_info")).toHaveLength(1));
    expect(within(screen.getByTestId("plan-card")).getByText("As the server is set")).toBeInTheDocument();
    cleanup();

    render(<PlanCard status={makeStatus({ plan: makePlan({ name: "Team", retentionDays: 90 }) })} />);
    expect(within(screen.getByTestId("plan-card")).getByText("90 days")).toBeInTheDocument();
    expect(callsOf("storage_info")).toHaveLength(1);
  });

  it("explains an older server's plan", async () => {
    await openAccount(makeAccount(), { plan: makePlan({ known: false, name: null, monthlyMinutes: 3000, usedMinutes: 812.5 }) });
    const card = screen.getByTestId("plan-card");
    expect(within(card).getByText(/This server has no plans yet/)).toBeInTheDocument();
    expect(within(card).getByText("813 of 3,000 minutes this month")).toBeInTheDocument();
    expect(within(card).queryByRole("list", { name: "In your plan" })).not.toBeInTheDocument();
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

  it("offers a viewer no recording, only the documented sentence", async () => {
    await record({ plan: makePlan({ role: "viewer", workspace: "Acme team" }) });
    expect(screen.getByTestId("viewer-notice")).toHaveTextContent("You are a viewer in Acme team: you can open what is shared with you, not record.");
    expect(screen.queryByRole("button", { name: "Record now" })).not.toBeInTheDocument();
    expect(screen.queryByRole("switch", { name: "Toggle automatic call recording" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Record a voice note" })).not.toBeInTheDocument();
  });
});
