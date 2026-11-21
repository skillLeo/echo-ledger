// Your account from the app (contract 2.0.0-l): every flow of Profile and Sign-in & security in both designs (the name,
// the picture, the email, the password, two-step sign-in with its QR code and recovery codes, Google, passkeys) and
// the answers the server can give them, said in the app's words. The proof rule: with two-step on, every proof is a
// two-step code, never the password alone.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { Account, AppStatus } from "../../lib/ipc";
import { errorMessage } from "../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeSettings, makeStatus } from "../../test/fixtures";
import { ProfilePage, SecurityPage } from "./AccountSettings";
import { ProfilePageV2 } from "../../v2/settings/pages/ProfileV2";
import { SecurityPageV2 } from "../../v2/settings/pages/SecurityV2";
import { EMAIL_POLL_MS } from "./AccountFlows";
import type { AccountReply, SecurityInfo } from "../../lib/account";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const ME: Account = {
  name: "Ayesha Siddiqui", email: "ayesha@qureshitextiles.pk", email_verified: true, two_factor: false, google: false, created_at: null, workspace: null, deletes_at: null,
  avatar_url: null, avatar_version: null, has_password: true, pending_email: null, pending_email_expires_at: null, passkeys_available: true,
};
const SECURITY: SecurityInfo = {
  password: true, google: { linked: false, email: null }, passkeys: [], two_factor: { enabled: false, recovery_codes_left: null }, browsers: [], devices: [],
};
const CODES = ["aaaa-1111", "bbbb-2222", "cccc-3333", "dddd-4444", "eeee-5555", "ffff-6666", "gggg-7777", "hhhh-8888"];

const ok = (data: unknown = null, status = 200, extra: Partial<AccountReply> = {}): AccountReply =>
  ({ ok: true, status, data: data as AccountReply["data"], code: null, message: null, accepts: [], errorCodes: null, errors: null, retryAfter: null, qr: null, ...extra });
const refuse = (status: number, code: string, extra: Partial<AccountReply> = {}): AccountReply =>
  ({ ok: false, status, data: null, code, message: `The server said ${code}.`, accepts: [], errorCodes: null, errors: null, retryAfter: null, qr: null, ...extra });

type Call = { action: string; id: string | null; body: Record<string, unknown> | null };
/** The account routes as a table: action → reply (or a function of the call). */
function serve(table: Record<string, AccountReply | ((c: Call) => AccountReply)>) {
  respondWith("account_call", (args) => {
    const c = args as unknown as Call;
    const answer = table[c.action];
    if (!answer) throw new Error(`no reply for ${c.action}`);
    return typeof answer === "function" ? answer(c) : answer;
  });
}
const calls = (action: string) => (callsOf("account_call") as unknown as Call[]).filter((c) => c.action === action);

let said: { ok: string[]; error: string[] };
const run = async <T,>(fn: () => Promise<T>, okText?: string) => {
  try {
    const r = await fn();
    if (okText) said.ok.push(okText);
    return r;
  } catch (e) {
    said.error.push(errorMessage(e));
    return undefined;
  }
};

type Design = "classic" | "v2";
const profile = (design: Design, account: Account = ME) =>
  render(design === "classic" ? <ProfilePage account={account} status={makeStatus()} run={run} />
    : <div className="el-root"><ProfilePageV2 account={account} status={makeStatus()} run={run} /></div>);
const security = (design: Design, account: Account = ME) =>
  render(design === "classic" ? <SecurityPage account={account} run={run} onSignedOut={(_s: AppStatus) => undefined} onActivity={() => undefined} />
    : <div className="el-root"><SecurityPageV2 account={account} run={run} onSignedOut={() => undefined} onActivity={() => undefined} /></div>);
const dialog = () => screen.queryByRole("dialog") ?? screen.getByRole("alertdialog");
const type = (label: string, value: string) => fireEvent.change(within(dialog()).getByLabelText(label), { target: { value } });
const press = async (name: string | RegExp) => {
  fireEvent.click(within(dialog()).getByRole("button", { name }));
  await act(flushAsync);
};
const openRow = async (name: string | RegExp) => {
  fireEvent.click(screen.getByRole("button", { name }));
  await act(flushAsync);
};

beforeEach(() => {
  resetTauriMock();
  said = { ok: [], error: [] };
  respond("get_settings", makeSettings({ serverUrl: "https://ledger.example.test" }));
  respond("auth_options", { password: true, google: false, signUp: false, signUpUrl: null, resetUrl: null, browserSignIn: false, code: false, passwordRules: { min: 12, letters: true, numbers: true } });
  respond("account_avatar_image", null);
  respond("server_page_open", null);
  serve({ security: ok(SECURITY) });
});
afterEach(() => {
  vi.useRealTimers();
  document.documentElement.lang = "";
});

describe.each(["classic", "v2"] as const)("Profile, %s", (design) => {
  it("Change name: checked first (any script, ZWNJ allowed; no direction overrides), then saved", async () => {
    serve({ name: (c) => ok({ ...ME, name: c.body?.name }) });
    profile(design);
    await act(flushAsync);
    await openRow("Change name");
    type("Name", "Ayesha ‮evil");
    expect(dialog()).toHaveTextContent("Use letters, numbers, spaces and . ' - ( ) , only.");
    await press("Save");
    expect(calls("name")).toEqual([]);
    type("Name", "عائشہ‌صدیقی");
    await press("Save");
    expect(calls("name")).toEqual([{ action: "name", id: null, body: { name: "عائشہ‌صدیقی" } }]);
    expect(said.ok).toEqual(["Name changed"]);
    expect(screen.queryByRole("dialog")).toBeNull();
  });

  it("Change name: the server's field code is said in the app's words", async () => {
    serve({ name: refuse(422, "validation_failed", { errorCodes: { name: [{ code: "too_long", max: 120 }] }, errors: { name: ["The name may not be greater than 120 characters."] } }) });
    profile(design);
    await act(flushAsync);
    await openRow("Change name");
    type("Name", "Ayesha S.");
    await press("Save");
    expect(dialog()).toHaveTextContent("Use 120 characters at most.");
  });

  it("Change picture: the core's picker and upload; a refusal says why; cancelling says nothing", async () => {
    respond("account_avatar_choose", ok({ avatar_url: "https://ledger.example.test/api/avatars/1/2?signature=x", avatar_version: 2 }));
    profile(design);
    await act(flushAsync);
    await openRow("Change picture");
    expect(said.ok).toEqual(["Picture changed"]);
    // With a picture, Remove picture is offered.
    expect(screen.getByRole("button", { name: "Remove picture" })).toBeInTheDocument();
    respond("account_avatar_choose", refuse(422, "invalid_image", { errorCodes: { image: [{ code: "invalid_image", reason: "too_small" }] } }));
    await openRow("Change picture");
    expect(said.error).toEqual(["This picture is too small. Choose one at least 128 pixels wide and tall."]);
    respond("account_avatar_choose", null);
    await openRow("Change picture");
    expect(said.ok).toHaveLength(1);
    expect(said.error).toHaveLength(1);
  });

  it("Remove picture asks first", async () => {
    serve({ avatar_remove: ok(null, 204) });
    profile(design, { ...ME, avatar_url: "https://ledger.example.test/a", avatar_version: 3 });
    await act(flushAsync);
    expect(callsOf("account_avatar_image")).toEqual([{ url: "https://ledger.example.test/a", version: 3 }]);
    await openRow("Remove picture");
    expect(dialog()).toHaveAccessibleName("Remove your picture?");
    await press("Remove");
    expect(calls("avatar_remove")).toHaveLength(1);
    expect(said.ok).toEqual(["Picture removed"]);
    expect(screen.queryByRole("button", { name: "Remove picture" })).toBeNull();
  });

  it("Change email with two-step on: the new address and a two-step code, then the code from the new address", async () => {
    serve({
      email: ok({ pending_email: "ayesha@new.pk", pending_email_expires_at: "2026-11-21T17:00:00Z" }, 202),
      email_confirm: (c) => (c.body?.code === "123456" ? ok({ ...ME, email: "ayesha@new.pk" }) : refuse(422, "invalid_code")),
    });
    profile(design, { ...ME, two_factor: true });
    await act(flushAsync);
    await openRow("Change email");
    expect(within(dialog()).queryByLabelText("Your password")).toBeNull();
    type("New email", "ayesha@new.pk");
    type("Two-step code", "654 321");
    await press("Send code");
    expect(calls("email")).toEqual([{ action: "email", id: null, body: { email: "ayesha@new.pk", otp: "654321" } }]);
    expect(dialog()).toHaveAccessibleName("Check your new address");
    type("Code from the email", "000000");
    await press("Confirm");
    expect(dialog()).toHaveTextContent("That code didn't work. Check the email and type the 6-digit code.");
    type("Code from the email", "123 456");
    await press("Confirm");
    expect(said.ok).toEqual(["Email changed"]);
  });

  it("Change email: a taken address, a wrong password, and a change that has ended", async () => {
    let answer = refuse(422, "email_taken");
    serve({ email: () => answer, email_confirm: refuse(410, "expired") });
    profile(design);
    await act(flushAsync);
    await openRow("Change email");
    type("Your password", "secret");
    type("New email", "not-an-address");
    await press("Send code");
    expect(dialog()).toHaveTextContent("Type a full email address, like name@example.com.");
    expect(calls("email")).toEqual([]);
    type("New email", "taken@example.com");
    await press("Send code");
    expect(dialog()).toHaveTextContent("Another account already uses this email.");
    answer = refuse(422, "wrong_password");
    await press("Send code");
    expect(dialog()).toHaveTextContent("That password isn't right.");
    expect(calls("email").at(-1)?.body).toEqual({ email: "taken@example.com", password: "secret" });
    answer = ok({ pending_email: "taken@example.com" }, 202);
    await press("Send code");
    type("Code from the email", "123456");
    await press("Confirm");
    expect(dialog()).toHaveTextContent("This email change has ended. Start again.");
    expect(dialog()).toHaveAccessibleName("Change email");
  });

  it("Change email while the last change can still be put back: said with its date, nothing else asked", async () => {
    serve({ email: refuse(409, "email_change_locked", { until: "2026-11-28T10:00:00Z" }) });
    profile(design);
    await act(flushAsync);
    await openRow("Change email");
    type("New email", "ayesha@new.pk");
    type("Your password", "secret");
    await press("Send code");
    expect(dialog()).toHaveTextContent("Your email was changed recently. You can change it again from 28 November 2026.");
    expect(dialog()).toHaveAccessibleName("Change email");
  });

  it("Check your new address notices a change confirmed by the link in a browser", async () => {
    vi.useFakeTimers({ toFake: ["setInterval", "clearInterval"] });
    respond("account_get", { ...ME, pending_email: "ayesha@new.pk" });
    profile(design, { ...ME, pending_email: "ayesha@new.pk" });
    await act(flushAsync);
    await openRow("Check your new address");
    expect(dialog()).toHaveAccessibleName("Check your new address");
    act(() => vi.advanceTimersByTime(EMAIL_POLL_MS));
    await act(flushAsync);
    expect(said.ok).toEqual([]);
    respond("account_get", { ...ME, email: "ayesha@new.pk", pending_email: null });
    act(() => vi.advanceTimersByTime(EMAIL_POLL_MS));
    await act(flushAsync);
    expect(said.ok).toEqual(["Email changed"]);
  });

  it("an older server: the name and email are changed on the website", async () => {
    const older = { ...ME } as Account;
    delete older.has_password;
    profile(design, older);
    await act(flushAsync);
    expect(screen.queryByRole("button", { name: "Change name" })).toBeNull();
    expect(screen.getByText("Your name and email are changed on the website.")).toBeInTheDocument();
  });
});

describe.each(["classic", "v2"] as const)("Sign-in & security, %s", (design) => {
  it("Change password: the rules are checked first, then the current password, the new one and Sign out other devices (on)", async () => {
    serve({ security: ok(SECURITY), password: ok(null, 204) });
    security(design);
    await act(flushAsync);
    await openRow("Change password");
    expect(dialog()).toHaveTextContent("At least 12 characters, with letters and numbers.");
    const others = within(dialog()).getByRole("checkbox", { name: "Sign out other devices and browsers" });
    expect(others).toBeChecked();
    type("Current password", "old-password-1");
    type("New password", "short1");
    await press("Change password");
    expect(dialog()).toHaveTextContent("Use at least 12 characters.");
    expect(calls("password")).toEqual([]);
    type("New password", "a much longer one 2026");
    fireEvent.click(others);
    await press("Change password");
    expect(calls("password")).toEqual([{ action: "password", id: null, body: { new: "a much longer one 2026", sign_out_others: false, current: "old-password-1" } }]);
    expect(said.ok).toEqual(["Password changed"]);
  });

  it("Change password with two-step on sends the code with the current password; a wrong one and a lock are said", async () => {
    let answer = refuse(422, "wrong_password");
    serve({ security: ok({ ...SECURITY, two_factor: { enabled: true, recovery_codes_left: 8 } }), password: () => answer });
    security(design, { ...ME, two_factor: true });
    await act(flushAsync);
    await openRow("Change password");
    type("Current password", "old-password-1");
    type("New password", "a much longer one 2026");
    expect(within(dialog()).getByRole("button", { name: "Change password" })).toBeDisabled();
    type("Two-step code", "123456");
    await press("Change password");
    expect(calls("password")[0].body).toMatchObject({ current: "old-password-1", otp: "123456", sign_out_others: true });
    expect(dialog()).toHaveTextContent("That password isn't right.");
    answer = refuse(429, "too_many_attempts", { retryAfter: 900 });
    await press("Change password");
    expect(dialog()).toHaveTextContent("Too many tries. Wait 15 minutes, then try again.");
    answer = refuse(422, "validation_failed", { errorCodes: { new: [{ code: "same_as_current" }] } });
    await press("Change password");
    expect(dialog()).toHaveTextContent("Choose a password you don't use now.");
  });

  it("an account made with Google sets a first password with a code sent by email", async () => {
    serve({ security: ok({ ...SECURITY, password: false, google: { linked: true, email: null } }), proof_code: ok(null, 202), password: ok(null, 204) });
    security(design, { ...ME, has_password: false, google: true });
    await act(flushAsync);
    await openRow("Set a password");
    await press("Send me a code");
    expect(calls("proof_code")).toHaveLength(1);
    expect(dialog()).toHaveTextContent("We sent a 6-digit code to ayesha@qureshitextiles.pk. It works for 10 minutes.");
    type("Code from your email", "111 222");
    type("New password", "a much longer one 2026");
    await press("Set password");
    expect(calls("password")[0].body).toEqual({ new: "a much longer one 2026", sign_out_others: true, email_code: "111222" });
    expect(said.ok).toEqual(["Password set"]);
  });

  it("Turn on two-step sign-in: proof, the QR code and the key, a code, then the 8 recovery codes once with Copy and Save", async () => {
    const writeText = vi.fn(() => Promise.resolve());
    Object.assign(navigator, { clipboard: { writeText } });
    respond("account_recovery_codes_save", "/Users/ayesha/Downloads/EchoLedger recovery codes.txt");
    serve({
      security: ok(SECURITY),
      two_factor_begin: ok({ ceremony: "c1", secret: "JBSWY3DPEHPK3PXP", otpauth_uri: "otpauth://totp/EchoLedger:a?secret=JBSWY3DPEHPK3PXP" }, 200, { qr: ["1110", "1010", "1110", "0001"] }),
      two_factor_confirm: (c) => (c.body?.code === "123456" ? ok({ recovery_codes: CODES }) : refuse(422, "invalid_otp")),
    });
    security(design);
    await act(flushAsync);
    await openRow("Turn on two-step sign-in");
    type("Your password", "secret");
    await press("Continue");
    expect(calls("two_factor_begin")).toEqual([{ action: "two_factor_begin", id: null, body: { password: "secret" } }]);
    expect(within(dialog()).getByRole("img", { name: "QR code for your authenticator app" })).toBeInTheDocument();
    expect(screen.getByTestId("two-step-key")).toHaveTextContent("JBSW Y3DP EHPK 3PXP");
    type("Code from the app", "000000");
    await press("Turn on");
    expect(dialog()).toHaveTextContent("That code didn't work.");
    type("Code from the app", "123456");
    await press("Turn on");
    expect(calls("two_factor_confirm").at(-1)?.body).toEqual({ ceremony: "c1", code: "123456" });
    const list = within(dialog()).getByRole("list", { name: "Recovery codes" });
    expect(within(list).getAllByRole("listitem").map((i) => i.textContent)).toEqual(CODES);
    await press("Copy codes");
    expect(writeText).toHaveBeenCalledWith(CODES.join("\n"));
    await press("Save as a file…");
    expect(callsOf("account_recovery_codes_save")).toEqual([{ codes: CODES, email: ME.email }]);
    expect(dialog()).toHaveTextContent("Saved to /Users/ayesha/Downloads/EchoLedger recovery codes.txt");
    await press("I've saved them");
    expect(said.ok).toEqual(["Two-step sign-in is on"]);
    expect(screen.queryByRole("list", { name: "Recovery codes" })).toBeNull();
  });

  it("Turn on two-step: a proof the account can't give is swapped for the one the server accepts", async () => {
    serve({ security: ok(SECURITY), two_factor_begin: refuse(422, "proof_required", { accepts: ["email_code"] }), proof_code: ok(null, 202) });
    security(design);
    await act(flushAsync);
    await openRow("Turn on two-step sign-in");
    type("Your password", "secret");
    await press("Continue");
    expect(within(dialog()).getByLabelText("Code from your email")).toBeInTheDocument();
    expect(within(dialog()).queryByLabelText("Your password")).toBeNull();
  });

  it("two-step on: New recovery codes and Turn off take a two-step code, never the password", async () => {
    serve({
      security: ok({ ...SECURITY, two_factor: { enabled: true, recovery_codes_left: 3 } }),
      recovery_codes: ok({ recovery_codes: CODES }),
      two_factor_off: refuse(409, "required_for_staff"),
    });
    security(design, { ...ME, two_factor: true });
    await act(flushAsync);
    expect(screen.getByText(/3 left/)).toBeInTheDocument();
    await openRow("New recovery codes");
    type("Two-step code", "123 456");
    await press("Make new codes");
    expect(calls("recovery_codes")[0].body).toEqual({ otp: "123456" });
    await press("I've saved them");
    expect(said.ok).toEqual(["New recovery codes made"]);
    await openRow("Turn off two-step sign-in");
    expect(within(dialog()).queryByLabelText("Your password")).toBeNull();
    type("Two-step code", "654321");
    await press("Turn off");
    expect(calls("two_factor_off")[0].body).toEqual({ otp: "654321" });
    expect(dialog()).toHaveTextContent("Staff accounts must keep two-step sign-in on.");
  });

  it("Unlink Google: proof, and the last way to sign in is kept", async () => {
    serve({ security: ok({ ...SECURITY, google: { linked: true, email: null } }), google_unlink: refuse(409, "last_sign_in_method") });
    security(design, { ...ME, google: true });
    await act(flushAsync);
    await openRow("Unlink Google");
    type("Your password", "secret");
    await press("Unlink");
    expect(calls("google_unlink")[0].body).toEqual({ password: "secret" });
    expect(dialog()).toHaveTextContent("Keep at least one way to sign in: set a password or add a passkey first.");
  });

  it("passkeys: rename and remove here, add one on the website", async () => {
    serve({
      security: ok({ ...SECURITY, passkeys: [{ id: "pk1", name: "Ayesha's S25", created_at: null, last_used_at: null }] }),
      passkey_rename: ok({ id: "pk1", name: "Phone" }),
      passkey_remove: ok(null, 204),
    });
    security(design);
    await act(flushAsync);
    await openRow("Rename passkey Ayesha's S25");
    type("Passkey name", "Phone");
    await press("Save");
    expect(calls("passkey_rename")).toEqual([{ action: "passkey_rename", id: "pk1", body: { name: "Phone" } }]);
    await openRow("Remove passkey Ayesha's S25");
    type("Your password", "secret");
    await press("Remove");
    expect(calls("passkey_remove")).toEqual([{ action: "passkey_remove", id: "pk1", body: { password: "secret" } }]);
    expect(said.ok).toEqual(["Passkey renamed", "Passkey removed"]);
    await openRow("Add a passkey on the website");
    expect(callsOf("server_page_open")).toEqual([{ page: "app/settings/security/passkeys", serverUrl: null }]);
  });
});
