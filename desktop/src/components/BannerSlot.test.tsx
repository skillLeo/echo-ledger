import { describe, expect, it } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { BANNER_ORDER, BannerSlot, bannerOnTop } from "./BannerSlot";
import { StateBanner } from "./StateBanner";

describe("one banner at a time above the pages", () => {
  it("shows the most important banner that wants the place", () => {
    expect(bannerOnTop(new Set())).toBeNull();
    expect(bannerOnTop(new Set(["budget", "permission-loss"]))).toBe("permission-loss");
    expect(bannerOnTop(new Set(["announcement", "one-sided", "update-ready"]))).toBe("one-sided");
    expect(BANNER_ORDER[0]).toBe("main-in-share");
  });

  it("shows the next one once the first is dismissed, and only then", () => {
    render(
      <BannerSlot>
        <StateBanner id="budget" slot="budget" state="warn" testId="budget">You have used 80% of this month's minutes.</StateBanner>
        <StateBanner id="update-ready" slot="update-ready" state="1.9.0" testId="update">Update ready</StateBanner>
        <StateBanner id="app-update" slot="app-update" state="1.9.0|true" testId="app-update">Please update EchoLedger.</StateBanner>
      </BannerSlot>,
    );
    expect(screen.getByTestId("app-update")).toBeInTheDocument();
    expect(screen.queryByTestId("budget")).not.toBeInTheDocument();
    expect(screen.queryByTestId("update")).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Dismiss" }));
    expect(screen.getByTestId("budget")).toBeInTheDocument();
    expect(screen.queryByTestId("update")).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Dismiss" }));
    expect(screen.getByTestId("update")).toBeInTheDocument();
    expect(document.querySelectorAll(".banner")).toHaveLength(1);
  });

  it("leaves a banner outside the slot alone (a page's own)", () => {
    render(<StateBanner id="page-own" slot="budget" state="x" testId="own">On its page</StateBanner>);
    expect(screen.getByTestId("own")).toBeInTheDocument();
  });
});
