import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { SignInView } from "./SignInView";
import { callsOf, emitEvent, fail, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeStatus, anyTarget } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

/** A built-in server as a build would have it (never the production address in the sources). */
const BUILT_IN = "https://ledger.example.com";
const OPTIONS = {
  password: true, google: true, signUp: true, signUpUrl: "https://ledger.example.com/admin/register",
  resetUrl: "https://ledger.example.com/admin/password-reset/request", browserSignIn: true, code: true,
};

const email = () => screen.getByLabelText(/^Email/) as HTMLInputElement;
const button = (name: string | RegExp) => screen.getByRole("button", { name });

function setup({ builtIn = BUILT_IN, serverUrl = null as string | null } = {}) {
  const onSignedIn = vi.fn();
  const notify = vi.fn();
  render(<SignInView onSignedIn={onSignedIn} notify={notify} serverUrl={serverUrl} defaultServer={builtIn} />);
  return { onSignedIn, notify };
}

async function continueWith(address: string) {
  fireEvent.change(email(), { target: { value: ` ${address} ` } });
  fireEvent.click(button("Continue"));
}

describe("SignInView: one flow, the email first (v1.6)", () => {
  beforeEach(() => {
    resetTauriMock();
    respond("auth_options", OPTIONS);
    respond("server_page_open", undefined);
    respond("sign_in_browser", true);
    respond("sign_in_browser_cancel", undefined);
  });

  it("asks only for the email: the built-in server is not a field, it is under Advanced", async () => {
    setup();
    expect(screen.getByRole("heading", { name: "Sign in to EchoLedger" })).toBeInTheDocument();
    expect(email()).toHaveFocus();
    expect(screen.queryByLabelText(/Server address/)).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/Use a different server/)).not.toBeInTheDocument();
    expect(button("Continue")).toBeDisabled();
    fireEvent.click(button("Advanced"));
    expect((screen.getByLabelText(/Use a different server/) as HTMLInputElement).value).toBe(BUILT_IN);
    expect(screen.getByLabelText(/Name for this computer/)).toBeInTheDocument();
    expect(await screen.findByRole("button", { name: "Continue with Google" })).toBeInTheDocument();
    expect(button("Sign in with QR / code")).toBeInTheDocument();
  });

  it("speaks plainly: the data is encrypted, no tokens or keychains", () => {
    setup();
    expect(screen.getByText("Your data is encrypted on this computer.")).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/token|keychain/i);
  });

  it("asks for the server address as before when the build has none built in", () => {
    setup({ builtIn: "" });
    const server = screen.getByLabelText(/Server address/) as HTMLInputElement;
    expect(server.value).toBe("https://");
    fireEvent.change(email(), { target: { value: "sam@example.test" } });
    expect(button("Continue")).toBeDisabled();
    fireEvent.change(server, { target: { value: "https://ledger.example.test" } });
    expect(button("Continue")).toBeEnabled();
  });

  it("shows the other server a signed-out computer used, under Advanced, and can go back to the built-in one", () => {
    setup({ serverUrl: "https://other.example.test" });
    const server = screen.getByLabelText(/Use a different server/) as HTMLInputElement;
    expect(server.value).toBe("https://other.example.test");
    fireEvent.click(button("Use ledger.example.com again"));
    expect(server.value).toBe(BUILT_IN);
  });

  it("signs a new email up: code, name, Terms, then the account on the Free plan", async () => {
    respond("auth_start", { account: "new", methods: ["code", "google"], signUp: true });
    respond("auth_code_send", { resendAfter: 60, expiresIn: 600, sent: true });
    respond("auth_code_verify", { result: "profile", status: null });
    const signedIn = makeStatus({ deviceName: "MacBook Pro" });
    respond("auth_sign_up", signedIn);
    const { onSignedIn, notify } = setup();

    await continueWith("New@Example.test");
    expect(await screen.findByRole("heading", { name: "Check your email" })).toBeInTheDocument();
    expect(callsOf("auth_start")).toEqual([{ serverUrl: BUILT_IN, email: "New@Example.test" }]);
    expect(callsOf("auth_code_send")).toEqual([{ serverUrl: BUILT_IN, email: "New@Example.test", purpose: "sign_up" }]);
    expect(screen.getByText(/We sent a 6-digit code to/)).toHaveTextContent("New@Example.test");
    expect(button(/^Send a new code in (60|59) s$/)).toBeDisabled();
    expect(screen.queryByRole("button", { name: "Continue with Google" })).not.toBeInTheDocument();

    const code = screen.getByLabelText(/Code from the email/) as HTMLInputElement;
    expect(code).toHaveFocus();
    expect(code).toHaveAttribute("autocomplete", "one-time-code");
    expect(button("Continue")).toBeDisabled();
    fireEvent.change(code, { target: { value: "123 456" } });
    fireEvent.click(button("Continue"));

    expect(await screen.findByRole("heading", { name: "Create your account" })).toBeInTheDocument();
    expect(callsOf("auth_code_verify")).toEqual([{ serverUrl: BUILT_IN, email: "New@Example.test", code: "123 456", otp: null, deviceName: "" }]);
    const create = button("Create account");
    expect(create).toBeDisabled();
    fireEvent.change(screen.getByLabelText(/Your name/), { target: { value: " Sam Khan " } });
    expect(create).toBeDisabled();
    fireEvent.click(button("Terms"));
    fireEvent.click(button("Privacy"));
    expect(callsOf("server_page_open")).toEqual([{ page: "terms", serverUrl: BUILT_IN }, { page: "privacy", serverUrl: BUILT_IN }]);
    fireEvent.click(screen.getByRole("checkbox", { name: "I accept the Terms and Privacy" }));
    fireEvent.click(create);

    await waitFor(() => expect(onSignedIn).toHaveBeenCalledWith(signedIn));
    expect(callsOf("auth_sign_up")).toEqual([{ name: "Sam Khan", acceptTerms: true }]);
    expect(notify).toHaveBeenCalledWith("Welcome to EchoLedger. Your account is ready.", false, anyTarget);
  });

  it("goes back to the email when a sign-up waited too long", async () => {
    respond("auth_start", { account: "new", methods: ["code"], signUp: true });
    respond("auth_code_send", { resendAfter: 60, expiresIn: 600, sent: true });
    respond("auth_code_verify", { result: "profile", status: null });
    fail("auth_sign_up", "This sign-up took too long. Start again with your email: a new code is sent.");
    setup();
    await continueWith("new@example.test");
    fireEvent.change(await screen.findByLabelText(/Code from the email/), { target: { value: "123456" } });
    fireEvent.click(button("Continue"));
    fireEvent.change(await screen.findByLabelText(/Your name/), { target: { value: "Sam" } });
    fireEvent.click(screen.getByRole("checkbox", { name: "I accept the Terms and Privacy" }));
    fireEvent.click(button("Create account"));
    expect(await screen.findByRole("alert")).toHaveTextContent("This sign-up took too long.");
    expect(screen.getByRole("heading", { name: "Sign in to EchoLedger" })).toBeInTheDocument();
    expect(email().value).toBe("new@example.test");
  });

  it("signs an existing account in with its password, then the two-factor code", async () => {
    respond("auth_start", { account: "existing", methods: ["password", "code", "google"], signUp: true });
    respondWith("sign_in_password", ({ otp }) => (otp ? { result: "signed_in", status: makeStatus() } : { result: "otp_required", status: null }));
    const { onSignedIn, notify } = setup();
    await continueWith("sam@example.test");

    expect(await screen.findByRole("heading", { name: "Welcome back" })).toBeInTheDocument();
    expect(screen.getByText("sam@example.test")).toBeInTheDocument();
    const password = screen.getByLabelText(/^Password/) as HTMLInputElement;
    expect(password).toHaveFocus();
    expect(await screen.findByRole("button", { name: "Forgot password?" })).toBeInTheDocument();
    expect(button("Continue with Google")).toBeInTheDocument();
    fireEvent.change(password, { target: { value: " pass word " } });
    fireEvent.click(button("Sign in"));

    const otp = (await screen.findByLabelText(/Two-factor code/)) as HTMLInputElement;
    expect(screen.getByRole("heading", { name: "Two-factor sign-in" })).toBeInTheDocument();
    expect(otp).toHaveFocus();
    fireEvent.change(otp, { target: { value: " 123 456 " } });
    fireEvent.click(button("Sign in"));
    await waitFor(() => expect(onSignedIn).toHaveBeenCalled());
    expect(callsOf("sign_in_password")).toEqual([
      { serverUrl: BUILT_IN, email: "sam@example.test", password: " pass word ", otp: null, deviceName: "" },
      { serverUrl: BUILT_IN, email: "sam@example.test", password: " pass word ", otp: "123 456", deviceName: "" },
    ]);
    expect(notify).toHaveBeenCalledWith("Signed in. Your library will sync now.", false, anyTarget);
  });

  it("emails an existing account a code instead of the password, with the two-factor code after it", async () => {
    respond("auth_start", { account: "existing", methods: ["password", "code"], signUp: true });
    respond("auth_code_send", { resendAfter: 60, expiresIn: 600, sent: true });
    respondWith("auth_code_verify", ({ otp }) => (otp ? { result: "signed_in", status: makeStatus() } : { result: "otp_required", status: null }));
    const { onSignedIn } = setup();
    await continueWith("sam@example.test");
    fireEvent.click(await screen.findByRole("button", { name: "Email me a code instead" }));

    expect(await screen.findByRole("heading", { name: "Check your email" })).toBeInTheDocument();
    expect(callsOf("auth_code_send")).toEqual([{ serverUrl: BUILT_IN, email: "sam@example.test", purpose: "sign_in" }]);
    fireEvent.change(screen.getByLabelText(/Code from the email/), { target: { value: "654321" } });
    fireEvent.click(button("Continue"));
    fireEvent.change(await screen.findByLabelText(/Two-factor code/), { target: { value: "111222" } });
    fireEvent.click(button("Sign in"));
    await waitFor(() => expect(onSignedIn).toHaveBeenCalled());
    expect(callsOf("auth_code_verify")[1]).toEqual({ serverUrl: BUILT_IN, email: "sam@example.test", code: "654321", otp: "111222", deviceName: "" });
  });

  it("sends a code straight away to an account without a password", async () => {
    respond("auth_start", { account: "existing", methods: ["code", "google"], signUp: true });
    respond("auth_code_send", { resendAfter: 60, expiresIn: 600, sent: true });
    setup();
    await continueWith("google-only@example.test");
    expect(await screen.findByRole("heading", { name: "Check your email" })).toBeInTheDocument();
    expect(callsOf("auth_code_send")[0].purpose).toBe("sign_in");
    expect(await screen.findByRole("button", { name: "Continue with Google" })).toBeInTheDocument();
  });

  it("keeps the code and says how many tries are left when it is wrong", async () => {
    respond("auth_start", { account: "existing", methods: ["code"], signUp: true });
    respond("auth_code_send", { resendAfter: 60, expiresIn: 600, sent: true });
    fail("auth_code_verify", "That code is not right. You can try 3 more times, or choose “Send a new code”.");
    setup();
    await continueWith("sam@example.test");
    const code = (await screen.findByLabelText(/Code from the email/)) as HTMLInputElement;
    fireEvent.change(code, { target: { value: "000000" } });
    fireEvent.click(button("Continue"));
    expect(await screen.findByRole("alert")).toHaveTextContent("You can try 3 more times");
    expect(code.value).toBe("000000");
    expect(button("Continue")).toBeEnabled();
  });

  it("says when a code went out a moment ago and counts down to the next one", async () => {
    respond("auth_start", { account: "new", methods: ["code"], signUp: true });
    respond("auth_code_send", { resendAfter: 41, expiresIn: null, sent: false });
    setup();
    await continueWith("new@example.test");
    expect(await screen.findByRole("alert")).toHaveTextContent("A code was sent a moment ago. Check your email, or ask for a new one in 41 s.");
    expect(button(/^Send a new code in (41|40) s$/)).toBeDisabled();
  });

  it("offers a new code once the wait is over, and sends it", async () => {
    respond("auth_start", { account: "existing", methods: ["code"], signUp: true });
    respond("auth_code_send", { resendAfter: 1, expiresIn: 600, sent: true });
    setup();
    await continueWith("sam@example.test");
    expect(await screen.findByRole("button", { name: "Send a new code in 1 s" })).toBeDisabled();
    const again = await screen.findByRole("button", { name: "Send a new code" }, { timeout: 3000 });
    expect(again).toBeEnabled();
    fireEvent.click(again);
    await waitFor(() => expect(callsOf("auth_code_send")).toHaveLength(2));
    expect(callsOf("auth_code_send")[1]).toEqual({ serverUrl: BUILT_IN, email: "sam@example.test", purpose: "sign_in" });
  });

  it.each([
    ["sign-up is closed", "New accounts are not open on this server. Ask its owner for an invitation."],
    ["the address is not allowed", "This email address can't be used for an account here. Use another address."],
  ])("shows %s beside the email and keeps it", async (_what, text) => {
    fail("auth_start", text);
    setup();
    await continueWith("someone@example.test");
    expect(await screen.findByRole("alert")).toHaveTextContent(text);
    expect(email().value).toBe("someone@example.test");
    expect(button("Continue")).toBeEnabled();
  });

  it("goes back to the email from any step", async () => {
    respond("auth_start", { account: "existing", methods: ["password"], signUp: true });
    setup();
    await continueWith("sam@example.test");
    fireEvent.click(await screen.findByRole("button", { name: "Use another email" }));
    expect(screen.getByRole("heading", { name: "Sign in to EchoLedger" })).toBeInTheDocument();
    expect(screen.queryByLabelText(/^Password/)).not.toBeInTheDocument();
  });
});

describe("SignInView: an older server (no email codes)", () => {
  beforeEach(() => {
    resetTauriMock();
    respond("auth_options", null);
    respond("auth_start", { account: "older", methods: ["password"], signUp: false });
    respond("sign_in_browser", true);
    respond("sign_in_browser_cancel", undefined);
  });

  it("shows the v1.5 sign-in: password and the browser for Google and passkeys", async () => {
    respond("sign_in_password", { result: "signed_in", status: makeStatus() });
    const { onSignedIn } = setup({ builtIn: "", serverUrl: "https://old.example.test" });
    await continueWith("sam@example.test");
    expect(await screen.findByLabelText(/^Password/)).toBeInTheDocument();
    expect(button("Sign in in the browser (Google, passkeys)")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Email me a code instead" })).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText(/^Password/), { target: { value: "pw" } });
    fireEvent.click(button("Sign in"));
    await waitFor(() => expect(onSignedIn).toHaveBeenCalled());
    expect(callsOf("sign_in_password")[0].serverUrl).toBe("https://old.example.test");
  });

  it("moves to the pairing code when the server has no account sign-in at all", async () => {
    respond("sign_in_password", { result: "pairing_only", status: null });
    setup({ builtIn: "", serverUrl: "https://old.example.test" });
    await continueWith("sam@example.test");
    fireEvent.change(await screen.findByLabelText(/^Password/), { target: { value: "pw" } });
    fireEvent.click(button("Sign in"));
    expect(await screen.findByRole("heading", { name: "Sign in with a code" })).toBeInTheDocument();
    expect(screen.getByRole("status")).toHaveTextContent("This server does not sign in with accounts yet.");
    expect((screen.getByLabelText(/Server address/) as HTMLInputElement).value).toBe("https://old.example.test");
  });
});

describe("SignInView: Google in the browser, and QR / code", () => {
  beforeEach(() => {
    resetTauriMock();
    respond("auth_options", OPTIONS);
    respond("sign_in_browser", true);
    respond("sign_in_browser_cancel", undefined);
  });

  it("opens the browser for the built-in server, waits, and enters the app when it comes back", async () => {
    const { onSignedIn, notify } = setup();
    fireEvent.click(button("Advanced"));
    fireEvent.change(screen.getByLabelText(/Name for this computer/), { target: { value: " Studio Mac " } });
    fireEvent.click(await screen.findByRole("button", { name: "Continue with Google" }));
    expect(await screen.findByText(/Finish signing in in your browser/)).toBeInTheDocument();
    expect(callsOf("sign_in_browser")).toEqual([{ serverUrl: BUILT_IN, deviceName: "Studio Mac" }]);

    const status = makeStatus({ deviceName: "Studio Mac" });
    await emitEvent("signin:done", status);
    expect(onSignedIn).toHaveBeenCalledWith(status);
    expect(notify).toHaveBeenCalledWith("Signed in. Your library will sync now.", false, anyTarget);
  });

  it("keeps waiting for an earlier sign-in's link, says why a link failed, and stops on Cancel", async () => {
    setup();
    fireEvent.click(await screen.findByRole("button", { name: "Continue with Google" }));
    await screen.findByText(/Finish signing in in your browser/);
    await emitEvent("signin:other", "This sign-in link belongs to another sign-in, so it was not used.");
    expect(screen.getByRole("alert")).toHaveTextContent("belongs to another sign-in");
    expect(screen.getByText(/Finish signing in in your browser/)).toBeInTheDocument();
    fireEvent.click(button("Cancel"));
    expect(callsOf("sign_in_browser_cancel")).toHaveLength(1);
    fireEvent.click(await screen.findByRole("button", { name: "Continue with Google" }));
    await emitEvent("signin:failed", "The browser sign-in did not finish.");
    expect(screen.getByRole("alert")).toHaveTextContent("The browser sign-in did not finish.");
  });

  it("offers QR / code as a small link with the built-in server, and the way back", async () => {
    respond("pair_device", makeStatus());
    const { onSignedIn } = setup();
    fireEvent.click(button("Sign in with QR / code"));
    expect(screen.getByRole("heading", { name: "Sign in with a code" })).toBeInTheDocument();
    expect(screen.queryByLabelText(/Server address/)).not.toBeInTheDocument();
    expect(screen.getByText(/Server: ledger.example.com/)).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText(/Sign-in code/), { target: { value: "ABCD-1234" } });
    fireEvent.click(button("Sign in with code"));
    await waitFor(() => expect(onSignedIn).toHaveBeenCalled());
    expect(callsOf("pair_device")[0]).toEqual({ serverUrl: BUILT_IN, code: "ABCD-1234", deviceName: "" });
    expect(document.body.textContent).not.toMatch(/token|keychain/i);

    fireEvent.click(button("Sign in with an account instead"));
    expect(screen.getByRole("heading", { name: "Sign in to EchoLedger" })).toBeInTheDocument();
  });

  it("asks the server what it offers once its address rests, and leaves out what it does not", async () => {
    respondWith("auth_options", ({ serverUrl }) => (serverUrl === "https://other.example.test" ? { ...OPTIONS, google: false } : OPTIONS));
    setup();
    expect(await screen.findByRole("button", { name: "Continue with Google" })).toBeInTheDocument();
    fireEvent.click(button("Advanced"));
    fireEvent.change(screen.getByLabelText(/Use a different server/), { target: { value: "https://other.example.test" } });
    expect(await screen.findByRole("button", { name: "Sign in in the browser (passkeys)" })).toBeInTheDocument();
    expect(callsOf("auth_options").map((c) => c.serverUrl)).toEqual([BUILT_IN, "https://other.example.test"]);
  });
});
