// The smoke-test scripts of the CI workflows, run against stand-in apps: passing, failing, hanging and crashing. The Mac
// script needs PlistBuddy, so this runs on a Mac only; the workflows run the real app with it on every runner.
import { execFileSync, spawnSync } from "node:child_process";
import { chmodSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
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

/** As CI runs it (GITHUB_ACTIONS=true: CI checks the signature in its own step, and branch builds are ad hoc). */
function run(app: string, home: string, limit: string, ci = true) {
  const env: NodeJS.ProcessEnv = { ...process.env, HOME: home };
  if (ci) env.GITHUB_ACTIONS = "true";
  else delete env.GITHUB_ACTIONS;
  return spawnSync("sh", [script, app, limit], { env, encoding: "utf8" });
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

  // D-168: 2.0.3 was installed unsigned (ad hoc): macOS gave it silent microphone buffers, since its permissions belong
  // to the pinned certificate. Before an install (outside CI) the smoke test refuses such an app, without starting it.
  it("outside CI refuses an app that isn't signed with the pinned certificate, before starting it", () => {
    const { app, home } = fakeApp(`echo started > "$HOME/started"\necho "[echoledger] smoke test: ok: 5 windows" >&2`);
    const result = run(app, home, "20", false);
    expect(result.stdout).toContain("not signed with the pinned EchoLedger certificate");
    expect(result.stdout).toContain("microphone");
    expect(result.status).toBe(1);
    expect(existsSync(join(home, "started"))).toBe(false);
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

/** The release job's script, run with a stand-in for `gh` that logs its arguments. */
function runReleaseStep(tag: string, releaseExists: boolean): string[] {
  const workflow = readFileSync(resolve(__dirname, "../../.github/workflows/desktop-mac.yml"), "utf8");
  const step = workflow.slice(workflow.indexOf("- name: Attach to the release of this tag"));
  const body = step.slice(step.indexOf("run: |") + "run: |".length).split("\n").map((line) => line.replace(/^ {10}/, "")).join("\n");
  const root = mkdtempSync(join(tmpdir(), "release-step-"));
  dirs.push(root);
  for (const [folder, dmg] of [["EchoLedger-mac-apple-silicon", "EchoLedger_1.5.0_aarch64.dmg"], ["EchoLedger-mac-intel", "EchoLedger_1.5.0_x64.dmg"]]) {
    mkdirSync(join(root, "downloaded", folder), { recursive: true });
    writeFileSync(join(root, "downloaded", folder, dmg), "dmg");
  }
  mkdirSync(join(root, "bin"));
  const log = join(root, "gh.log");
  const gh = join(root, "bin", "gh");
  writeFileSync(gh, `#!/bin/sh\necho "$*" >> "${log}"\n[ "$1 $2" = "release view" ] && exit ${releaseExists ? 0 : 1}\nexit 0\n`);
  chmodSync(gh, 0o755);
  const result = spawnSync("sh", ["-e", "-c", body], {
    cwd: root,
    encoding: "utf8",
    env: { ...process.env, PATH: `${join(root, "bin")}:${process.env.PATH}`, TAG: tag, GITHUB_STEP_SUMMARY: join(root, "summary") },
  });
  expect(result.status, result.stderr).toBe(0);
  return readFileSync(log, "utf8").trim().split("\n");
}

describe.runIf(process.platform !== "win32")("the release job", () => {
  it("makes the release when the tag has none and attaches both DMGs, a beta as a pre-release", () => {
    const calls = runReleaseStep("v1.5.0-beta.1", false);
    expect(calls[0]).toBe("release view v1.5.0-beta.1");
    expect(calls[1]).toMatch(/^release create v1\.5\.0-beta\.1 --verify-tag .*--prerelease$/);
    expect(calls[2]).toMatch(/^release upload v1\.5\.0-beta\.1 --clobber /);
    expect(calls[2]).toContain("EchoLedger_1.5.0_aarch64.dmg");
    expect(calls[2]).toContain("EchoLedger_1.5.0_x64.dmg");
  });

  it("a final version is not a pre-release", () => {
    expect(runReleaseStep("v1.5.0", false)[1]).not.toContain("--prerelease");
  });

  it("adds to a release the tag already has", () => {
    const calls = runReleaseStep("v1.5.0", true);
    expect(calls.some((call) => call.startsWith("release create"))).toBe(false);
    expect(calls.some((call) => call.startsWith("release upload v1.5.0 --clobber"))).toBe(true);
  });
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
    // Only the Arm runner's Share Check may fail without failing the run (its image's own window covers the test
    // window): the step, never the job, so the smoke test and its second run fail the run on Arm too, and the Server
    // 2022 and 2025 smoke tests and Share Checks must pass.
    const steps = windows.slice(windows.indexOf("\n  smoke:")).split("\n      - ");
    const shareCheck = steps.find((step) => step.startsWith("name: Share Check"));
    expect(shareCheck).toContain("continue-on-error: ${{ matrix.allow_failure }}");
    expect(windows.match(/continue-on-error/g)).toHaveLength(1);
    expect(steps.filter((step) => step.includes("smoke-windows.ps1"))).toHaveLength(2);
    expect(windows.match(/allow_failure: true/g)).toHaveLength(1);
    expect(windows).toMatch(/runner: windows-11-arm\n\s+label: [^\n]+\n\s+allow_failure: true/);
    expect(windows.match(/allow_failure: false/g)).toHaveLength(2);
  });

  it("the Windows script also works outside a runner, and the workflow proves it", () => {
    const script = readFileSync(resolve(__dirname, "../scripts/smoke-windows.ps1"), "utf8");
    expect(script).toContain("[System.IO.Path]::GetTempPath()");
    expect(script).not.toMatch(/Join-Path \$env:RUNNER_TEMP/);
    expect(workflow("desktop-windows.yml")).toContain("Remove-Item Env:RUNNER_TEMP");
  });

  it("the Windows build keeps its PDB as an artifact only: never in the installer or a release, no runner path in the exe", () => {
    const windows = workflow("desktop-windows.yml");
    expect(windows).toContain("CARGO_PROFILE_RELEASE_DEBUG: line-tables-only");
    expect(windows).toContain('CARGO_TARGET_X86_64_PC_WINDOWS_MSVC_RUSTFLAGS: "-C link-arg=/PDBALTPATH:%_PDB%"');
    const pdb = windows.split("\n      - ").find((step) => step.includes("name: EchoLedger-windows-x64-pdb")) ?? "";
    expect(pdb).toContain("retention-days: ${{ startsWith(github.ref, 'refs/tags/') && 90 || 7 }}");
    expect(windows).toContain("name: Check the installer carries no PDB");
    expect(windows).toMatch(/permissions:\n  contents: read\n/);
    expect(windows).not.toMatch(/contents: write|gh release|action-gh-release|tagName|releaseId/);
  });

  it("the manual Windows check waits for the windowed app before it reads the exit code", () => {
    const qa = readFileSync(resolve(__dirname, "../../docs/MANUAL_QA.md"), "utf8");
    const row = qa.split("\n").find((line) => line.startsWith("| WIN-6 |")) ?? "";
    expect(row).toContain("start /wait");
    expect(row).toContain("%ERRORLEVEL%");
  });

  it("the first-launch text no longer says right-click", () => {
    expect(workflow("desktop-mac.yml")).not.toMatch(/right-click/i);
    expect(workflow("desktop-mac.yml")).toContain("Open Anyway");
  });
});
