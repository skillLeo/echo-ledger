import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { ConsentNotice } from "./ConsentNotice";
import { CONSENT_NOTICE, CONSENT_SWITCH, CONSENT_TICK } from "../../lib/consent";
import { callsOf, flushAsync, resetTauriMock, respond } from "../../test/tauri";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

describe("the recording-consent notice", () => {
  beforeEach(() => resetTauriMock());

  it("uses the phone's words (OnboardingScreen.kt and SettingsScreens.kt)", () => {
    expect(CONSENT_NOTICE).toBe("Recording laws differ by country. Tell the people in your meeting that you are recording where the law requires it.");
    expect(CONSENT_TICK).toBe("I understand and will get consent where required");
    expect(CONSENT_SWITCH).toBe("I understand");
    render(<ConsentNotice legal={false} />);
    expect(screen.getByTestId("consent-notice")).toHaveTextContent(`${CONSENT_NOTICE} A red indicator is always shown while EchoLedger records.`);
    expect(screen.queryByRole("button", { name: "Terms" })).not.toBeInTheDocument();
  });

  it("opens the server's Terms and Privacy pages when the server has them", async () => {
    respond("server_page_open", undefined);
    render(<ConsentNotice legal />);
    fireEvent.click(screen.getByRole("button", { name: "Terms" }));
    fireEvent.click(screen.getByRole("button", { name: "Privacy" }));
    await flushAsync();
    expect(callsOf("server_page_open")).toEqual([{ page: "terms", serverUrl: null }, { page: "privacy", serverUrl: null }]);
  });
});
