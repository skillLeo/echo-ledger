import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { anyTarget } from "../../test/fixtures";
import { ATTACH_NOTE, fileName, linkMessage, WHATSAPP_LINK_SECONDS, WhatsAppSheet } from "./WhatsAppSheet";
import { callsOf, fail, flushAsync, resetTauriMock, respond } from "../../test/tauri";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const writeText = vi.fn((_text: string) => Promise.resolve());
const TEXT = "Me: Welcome everyone\nClient: Thanks for having us";

beforeEach(() => {
  resetTauriMock();
  writeText.mockClear();
  Object.defineProperty(navigator, "clipboard", { configurable: true, value: { writeText } });
});
afterEach(async () => {
  await flushAsync();
  vi.restoreAllMocks();
});

function renderSheet(props: Partial<Parameters<typeof WhatsAppSheet>[0]> = {}) {
  const all = { sessionId: "s1", title: "Kick-off", hasAudio: true, text: TEXT, notify: vi.fn(), onClose: vi.fn(), ...props };
  render(<WhatsAppSheet {...all} />);
  return all;
}

describe("Send to WhatsApp on the computer (v1.6, P5.5)", () => {
  it("writes a link's message and a saved file's name", () => {
    expect(linkMessage("Kick-off", "https://x.test/s/t")).toBe("Kick-off\nhttps://x.test/s/t");
    expect(linkMessage("  ", "https://x.test/s/t")).toBe("https://x.test/s/t");
    expect(linkMessage(null, "https://x.test/s/t")).toBe("https://x.test/s/t");
    expect(fileName("C:\\Users\\me\\Downloads\\Kick-off 2026-11-06.ogg")).toBe("Kick-off 2026-11-06.ogg");
    expect(fileName("/Users/me/Downloads/Call.m4a")).toBe("Call.m4a");
    expect(WHATSAPP_LINK_SECONDS).toBe(604_800);
  });

  it("offers the phone's three ways", () => {
    renderSheet();
    expect(screen.getByRole("dialog", { name: "Send to WhatsApp" })).toBeInTheDocument();
    for (const name of ["Text only", "Text and voice", "Link (works for 7 days)"]) expect(screen.getByRole("button", { name })).toBeEnabled();
  });

  it("Text only: copies the text and opens WhatsApp with it", async () => {
    respond("whatsapp_open", false);
    const props = renderSheet();
    fireEvent.click(screen.getByRole("button", { name: "Text only" }));
    await waitFor(() => expect(callsOf("whatsapp_open")).toEqual([{ text: TEXT }]));
    expect(writeText).toHaveBeenCalledWith(TEXT);
    await waitFor(() => expect(props.onClose).toHaveBeenCalled());
    expect(props.notify).toHaveBeenCalledWith("Opening WhatsApp with the text", false, anyTarget);
  });

  it("says the whole text is on the clipboard when the message had to be cut", async () => {
    respond("whatsapp_open", true);
    const props = renderSheet();
    fireEvent.click(screen.getByRole("button", { name: "Text only" }));
    await waitFor(() => expect(props.notify).toHaveBeenCalledWith("WhatsApp opens with the start of the text. The whole text is copied: paste it there.", false, anyTarget));
  });

  it("Text and voice: saves the audio in Downloads, opens WhatsApp with the text and says to attach the file", async () => {
    respond("save_audio_downloads", "C:\\Users\\me\\Downloads\\Kick-off 2026-11-06.ogg");
    respond("whatsapp_open", false);
    const props = renderSheet();
    fireEvent.click(screen.getByRole("button", { name: "Text and voice" }));
    expect(await screen.findByText(ATTACH_NOTE)).toBeInTheDocument();
    expect(callsOf("save_audio_downloads")).toEqual([{ id: "s1" }]);
    expect(callsOf("whatsapp_open")).toEqual([{ text: TEXT }]);
    expect(writeText).toHaveBeenCalledWith(TEXT);
    expect(screen.getByText("Kick-off 2026-11-06.ogg")).toBeInTheDocument();
    expect(props.onClose).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole("button", { name: "Done" }));
    expect(props.onClose).toHaveBeenCalled();
  });

  it("Link: makes a 7-day link as the phone does and sends the title with it", async () => {
    respond("share_create", { id: "sh9", session_id: "s1", url: "https://ledger.example.test/s/tok" });
    respond("whatsapp_open", false);
    const props = renderSheet();
    fireEvent.click(screen.getByRole("button", { name: "Link (works for 7 days)" }));
    await waitFor(() => expect(callsOf("whatsapp_open")).toEqual([{ text: "Kick-off\nhttps://ledger.example.test/s/tok" }]));
    expect(callsOf("share_create")).toEqual([{ sessionId: "s1", expiresIn: 604_800, password: null, includeAudio: true }]);
    expect(writeText).toHaveBeenCalledWith("https://ledger.example.test/s/tok");
    expect(props.notify).toHaveBeenCalledWith("Link made (it works for 7 days) and copied", false, { kind: "shared", tab: "links" });
    expect(props.onClose).toHaveBeenCalled();
  });

  it("sends only a link while there is no transcript, and no voice without audio", () => {
    renderSheet({ text: null, hasAudio: false });
    expect(screen.getByRole("button", { name: "Text only" })).toBeDisabled();
    expect(screen.queryByRole("button", { name: "Text and voice" })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Link (works for 7 days)" })).toBeEnabled();
    expect(screen.getByTestId("whatsapp-no-text")).toHaveTextContent("only the link can be sent");
  });

  it("says why when the audio cannot be saved, and stays open", async () => {
    fail("save_audio_downloads", "The audio of this recording is not on the server, so there is nothing to save yet.");
    const props = renderSheet();
    fireEvent.click(screen.getByRole("button", { name: "Text and voice" }));
    await waitFor(() => expect(props.notify).toHaveBeenCalledWith("The audio of this recording is not on the server, so there is nothing to save yet.", true));
    expect(callsOf("whatsapp_open")).toHaveLength(0);
    expect(props.onClose).not.toHaveBeenCalled();
    expect(screen.getByRole("button", { name: "Text and voice" })).toBeEnabled();
  });
});
