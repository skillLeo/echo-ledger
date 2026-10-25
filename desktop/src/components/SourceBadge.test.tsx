import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { SourceAppBadge, SourceDeviceBadge } from "./SourceBadge";

describe("source badges", () => {
  it("show an icon and the device label (never icon only)", () => {
    render(<SourceDeviceBadge source="phone" />);
    const badge = screen.getByTestId("source-device");
    expect(badge).toHaveTextContent("Phone");
    expect(badge.querySelector("svg")).not.toBeNull();
  });

  it("fall back to Web for an unknown device and Other for an unknown app", () => {
    render(<SourceDeviceBadge />);
    expect(screen.getByTestId("source-device")).toHaveTextContent("Web");
    render(<SourceAppBadge />);
    expect(screen.getByTestId("source-app")).toHaveTextContent("Other");
  });

  it("render the app label and honour a server-provided override", () => {
    const { rerender } = render(<SourceAppBadge app="whatsapp_business" />);
    expect(screen.getByTestId("source-app")).toHaveTextContent("WhatsApp Business");
    rerender(<SourceAppBadge app="whatsapp" label="WhatsApp (work)" />);
    expect(screen.getByTestId("source-app")).toHaveTextContent("WhatsApp (work)");
  });
});
