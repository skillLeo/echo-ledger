// The settings icons follow the one shared map (tokens/icons/settings.json, DESIGN_SYSTEM.md "Settings icons"): each
// goal group and each root page draws the Lucide glyph the map names, so desktop, web and the phone show the same
// meaning with the same picture.
import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import * as Drawn from "../../components/Icons";
import { ROOT_ICONS } from "../../components/Icons";
import { GOAL_GROUPS } from "./SettingsV2";

type Entry = { lucide: string; group?: string; platforms?: string[] };
const map = JSON.parse(readFileSync(join(dirname(fileURLToPath(import.meta.url)), "../../../../tokens/icons/settings.json"), "utf8")) as {
  groups: Record<string, Entry>;
  pages: Record<string, Entry>;
};

/** Drawn icons whose names aren't Lucide's but draw that glyph: RecordIcon is circle-dot (the ring and its dot, filled while recording). */
const SAME_GLYPH: Record<string, string> = { RecordIcon: "circle-dot" };

/** The map's name of a drawn icon: LucideCircleDotIcon → "circle-dot". */
function lucideName(icon: unknown): string | null {
  const alias = Object.entries(Drawn).find(([name, value]) => value === icon && name in SAME_GLYPH)?.[0];
  if (alias) return SAME_GLYPH[alias];
  const key = Object.entries(Drawn).find(([name, value]) => value === icon && /^Lucide\w+Icon$/.test(name))?.[0];
  if (!key) return null;
  return key.slice("Lucide".length, -"Icon".length).replace(/([a-z])([A-Z0-9])/g, "$1-$2").replace(/([0-9])([A-Z])/g, "$1-$2").toLowerCase();
}

/** GOAL_GROUPS, in the order of IA 5, against the map's group ids. */
const GROUP_IDS = ["recording", "transcription", "live", "privacy", "notifications", "appearance", "account", "setup", "shortcuts", "about"];

describe("settings icons follow the shared map", () => {
  it("each goal group draws the map's glyph", () => {
    expect(GOAL_GROUPS.map((g) => g.title).length).toBe(GROUP_IDS.length);
    const drawn = Object.fromEntries(GOAL_GROUPS.map((g, i) => [GROUP_IDS[i], lucideName(g.icon)]));
    const wanted = Object.fromEntries(GROUP_IDS.map((id) => [id, map.groups[id]?.lucide]));
    expect(drawn).toEqual(wanted);
  });

  it("each root page the desktop shows draws the map's glyph", () => {
    const shown = Object.keys(ROOT_ICONS).filter((id) => map.pages[id] && (!map.pages[id].platforms || map.pages[id].platforms!.includes("desktop")));
    expect(shown.length).toBeGreaterThan(10);
    const drawn = Object.fromEntries(shown.map((id) => [id, lucideName(ROOT_ICONS[id])]));
    const wanted = Object.fromEntries(shown.map((id) => [id, map.pages[id].lucide]));
    expect(drawn).toEqual(wanted);
  });
});
