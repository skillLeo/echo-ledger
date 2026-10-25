import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { PairingView } from "./PairingView";
import { callsOf, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeStatus } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const server = () => screen.getByLabelText(/Server address/) as HTMLInputElement;
const code = () => screen.getByLabelText(/Pairing code/) as HTMLInputElement;
const name = () => screen.getByLabelText(/Name for this computer/) as HTMLInputElement;
const submit = () => screen.getByRole("button", { name: /^(Pair|Pairing…)$/ });

function setup() {
  const onPaired = vi.fn();
  const notify = vi.fn();
  render(<PairingView onPaired={onPaired} notify={notify} />);
  return { onPaired, notify };
}

describe("PairingView", () => {
  beforeEach(() => {
    resetTauriMock();
  });

  it("explains the flow and starts with https:// in the server field and focus on the code", () => {
    setup();
    expect(screen.getByRole("heading", { name: "Pair this computer" })).toBeInTheDocument();
    expect(screen.getByText(/Devices → Pair a device/)).toBeInTheDocument();
    expect(server().value).toBe("https://");
    expect(code()).toHaveFocus();
    expect(screen.getByText(/stored in your OS keychain/)).toBeInTheDocument();
  });

  it("sends trimmed server, code and name to pair_device and reports success", async () => {
    const paired = makeStatus({ deviceName: "Studio PC" });
    respond("pair_device", paired);
    const { onPaired, notify } = setup();
    fireEvent.change(server(), { target: { value: "  https://ledger.example.test/  " } });
    fireEvent.change(code(), { target: { value: " ABCD-1234 " } });
    fireEvent.change(name(), { target: { value: " Studio PC " } });
    fireEvent.click(submit());

    await waitFor(() => expect(onPaired).toHaveBeenCalledWith(paired));
    expect(callsOf("pair_device")).toEqual([{ serverUrl: "https://ledger.example.test/", code: "ABCD-1234", deviceName: "Studio PC" }]);
    expect(notify).toHaveBeenCalledWith("Paired. Your library will sync now.");
  });

  it("sends an empty device name when the optional field is blank", async () => {
    respond("pair_device", makeStatus());
    setup();
    fireEvent.change(server(), { target: { value: "https://ledger.example.test" } });
    fireEvent.change(code(), { target: { value: "ABCD-1234" } });
    fireEvent.click(submit());
    await waitFor(() => expect(callsOf("pair_device")).toHaveLength(1));
    expect(callsOf("pair_device")[0].deviceName).toBe("");
  });

  it("shows the backend's error message, stays on the form and does not report a pairing", async () => {
    respondWith("pair_device", () => Promise.reject("Server returned 422: The pairing code is invalid or has expired."));
    const { onPaired, notify } = setup();
    fireEvent.change(server(), { target: { value: "https://ledger.example.test" } });
    fireEvent.change(code(), { target: { value: "WRONG" } });
    fireEvent.click(submit());

    await waitFor(() => expect(notify).toHaveBeenCalledWith("Server returned 422: The pairing code is invalid or has expired.", true));
    expect(onPaired).not.toHaveBeenCalled();
    expect(submit()).toBeEnabled();
    expect(submit()).toHaveTextContent("Pair");
    expect(code().value).toBe("WRONG");
  });

  it("reports a network failure the same way", async () => {
    respondWith("pair_device", () => Promise.reject("Network error: error sending request for url (https://nowhere.example.test/)"));
    const { notify } = setup();
    fireEvent.change(server(), { target: { value: "https://nowhere.example.test" } });
    fireEvent.change(code(), { target: { value: "CODE-0001" } });
    fireEvent.click(submit());
    await waitFor(() => expect(notify).toHaveBeenCalledWith(expect.stringContaining("Network error"), true));
  });

  it("disables the button and shows progress while pairing, then re-enables it", async () => {
    let finish: (v: unknown) => void = () => undefined;
    respondWith("pair_device", () => new Promise((resolve) => (finish = resolve)));
    setup();
    fireEvent.change(server(), { target: { value: "https://ledger.example.test" } });
    fireEvent.change(code(), { target: { value: "CODE-0001" } });
    fireEvent.click(submit());

    await waitFor(() => expect(submit()).toHaveTextContent("Pairing…"));
    expect(submit()).toBeDisabled();
    finish(makeStatus());
    await waitFor(() => expect(submit()).toHaveTextContent(/^Pair$/));
    expect(submit()).toBeEnabled();
  });

  it("does not submit while the required code is empty", () => {
    setup();
    fireEvent.change(server(), { target: { value: "https://ledger.example.test" } });
    fireEvent.click(submit());
    expect(callsOf("pair_device")).toHaveLength(0);
  });

  it("hides the server field and forwards the whole text when a QR payload is pasted", async () => {
    respond("pair_device", makeStatus());
    setup();
    const payload = '{"v":1,"url":"https://ledger.example.test","code":"ABCD-1234","pins":["sha256/AAAA","sha256/BBBB"]}';
    fireEvent.change(code(), { target: { value: `  ${payload}  ` } });
    expect(screen.queryByLabelText(/Server address/)).not.toBeInTheDocument();
    fireEvent.click(submit());
    await waitFor(() => expect(callsOf("pair_device")).toHaveLength(1));
    expect(callsOf("pair_device")[0].code).toBe(payload);
  });

  it("brings the server field back when the pasted payload is removed", () => {
    setup();
    fireEvent.change(code(), { target: { value: '{"url":"https://ledger.example.test"}' } });
    expect(screen.queryByLabelText(/Server address/)).not.toBeInTheDocument();
    fireEvent.change(code(), { target: { value: "" } });
    expect(server()).toBeInTheDocument();
  });
});
