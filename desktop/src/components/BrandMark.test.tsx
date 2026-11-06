import { describe, expect, it } from "vitest";
import { render } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { BrandMark, Wordmark } from "./BrandMark";

const brand = join(dirname(fileURLToPath(import.meta.url)), "../../../docs/design/brand");
/** The `d` of every path in an SVG file, in order. */
const pathsOf = (file: string) => [...readFileSync(join(brand, file), "utf8").matchAll(/\sd="([^"]+)"/g)].map((m) => m[1]);
const drawn = (size: number) => [...render(<BrandMark size={size} />).container.querySelectorAll("path")].map((p) => p.getAttribute("d"));

describe("the brand mark", () => {
  it("draws the strokes of mark.svg from 24 px", () => {
    expect(drawn(24)).toEqual(pathsOf("mark.svg"));
    expect(drawn(40)).toEqual(pathsOf("mark.svg"));
  });

  it("draws mark-small.svg below 24 px, with its bolder strokes and the wave moved down", () => {
    const { container } = render(<BrandMark size={16} />);
    expect(drawn(16)).toEqual(pathsOf("mark-small.svg"));
    expect(container.querySelector("g")).toHaveAttribute("stroke-width", "6.5");
    expect(container.querySelector("path")).toHaveAttribute("transform", "translate(0 5)");
  });

  it("keeps the brand indigo tile with the brand's corner radius, whatever the accent colour", () => {
    const tile = render(<BrandMark />).container.querySelector("rect")!;
    expect(tile.getAttribute("fill")?.toLowerCase()).toBe("#3e63dd");
    expect(tile).toHaveAttribute("rx", "15");
    expect(readFileSync(join(brand, "mark.svg"), "utf8")).toContain('rx="15" fill="#3E63DD"');
  });

  it("is decoration next to the name, which is text", () => {
    const { container } = render(<Wordmark />);
    expect(container.querySelector("svg")).toHaveAttribute("aria-hidden", "true");
    expect(container).toHaveTextContent("EchoLedger");
  });
});
