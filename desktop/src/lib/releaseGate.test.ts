// The release gate for commands the interface wraps before the core has them (lib/awaitingCore.ts). A tag build (CI
// runs this suite on `v*` tags) or ECHOLEDGER_RELEASE=1 is a release: it fails while Settings › Help can open the page
// whose commands the core still lacks. Every run fails once the app's version reaches the list's removeBy.
import { describe, expect, it } from "vitest";
import packageJson from "../../package.json";
import { AWAITING_CORE, releaseBlockers, versionAtLeast } from "./awaitingCore";
import { helpV2Reachable } from "../v2/help/route";

const release = process.env.ECHOLEDGER_RELEASE === "1" || process.env.GITHUB_REF_TYPE === "tag" || (process.env.GITHUB_REF ?? "").startsWith("refs/tags/");

describe("release gate", () => {
  it("this build has no blocker", () => {
    expect(releaseBlockers({
      release, version: packageJson.version, awaiting: AWAITING_CORE.commands, helpReachable: helpV2Reachable({ "ui_v2.settings": true }),
    })).toEqual([]);
  });

  it("a release with Help reachable and its commands missing is blocked; without either it is not", () => {
    const awaiting = ["help_guides", "support_send"];
    expect(releaseBlockers({ release: true, version: "1.9.0", awaiting, helpReachable: true })).toEqual([
      "a release can open Help v2 but the core lacks help_guides, support_send",
    ]);
    expect(releaseBlockers({ release: false, version: "1.9.0", awaiting, helpReachable: true })).toEqual([]);
    expect(releaseBlockers({ release: true, version: "1.9.0", awaiting, helpReachable: false })).toEqual([]);
    expect(releaseBlockers({ release: true, version: "1.9.0", awaiting: [], helpReachable: true })).toEqual([]);
  });

  it("the list must be empty by its removeBy version, release or not", () => {
    expect(AWAITING_CORE.removeBy).toBe("2.0.0");
    expect(releaseBlockers({ release: false, version: "2.0.0", awaiting: ["help_guide"], helpReachable: false })).toEqual([
      "version 2.0.0 has reached 2.0.0: the core must have help_guide, and AWAITING_CORE must be empty",
    ]);
    expect(versionAtLeast("1.10.2", "1.9.0")).toBe(true);
    expect(versionAtLeast("1.9.9", "2.0.0")).toBe(false);
    expect(versionAtLeast("2.0.0-beta.1", "2.0.0")).toBe(true);
  });

  it("Help v2 is reachable exactly when the new Settings is on", () => {
    expect(helpV2Reachable({ "ui_v2.settings": true })).toBe(true);
    expect(helpV2Reachable({ "ui_v2.settings": false })).toBe(false);
    expect(helpV2Reachable({})).toBe(false);
  });
});
