import { afterEach, describe, expect, it } from "vitest";
import { collectRegions } from "./hitRegions";

describe("collectRegions", () => {
  afterEach(() => {
    document.body.innerHTML = "";
  });

  it("reports [data-hit] rects scaled by the device pixel ratio", () => {
    const el = document.createElement("div");
    el.setAttribute("data-hit", "");
    el.getBoundingClientRect = () => ({ left: 10, top: 20, width: 30, height: 40, right: 40, bottom: 60, x: 10, y: 20, toJSON: () => ({}) });
    document.body.appendChild(el);

    expect(collectRegions(document, 2)).toEqual([{ x: 20, y: 40, w: 60, h: 80 }]);
  });

  it("ignores zero-size regions and elements without the marker", () => {
    const hidden = document.createElement("div");
    hidden.setAttribute("data-hit", "");
    hidden.getBoundingClientRect = () => ({ left: 0, top: 0, width: 0, height: 0, right: 0, bottom: 0, x: 0, y: 0, toJSON: () => ({}) });
    const plain = document.createElement("div");
    plain.getBoundingClientRect = () => ({ left: 1, top: 1, width: 5, height: 5, right: 6, bottom: 6, x: 1, y: 1, toJSON: () => ({}) });
    document.body.append(hidden, plain);

    expect(collectRegions(document, 1)).toEqual([]);
  });
});
