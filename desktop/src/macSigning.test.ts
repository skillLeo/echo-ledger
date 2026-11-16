// The Mac signing checks (scripts/check-mac-signature.sh, scripts/release-mac.sh) run against stand-ins for codesign and
// shasum, and the "Desktop (macOS)" workflow's signing steps read as text. The scripts run on a Mac in CI and in the
// release shell; here only their decisions are checked.
import { spawnSync } from "node:child_process";
import { chmodSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { afterEach, describe, expect, it } from "vitest";

const check = resolve(__dirname, "../scripts/check-mac-signature.sh");
const release = resolve(__dirname, "../scripts/release-mac.sh");
const PINNED = "e4e522f7ca98ac2aca82590ccb7bdf84fe84b7301e6462dba8dae44643393956";
const SHA1 = "0123456789abcdef0123456789abcdef01234567";
let dirs: string[] = [];

afterEach(() => {
  dirs.forEach((d) => rmSync(d, { recursive: true, force: true }));
  dirs = [];
});

interface Signature {
  verifies?: boolean;
  flags?: string;
  entitlements?: string;
  certificate?: boolean;
  sha256?: string;
  requirement?: string;
}

const ENTITLED = `<?xml version="1.0" encoding="UTF-8"?><plist version="1.0"><dict><key>com.apple.security.device.audio-input</key><true/>
  <key>com.apple.security.automation.apple-events</key>
  <true/><key>com.apple.security.network.client</key><true/></dict></plist>`;
const GOOD: Required<Signature> = {
  verifies: true,
  flags: "0x10000(runtime)",
  entitlements: ENTITLED,
  certificate: true,
  sha256: PINNED,
  requirement: `identifier "com.echoledger.desktop" and certificate leaf = H"${SHA1}"`,
};

/** Runs check-mac-signature.sh on a stand-in app whose codesign and shasum answer as `signature` says. */
function runCheck(signature: Signature, pinned: boolean) {
  const s = { ...GOOD, ...signature };
  const root = mkdtempSync(join(tmpdir(), "mac-signature-"));
  dirs.push(root);
  const app = join(root, "EchoLedger.app");
  mkdirSync(join(app, "Contents"), { recursive: true });
  const bin = join(root, "bin");
  mkdirSync(bin);
  writeFileSync(join(root, "entitlements.xml"), s.entitlements);
  const codesign = `#!/bin/sh
case "$*" in
  --verify*) [ "${s.verifies ? 0 : 1}" = 0 ] || { echo "invalid signature" >&2; exit 1; } ;;
  "-dv "*) echo "Executable=$2" >&2; echo "CodeDirectory v=20500 size=1 flags=${s.flags} hashes=1+0 location=embedded" >&2 ;;
  *--entitlements*) cat "${join(root, "entitlements.xml")}" ;;
  *--extract-certificates=*) ${s.certificate ? `prefix=$(echo "$*" | sed 's/.*--extract-certificates=\\([^ ]*\\).*/\\1/'); echo cert > "\${prefix}0"` : "true"} ;;
  "-d -r- "*) echo "Executable=$3" >&2; echo 'designated => ${s.requirement}' ;;
esac
exit 0
`;
  const shasum = `#!/bin/sh
case "$2" in 256) echo "${s.sha256}  $3" ;; 1) echo "${SHA1}  $3" ;; esac
`;
  for (const [name, body] of [["codesign", codesign], ["shasum", shasum]]) {
    writeFileSync(join(bin, name), body);
    chmodSync(join(bin, name), 0o755);
  }
  return spawnSync("sh", [check, app, ...(pinned ? ["--pinned"] : [])], { encoding: "utf8", env: { ...process.env, PATH: `${bin}:${process.env.PATH}` } });
}

describe.runIf(process.platform !== "win32")("check-mac-signature.sh", () => {
  it("passes a strict signature with the hardened runtime, the microphone and Apple events, and the pinned certificate", () => {
    expect(runCheck({}, false).status).toBe(0);
    const pinned = runCheck({}, true);
    expect(pinned.status, pinned.stderr).toBe(0);
    expect(pinned.stdout).toContain("signed with EchoLedger Development Signing");
    // codesign says "root" when the certificate's subject has an organisation: the same one self-signed certificate.
    expect(runCheck({ requirement: `identifier "com.echoledger.desktop" and certificate root = H"${SHA1}"` }, true).status).toBe(0);
  });

  it("fails without the hardened runtime, or without the microphone or Apple events allowed", () => {
    const adhoc = runCheck({ flags: "0x20002(adhoc,linker-signed)" }, false);
    expect(adhoc.status).toBe(1);
    expect(adhoc.stderr).toContain("not signed with the hardened runtime");
    const noEvents = runCheck({ entitlements: ENTITLED.replace("com.apple.security.automation.apple-events", "com.apple.security.device.camera") }, false);
    expect(noEvents.status).toBe(1);
    expect(noEvents.stderr).toContain("do not allow com.apple.security.automation.apple-events");
    const refused = runCheck({ entitlements: ENTITLED.replace("<key>com.apple.security.device.audio-input</key><true/>", "<key>com.apple.security.device.audio-input</key><false/>") }, false);
    expect(refused.stderr).toContain("do not allow com.apple.security.device.audio-input");
    expect(runCheck({ verifies: false }, false).stderr).toContain("does not verify");
  });

  it("fails a build that is not from the pinned certificate, or whose requirement does not pin it", () => {
    expect(runCheck({ certificate: false }, true).stderr).toContain("holds no certificate");
    expect(runCheck({ sha256: "f".repeat(64) }, true).stderr).toContain("signed with another certificate");
    for (const requirement of [`cdhash H"${SHA1}"`, `identifier "com.echoledger.desktop" and certificate leaf = H"${"9".repeat(40)}"`,
      `identifier "com.echoledger.desktop" and certificate leaf = H"${SHA1}" or cdhash H"${SHA1}"`, `identifier "other" and certificate leaf = H"${SHA1}"`]) {
      const result = runCheck({ requirement }, true);
      expect(result.status, requirement).toBe(1);
      expect(result.stderr).toContain("does not pin the certificate");
    }
  });
});

describe.runIf(process.platform !== "win32")("release-mac.sh", () => {
  const run = (args: string[], env: Record<string, string | undefined>) => {
    const clean = { ...process.env };
    delete clean.TAURI_SIGNING_PRIVATE_KEY;
    delete clean.TAURI_SIGNING_PRIVATE_KEY_PATH;
    delete clean.TAURI_SIGNING_PRIVATE_KEY_PASSWORD;
    return spawnSync("sh", [release, ...args], { encoding: "utf8", env: { ...clean, ...env } as NodeJS.ProcessEnv });
  };

  it("refuses to start without the updater key, its password variable, or with a tag for a version", () => {
    const folder = mkdtempSync(join(tmpdir(), "release-mac-"));
    dirs.push(folder);
    expect(run([folder, "1.8.1"], {}).stderr).toContain("set TAURI_SIGNING_PRIVATE_KEY");
    expect(run([folder, "1.8.1"], { TAURI_SIGNING_PRIVATE_KEY: "key" }).stderr).toContain("set TAURI_SIGNING_PRIVATE_KEY_PASSWORD");
    const key = { TAURI_SIGNING_PRIVATE_KEY: "key", TAURI_SIGNING_PRIVATE_KEY_PASSWORD: "" };
    expect(run([folder, "v1.8.1"], key).stderr).toContain("without the v: 1.8.1");
    // Nothing to check: it stops before signing anything.
    const empty = run([folder, "1.8.1"], key);
    expect(empty.status).toBe(1);
    expect(empty.stderr).toContain("no *.dmg");
  });

  it("checks both apps with the pinned certificate before it signs, binding the version", () => {
    const script = readFileSync(release, "utf8");
    const checked = script.indexOf('check-mac-signature.sh" "$app" --pinned');
    const signed = script.indexOf("tauri signer sign --app-version \"$version\"");
    expect(checked).toBeGreaterThan(-1);
    expect(signed).toBeGreaterThan(checked);
    expect(script).toContain('for app in "$packaged" "$installed"');
    expect(script).toContain("hold different builds");
    expect(script).toContain("-readonly");
  });
});

describe("the Mac workflow signs only what can be released, and proves the signature", () => {
  const mac = readFileSync(resolve(__dirname, "../../.github/workflows/desktop-mac.yml"), "utf8");
  const step = (name: string) => {
    const start = mac.indexOf(`- name: ${name}`);
    expect(start, name).toBeGreaterThan(-1);
    const next = mac.indexOf("\n      - ", start + 1);
    return mac.slice(start, next === -1 ? undefined : next);
  };

  it("uses the self-signed identity on main, v1.1 and v* tags only; other branches build ad hoc", () => {
    expect(mac).toContain("SIGNING_REF: ${{ github.ref == 'refs/heads/main' || github.ref == 'refs/heads/v1.1' || startsWith(github.ref, 'refs/tags/v') }}");
    expect(step("Import the self-signed identity")).toContain("if: env.HAS_APPLE_SIGNING != 'true' && env.HAS_SELF_SIGNING == 'true' && env.SIGNING_REF == 'true'");
  });

  it("changes no trust setting: signing and the checks need none", () => {
    expect(mac).not.toMatch(/add-trusted-cert|remove-trusted-cert|sudo/);
    // The untrusted identity signs a probe with the hardened runtime, which verifies strictly, before the build.
    expect(step("Import the self-signed identity")).toContain('codesign --verify --strict --verbose=2 "$RUNNER_TEMP/probe"');
  });

  it("checks the hardened runtime, the microphone and Apple events, and the pinned certificate after the build", () => {
    const checkStep = step("Check the signature and the notarization");
    expect(checkStep).toContain("codesign -dv macos/EchoLedger.app");
    expect(checkStep).toContain("codesign -d --entitlements - --xml macos/EchoLedger.app");
    expect(checkStep).toContain("check-mac-signature.sh\" macos/EchoLedger.app --pinned");
    expect(checkStep).toMatch(/HAS_APPLE_SIGNING" = "true" \]; then\n\s+sh "\$GITHUB_WORKSPACE\/desktop\/scripts\/check-mac-signature.sh" macos\/EchoLedger.app\n/);
  });

  it("packs the updater package for the release shell, as the key stays out of GitHub", () => {
    const pack = step("Pack the updater package for the release shell");
    expect(pack).toContain("if: env.HAS_UPDATER_KEY != 'true'");
    expect(pack).toContain("COPYFILE_DISABLE=1 tar --no-mac-metadata -czf EchoLedger.app.tar.gz EchoLedger.app");
    expect(mac.indexOf("- name: Pack the updater package")).toBeGreaterThan(mac.indexOf("- name: Check the signature"));
    expect(mac).toContain("bundle/macos/*.app.tar.gz\n");
  });
});
