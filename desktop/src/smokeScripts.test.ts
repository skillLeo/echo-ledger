// The smoke-test scripts of the CI workflows, run against stand-in apps: passing, failing, hanging and crashing. The Mac
// script needs PlistBuddy, so this runs on a Mac only; the workflows run the real app with it on every runner.
import { execFileSync, spawnSync } from "node:child_process";
import { chmodSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { afterEach, describe, expect, it } from "vitest";

const script = resolve(__dirname, "../scripts/smoke-mac.sh");
let dirs: string[] = [];

/** A folder that looks like EchoLedger.app, whose binary is this shell script. */
function fakeApp(body: string): { app: string; home: string } {
  const root = mkdtempSync(join(tmpdir(), "smoke-script-"));
  dirs.push(root);
  const app = join(root, "EchoLedger.app");
  mkdirSync(join(app, "Contents/MacOS"), { recursive: true });
  const plist = join(app, "Contents/Info.plist");
  execFileSync("/usr/libexec/PlistBuddy", ["-c", "Add :CFBundleExecutable string fake", plist]);
  const binary = join(app, "Contents/MacOS/fake");
  writeFileSync(binary, `#!/bin/sh\n${body}\n`);
  chmodSync(binary, 0o755);
  const home = join(root, "home");
  mkdirSync(home);
  return { app, home };
}

function run(app: string, home: string, limit: string) {
  return spawnSync("sh", [script, app, limit], { env: { ...process.env, HOME: home }, encoding: "utf8" });
}

afterEach(() => {
  dirs.forEach((d) => rmSync(d, { recursive: true, force: true }));
  dirs = [];
});

describe.runIf(process.platform === "darwin")("smoke-mac.sh", () => {
  it("passes an app that says its windows were ready and exits 0, and asks for the smoke test", () => {
    const { app, home } = fakeApp(`[ "$1" = "--smoke-test" ] || exit 9\necho "[echoledger] smoke test: ok: 5 windows" >&2`);
    const result = run(app, home, "20");
    expect(result.stdout).toContain("Smoke test passed");
    expect(result.status).toBe(0);
  }, 30_000);

  it("fails a non-zero exit", () => {
    const { app, home } = fakeApp(`echo "[echoledger] smoke test: failed" >&2\nexit 3`);
    const result = run(app, home, "20");
    expect(result.stdout).toContain("exited with 3");
    expect(result.status).toBe(1);
  }, 30_000);

  it("fails an exit 0 that never said ok (another instance took it, say)", () => {
    const { app, home } = fakeApp("exit 0");
    const result = run(app, home, "20");
    expect(result.stdout).toContain("without saying");
    expect(result.status).toBe(1);
  }, 30_000);

  it("kills a hang at the limit and fails", () => {
    const { app, home } = fakeApp("exec sleep 300");
    const result = run(app, home, "2");
    expect(result.stdout).toContain("still running after 2 s");
    expect(result.status).toBe(1);
  }, 30_000);

  it("fails on a new EchoLedger crash report and ignores another app's", () => {
    const crash = fakeApp(`mkdir -p "$HOME/Library/Logs/DiagnosticReports"\necho report > "$HOME/Library/Logs/DiagnosticReports/echoledger-desktop-2026.ips"\necho "smoke test: ok" >&2`);
    const crashed = run(crash.app, crash.home, "20");
    expect(crashed.stdout).toContain("new crash reports");
    expect(crashed.status).toBe(1);
    const other = fakeApp(`mkdir -p "$HOME/Library/Logs/DiagnosticReports"\necho report > "$HOME/Library/Logs/DiagnosticReports/Safari-2026.ips"\necho "smoke test: ok" >&2`);
    expect(run(other.app, other.home, "20").status).toBe(0);
  }, 60_000);
});

describe("the workflows run the smoke test", () => {
  const workflow = (name: string) => readFileSync(resolve(__dirname, `../../.github/workflows/${name}`), "utf8");

  it("on both Mac chips, on real runners, with the release job only on tags", () => {
    const mac = workflow("desktop-mac.yml");
    expect(mac).toContain("runner: macos-15\n");
    expect(mac).toContain("runner: macos-15-intel");
    expect(mac).not.toContain("checks: false");
    expect(mac).toContain("smoke-mac.sh");
    const release = mac.slice(mac.indexOf("  release:"));
    expect(release).toContain("if: startsWith(github.ref, 'refs/tags/v')");
    expect(release).toContain("contents: write");
    // Only the release job may write.
    expect(mac.slice(0, mac.indexOf("jobs:"))).toContain("contents: read");
    expect(mac.match(/contents: write/g)).toHaveLength(1);
  });

  it("on every Windows runner there is", () => {
    const windows = workflow("desktop-windows.yml");
    for (const runner of ["windows-2022", "windows-2025", "windows-11-arm"]) expect(windows).toContain(`runner: ${runner}`);
    expect(windows).toContain("smoke-windows.ps1");
  });

  it("the first-launch text no longer says right-click", () => {
    expect(workflow("desktop-mac.yml")).not.toMatch(/right-click/i);
    expect(workflow("desktop-mac.yml")).toContain("Open Anyway");
  });
});
