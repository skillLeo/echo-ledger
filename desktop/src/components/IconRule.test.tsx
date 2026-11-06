// The one icon rule (docs/DESIGN_SYSTEM.md "Icons", since v1.5): every icon comes from Icons.tsx or ControlIcons.tsx,
// draws on the 24-unit grid with the one stroke and round ends, and uses the phone's metaphor for each concept.
import { describe, expect, it } from "vitest";
import { render } from "@testing-library/react";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";
import type { ComponentType } from "react";
import * as Icons from "./Icons";
import * as Controls from "./ControlIcons";

const src = join(dirname(fileURLToPath(import.meta.url)), "..");
const APPS = ["whatsapp", "whatsapp_business", "whatsapp_web", "google_meet", "zoom", "microsoft_teams", "telegram", "phone_call", "voice_note", "other"];
const SOURCES = ["phone", "desktop", "web"];
/** The pages and places that have a filled form for when they are active. */
const STATEFUL = ["LibraryIcon", "FolderIcon", "RecordIcon", "BellIcon", "SettingsIcon"];

type AnyIcon = ComponentType<Record<string, unknown>>;
const glyphs = Object.entries({ ...Icons, ...Controls }).filter(([name, value]) => name.endsWith("Icon") && typeof value === "function") as [string, AnyIcon][];

/** Every way each icon can be drawn: each app and source, each filled form, the skip arrows both ways. */
function drawings(name: string, Icon: AnyIcon): Element[] {
  const variants: Record<string, unknown>[] =
    name === "SourceAppIcon" || name === "AppTileIcon" ? APPS.map((app) => ({ app }))
      : name === "SourceDeviceIcon" || name === "DeviceBadgeIcon" ? SOURCES.map((source) => ({ source }))
        : name === "SkipIcon" ? [{}, { back: true }]
          : STATEFUL.includes(name) ? [{}, { filled: true }] : [{}];
  return variants.map((props) => render(<Icon {...props} />).container.querySelector("svg")!);
}

describe("one icon family", () => {
  it("finds the icons to check", () => {
    expect(glyphs.length).toBeGreaterThan(50);
  });

  it.each(glyphs)("%s draws on the 24-unit grid with the one stroke and round ends", (name, Icon) => {
    for (const svg of drawings(name, Icon)) {
      expect(svg, name).not.toBeNull();
      expect(svg.getAttribute("viewBox")).toBe("0 0 24 24");
      expect(svg.getAttribute("stroke-width")).toBe(String(Icons.ICON_STROKE));
      expect(svg.getAttribute("stroke-linecap")).toBe("round");
      expect(svg.getAttribute("stroke-linejoin")).toBe("round");
      expect(svg.getAttribute("stroke")).toBe("currentColor");
      expect(svg.getAttribute("aria-hidden")).toBe("true");
      for (const part of svg.querySelectorAll("*")) {
        const what = `${name}: <${part.tagName}>`;
        // One stroke: no part draws thicker or thinner lines, and dots are filled shapes.
        expect(part.hasAttribute("stroke-width"), what).toBe(false);
        expect(["none", "currentColor", null], what).toContain(part.getAttribute("stroke"));
        expect(["none", "currentColor", null], what).toContain(part.getAttribute("fill"));
        expect(part.hasAttribute("style"), what).toBe(false);
        expect(part.tagName.toLowerCase(), what).not.toBe("svg");
      }
    }
  });

  it("uses one stroke width, and only the two icon files and the brand's mark draw SVG", () => {
    expect(Icons.ICON_STROKE).toBe(1.75);
    const files = (dir: string): string[] => readdirSync(dir).flatMap((f) => {
      const path = join(dir, f);
      return statSync(path).isDirectory() ? files(path) : [path];
    });
    const DRAWERS = ["components/Icons.tsx", "components/ControlIcons.tsx", "components/BrandMark.tsx"];
    const offenders: string[] = [];
    for (const file of files(src)) {
      const name = relative(src, file).split("\\").join("/");
      if (/\.test\.tsx?$/.test(name) || name.startsWith("test/") || DRAWERS.includes(name)) continue;
      const text = readFileSync(file, "utf8");
      if (/\.(tsx?|jsx?)$/.test(name)) {
        // No inline SVG, no icon library, no symbol character standing in for an icon on a button or in a row.
        if (/<(svg|path|circle|rect|polyline|polygon|ellipse|use)\b/.test(text)) offenders.push(`${name}: inline SVG`);
        if (/createElementNS\(/.test(text)) offenders.push(`${name}: SVG built in code`);
        if (/from\s+["'](lucide|react-icons|@heroicons|@mui\/icons|@material|@fortawesome|@phosphor|phosphor-react|@tabler)/.test(text)) offenders.push(`${name}: icon library`);
        if (/>\s*[×✕✖✓✔✗⋯▾▸▴▲▼►◀☰⚙★☆]\s*</.test(text)) offenders.push(`${name}: a symbol character as an icon`);
      }
      if (name.endsWith(".css")) {
        if (/content:\s*["'][^"']*[×✕✖✓✔✗⋯▾▸▴▲▼►◀☰⚙★☆][^"']*["']/.test(text)) offenders.push(`${name}: a symbol character as an icon`);
        if (/url\([^)]*\.(svg|png|ico)|data:image\/svg/.test(text)) offenders.push(`${name}: an image as an icon`);
      }
    }
    expect(offenders).toEqual([]);
  });
});

describe("the phone's metaphors (DESIGN_SYSTEM.md \"Icons\")", () => {
  const html = (node: React.ReactElement) => render(node).container.innerHTML;

  it("draws Calls as the handset, a voice note as the microphone, a video call as the camera", () => {
    expect(html(<Icons.SourceAppIcon app="phone_call" />)).toBe(html(<Icons.CallIcon />));
    expect(html(<Icons.SourceAppIcon app="voice_note" />)).toBe(html(<Icons.MicIcon />));
    expect(html(<Icons.SourceAppIcon app="zoom" />)).toBe(html(<Icons.VideoIcon />));
  });

  it("puts the app's line icon on a row's tinted tile, and the source's on its badge", () => {
    for (const app of APPS) expect(html(<Icons.AppTileIcon app={app} />)).toBe(html(<Icons.SourceAppIcon app={app} size={22} />));
    for (const source of SOURCES) expect(html(<Icons.DeviceBadgeIcon source={source} />)).toBe(html(<Icons.SourceDeviceIcon source={source} size={12} />));
  });

  it("keeps a filled form apart from the outline for each page", () => {
    for (const name of STATEFUL) {
      const Icon = (Icons as unknown as Record<string, AnyIcon>)[name];
      expect(html(<Icon filled />), name).not.toBe(html(<Icon />));
      expect(render(<Icon filled />).container.querySelector("[fill=currentColor]"), name).not.toBeNull();
    }
  });

  it("tells Screens' monitor from this computer's laptop, and Export from Import", () => {
    expect(html(<Icons.MonitorIcon />)).not.toBe(html(<Icons.DesktopIcon />));
    expect(html(<Icons.DownloadIcon />)).not.toBe(html(<Icons.ImportIcon />));
    expect(html(<Icons.RequestFilesIcon />)).not.toBe(html(<Icons.InboxIcon />));
  });

  it("draws the record control's dot as a filled circle", () => {
    const dot = render(<Controls.RecordDotIcon />).container.querySelector("circle")!;
    expect(dot).toHaveAttribute("fill", "currentColor");
  });
});
