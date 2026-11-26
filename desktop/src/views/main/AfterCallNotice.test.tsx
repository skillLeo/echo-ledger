import { NO_TARGET } from "../../lib/notice";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { AfterCallNotice } from "./AfterCallNotice";
import { callsOf, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { resetClosedBanners } from "../../components/StateBanner";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const TEXT = "After each call now asks before transcribing. To transcribe every call as before, choose Transcribe every call";

beforeEach(() => {
  resetTauriMock();
  resetClosedBanners();
  respond("after_call_notice_answer", null);
});

describe("After a call notice", () => {
  it("says what changed once, with Transcribe every call one tap away", async () => {
    const notify = vi.fn();
    render(<AfterCallNotice text={TEXT} notify={notify} />);
    expect(screen.getByTestId("after-call-notice")).toHaveTextContent(TEXT);
    fireEvent.click(screen.getByRole("button", { name: "Transcribe every call" }));
    await flushAsync();
    expect(callsOf("after_call_notice_answer")).toEqual([{ alwaysTranscribe: true }]);
    expect(notify).toHaveBeenCalledWith("Every call is now transcribed", false, NO_TARGET);
  });

  it("closing it keeps Ask me and is the answer too", async () => {
    render(<AfterCallNotice text={TEXT} notify={vi.fn()} />);
    fireEvent.click(screen.getByRole("button", { name: /close|dismiss/i }));
    await flushAsync();
    expect(callsOf("after_call_notice_answer")).toEqual([{ alwaysTranscribe: false }]);
  });

  it("shows nothing without the core's notice", () => {
    const { container } = render(<AfterCallNotice text={null} notify={vi.fn()} />);
    expect(container).toBeEmptyDOMElement();
  });
});
