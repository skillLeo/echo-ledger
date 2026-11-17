// The release gate for commands the interface wraps before the core has them (lib/awaitingCore.ts). A tag build (CI
// runs this suite on `v*` tags) or ECHOLEDGER_RELEASE=1 is a release: it fails while the list has a command without a
// declared safe fallback. Every run fails once the app's version reaches the list's removeBy.
import { describe, expect, it } from "vitest";
import packageJson from "../../package.json";
import { AWAITING_CORE, releaseBlockers, versionAtLeast } from "./awaitingCore";

const release = process.env.ECHOLEDGER_RELEASE === "1" || process.env.GITHUB_REF_TYPE === "tag" || (process.env.GITHUB_REF ?? "").startsWith("refs/tags/");

describe("release gate", () => {
  it("this build has no blocker", () => {
    expect(releaseBlockers({ release, version: packageJson.version, awaiting: AWAITING_CORE.commands })).toEqual([]);
  });

  it("every command on the list today declares why the app is safe without it", () => {
    for (const c of AWAITING_CORE.commands) expect(c.fallback?.trim(), c.name).toBeTruthy();
  });

  it("a release is blocked by a command with no declared fallback, and passes one with a fallback", () => {
    const unsafe = { name: "help_guides" };
    const safe = { name: "live_translate_status", fallback: "Live Translate shows as unavailable without it." };
    expect(releaseBlockers({ release: true, version: "1.9.0", awaiting: [unsafe, safe] })).toEqual([
      "a release needs help_guides from the core: no safe fallback is declared",
    ]);
    expect(releaseBlockers({ release: true, version: "1.9.0", awaiting: [safe] })).toEqual([]);
    expect(releaseBlockers({ release: true, version: "1.9.0", awaiting: [{ name: "x", fallback: "  " }] })).toHaveLength(1);
    // Outside a release, only the version marker counts.
    expect(releaseBlockers({ release: false, version: "1.9.0", awaiting: [unsafe] })).toEqual([]);
    expect(releaseBlockers({ release: true, version: "1.9.0", awaiting: [] })).toEqual([]);
  });

  it("the list must be empty by its removeBy version, release or not, fallback or not", () => {
    expect(AWAITING_CORE.removeBy).toBe("2.0.0");
    expect(releaseBlockers({ release: false, version: "2.0.0", awaiting: [{ name: "live_translate_status", fallback: "safe" }] })).toEqual([
      "version 2.0.0 has reached 2.0.0: the core must have live_translate_status, and AWAITING_CORE must be empty",
    ]);
    expect(versionAtLeast("1.10.2", "1.9.0")).toBe(true);
    expect(versionAtLeast("1.9.9", "2.0.0")).toBe(false);
    expect(versionAtLeast("2.0.0-beta.1", "2.0.0")).toBe(true);
  });
});
