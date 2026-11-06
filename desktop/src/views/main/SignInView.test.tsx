import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { SignInView } from "./SignInView";
import { callsOf, emitEvent, fail, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeStatus } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const server = () => screen.getByLabelText(/Server address/) as HTMLInputElement;
const email = () => screen.getByLabelText(/^Email/) as HTMLInputElement;
const password = () => screen.getByLabelText(/^Password/) as HTMLInputElement;
const name = () => screen.getByLabelText(/Name for this computer/) as HTMLInputElement;
const signIn = () => screen.getByRole("button", { name: /^(Sign in|Signing in…)$/ });
const browserButton = () => screen.getByRole("button", { name: "Sign in in the browser (Google, passkeys)" });

function setup(serverUrl: string | null = null) {
  const onSignedIn = vi.fn();
  const notify = vi.fn();
  render(<SignInView onSignedIn={onSignedIn} notify={notify} serverUrl={serverUrl} />);
  return { onSignedIn, notify };
}

function fillAccount(address = "https://ledger.example.test") {
  fireEvent.change(server(), { target: { value: address } });
  fireEvent.change(email(), { target: { value: " hassam@example.test " } });
  fireEvent.change(password(), { target: { value: " pass word " } });
}

describe("SignInView: email and password", () => {
  beforeEach(() => {
    resetTauriMock();
  });

  it("asks for the server, email and password, and starts the server field with the one used before", () => {
    setup("https://ledger.example.test");
    expect(screen.getByRole("heading", { name: "Sign in to EchoLedger" })).toBeInTheDocument();
    expect(server().value).toBe("https://ledger.example.test");
    expect(email()).toHaveFocus();
    expect(password()).toHaveAttribute("type", "password");
    expect(screen.queryByLabelText(/Two-factor code/)).not.toBeInTheDocument();
    expect(screen.getByText(/stored in your OS keychain/)).toBeInTheDocument();
  });

  it("starts with https:// when this computer never signed in", () => {
    setup();
    expect(server().value).toBe("https://");
    expect(browserButton()).toBeDisabled();
  });

  it("signs in with trimmed fields (the password as typed) and reports it", async () => {
    const signedIn = makeStatus({ deviceName: "Studio Mac" });
    respond("sign_in_password", { result: "signed_in", status: signedIn });
    const { onSignedIn, notify } = setup();
    fillAccount("  https://ledger.example.test/  ");
    fireEvent.change(name(), { target: { value: " Studio Mac " } });
    fireEvent.click(signIn());

    await waitFor(() => expect(onSignedIn).toHaveBeenCalledWith(signedIn));
    expect(callsOf("sign_in_password")).toEqual([
      { serverUrl: "https://ledger.example.test/", email: "hassam@example.test", password: " pass word ", otp: null, deviceName: "Studio Mac" },
    ]);
    expect(notify).toHaveBeenCalledWith("Signed in. Your library will sync now.");
  });

  it("asks for the two-factor code when the account has one, then sends it", async () => {
    respondWith("sign_in_password", ({ otp }) => (otp ? { result: "signed_in", status: makeStatus() } : { result: "otp_required", status: null }));
    const { onSignedIn } = setup();
    fillAccount();
    fireEvent.click(signIn());

    const code = (await screen.findByLabelText(/Two-factor code/)) as HTMLInputElement;
    expect(code).toHaveFocus();
    expect(screen.getByText(/authenticator app shows, or a recovery code/)).toBeInTheDocument();
    expect(onSignedIn).not.toHaveBeenCalled();
    fireEvent.change(code, { target: { value: " 123 456 " } });
    fireEvent.click(signIn());

    await waitFor(() => expect(onSignedIn).toHaveBeenCalled());
    expect(callsOf("sign_in_password")[1].otp).toBe("123 456");
    expect(callsOf("sign_in_password")[1].password).toBe(" pass word ");
  });

  it.each([
    ["a wrong email or password", "The email or password is not right. Check both and try again."],
    ["a wrong two-factor code", "That code did not work. Type the 6-digit code your authenticator app shows now, or one of your recovery codes."],
    ["an address not verified yet", "Your email address is not verified yet. Open the link in the email EchoLedger sent you, then sign in again."],
    ["a locked sign-in", "Too many sign-in attempts. Wait 14 minutes, then try again."],
  ])("shows %s in plain words beside the form and keeps what was typed", async (_what, text) => {
    fail("sign_in_password", text);
    const { onSignedIn } = setup();
    fillAccount();
    fireEvent.click(signIn());

    expect(await screen.findByRole("alert")).toHaveTextContent(text);
    expect(onSignedIn).not.toHaveBeenCalled();
    expect(email().value).toBe("hassam@example.test");
    expect(password().value).toBe(" pass word ");
    expect(signIn()).toBeEnabled();
  });

  it("does not send the form without an email and a password", () => {
    setup();
    fireEvent.change(server(), { target: { value: "https://ledger.example.test" } });
    fireEvent.click(signIn());
    expect(callsOf("sign_in_password")).toHaveLength(0);
  });

  it("shows progress while signing in", async () => {
    let finish: (v: unknown) => void = () => undefined;
    respondWith("sign_in_password", () => new Promise((resolve) => (finish = resolve)));
    setup();
    fillAccount();
    fireEvent.click(signIn());
    await waitFor(() => expect(signIn()).toHaveTextContent("Signing in…"));
    expect(signIn()).toBeDisabled();
    expect(browserButton()).toBeDisabled();
    finish({ result: "signed_in", status: makeStatus() });
    await waitFor(() => expect(signIn()).toHaveTextContent(/^Sign in$/));
  });

  it("moves to the pairing code when the server has no account sign-in", async () => {
    respond("sign_in_password", { result: "pairing_only", status: null });
    setup();
    fillAccount();
    fireEvent.click(signIn());

    expect(await screen.findByRole("heading", { name: "Pair this computer" })).toBeInTheDocument();
    expect(screen.getByRole("status")).toHaveTextContent("This server does not sign in with accounts yet.");
    expect(screen.queryByRole("button", { name: "Sign in with an account instead" })).not.toBeInTheDocument();
  });

  it("offers the pairing code or QR for older servers, and the way back", async () => {
    respond("pair_device", makeStatus());
    const { onSignedIn } = setup();
    fireEvent.click(screen.getByRole("button", { name: "Use a pairing code or QR instead" }));
    expect(screen.getByRole("heading", { name: "Pair this computer" })).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText(/Server address/), { target: { value: "https://ledger.example.test" } });
    fireEvent.change(screen.getByLabelText(/Pairing code/), { target: { value: "ABCD-1234" } });
    fireEvent.click(screen.getByRole("button", { name: "Pair" }));
    await waitFor(() => expect(onSignedIn).toHaveBeenCalled());

    fireEvent.click(screen.getByRole("button", { name: "Sign in with an account instead" }));
    expect(screen.getByRole("heading", { name: "Sign in to EchoLedger" })).toBeInTheDocument();
  });
});

describe("SignInView: in the browser (Google, passkeys)", () => {
  beforeEach(() => {
    resetTauriMock();
    respond("sign_in_browser", true);
    respond("sign_in_browser_cancel", undefined);
  });

  it("opens the browser for the server and this computer's name, then waits", async () => {
    setup();
    fireEvent.change(server(), { target: { value: " https://ledger.example.test " } });
    fireEvent.change(name(), { target: { value: " Studio Mac " } });
    fireEvent.click(browserButton());

    expect(await screen.findByText(/Finish signing in in your browser/)).toBeInTheDocument();
    expect(callsOf("sign_in_browser")).toEqual([{ serverUrl: "https://ledger.example.test", deviceName: "Studio Mac" }]);
    expect(server()).toBeDisabled();
    expect(screen.queryByLabelText(/^Password/)).not.toBeInTheDocument();
  });

  it("enters the app when the browser sends it back signed in", async () => {
    const { onSignedIn, notify } = setup();
    fireEvent.change(server(), { target: { value: "https://ledger.example.test" } });
    fireEvent.click(browserButton());
    await screen.findByText(/Finish signing in in your browser/);

    const status = makeStatus({ deviceName: "Studio Mac" });
    await emitEvent("signin:done", status);
    expect(onSignedIn).toHaveBeenCalledWith(status);
    expect(notify).toHaveBeenCalledWith("Signed in. Your library will sync now.");
  });

  it("says why when the sign-in link could not be used, and lets the person start again", async () => {
    setup();
    fireEvent.change(server(), { target: { value: "https://ledger.example.test" } });
    fireEvent.click(browserButton());
    await screen.findByText(/Finish signing in in your browser/);

    const text = "The browser sign-in did not finish: its code was wrong, already used or expired. Choose “Sign in in the browser” to start again.";
    await emitEvent("signin:failed", text);
    expect(screen.getByRole("alert")).toHaveTextContent(text);
    expect(browserButton()).toBeEnabled();
    expect(screen.queryByText(/Finish signing in in your browser/)).not.toBeInTheDocument();
  });

  it("stops waiting on Cancel", async () => {
    setup();
    fireEvent.change(server(), { target: { value: "https://ledger.example.test" } });
    fireEvent.click(browserButton());
    fireEvent.click(await screen.findByRole("button", { name: "Cancel" }));
    expect(callsOf("sign_in_browser_cancel")).toHaveLength(1);
    expect(browserButton()).toBeInTheDocument();
  });

  it("moves to the pairing code when the server has no browser sign-in", async () => {
    respond("sign_in_browser", false);
    setup();
    fireEvent.change(server(), { target: { value: "https://old.example.test" } });
    fireEvent.click(browserButton());
    expect(await screen.findByRole("heading", { name: "Pair this computer" })).toBeInTheDocument();
    expect(screen.getByRole("status")).toHaveTextContent("Pair this computer with a code from the console instead.");
  });

  it("shows a server that cannot be reached", async () => {
    fail("sign_in_browser", "Network error: error sending request for url (https://nowhere.example.test/api/auth/device/token)");
    setup();
    fireEvent.change(server(), { target: { value: "https://nowhere.example.test" } });
    fireEvent.click(browserButton());
    expect(await screen.findByRole("alert")).toHaveTextContent("Network error");
  });
});
