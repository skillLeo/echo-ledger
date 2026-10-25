import { describe, expect, it } from "vitest";
import { render } from "@testing-library/react";
import { SourceAppIcon, SourceDeviceIcon } from "./Icons";

const markup = (element: React.ReactElement) => render(element).container.innerHTML;

describe("icons", () => {
  it("renders a decorative svg at the requested size", () => {
    const { container } = render(<SourceDeviceIcon source="phone" size={22} />);
    const svg = container.querySelector("svg")!;
    expect(svg).toHaveAttribute("aria-hidden", "true");
    expect(svg).toHaveAttribute("width", "22");
    expect(svg).toHaveAttribute("height", "22");
  });

  it("uses a different glyph for phone, desktop and web, and web for anything unknown", () => {
    const phone = markup(<SourceDeviceIcon source="phone" />);
    const desktop = markup(<SourceDeviceIcon source="desktop" />);
    const web = markup(<SourceDeviceIcon source="web" />);
    expect(new Set([phone, desktop, web]).size).toBe(3);
    expect(markup(<SourceDeviceIcon />)).toBe(web);
    expect(markup(<SourceDeviceIcon source="tablet" />)).toBe(web);
  });

  it("maps each call app to its own glyph, sharing one video glyph for Meet and Zoom", () => {
    const whatsapp = markup(<SourceAppIcon app="whatsapp" />);
    const business = markup(<SourceAppIcon app="whatsapp_business" />);
    const meet = markup(<SourceAppIcon app="google_meet" />);
    const zoom = markup(<SourceAppIcon app="zoom" />);
    const note = markup(<SourceAppIcon app="voice_note" />);
    const other = markup(<SourceAppIcon app="other" />);
    expect(meet).toBe(zoom);
    expect(new Set([whatsapp, business, meet, note, other]).size).toBe(5);
  });

  it("gives WhatsApp Web the web glyph and Microsoft Teams the video-call glyph", () => {
    const other = markup(<SourceAppIcon app="other" />);
    expect(markup(<SourceAppIcon app="microsoft_teams" />)).toBe(markup(<SourceAppIcon app="zoom" />));
    expect(markup(<SourceAppIcon app="whatsapp_web" />)).toBe(markup(<SourceDeviceIcon source="web" />));
    expect(markup(<SourceAppIcon app="whatsapp_web" />)).not.toBe(other);
  });

  it("falls back to the generic glyph for an unknown or missing app", () => {
    const other = markup(<SourceAppIcon app="other" />);
    expect(markup(<SourceAppIcon app="signal" />)).toBe(other);
    expect(markup(<SourceAppIcon />)).toBe(other);
  });
});
