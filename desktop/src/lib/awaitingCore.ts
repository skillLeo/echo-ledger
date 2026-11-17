// Commands the interface wraps before the core has them. The list is short-lived by design:
// - ipc.contract.test.ts fails the day the core adds one, so it comes off the list and its arguments are checked;
// - releaseGate.test.ts fails a release build while a screen the release can reach still needs one, and fails every
//   run once the app's version reaches `removeBy`.
export const AWAITING_CORE = {
  /** The app version by which the list must be empty. */
  removeBy: "2.0.0",
  /** Empty: the help commands (docs/API.md 2.0.0-d) are in the core. */
  commands: [] as readonly string[],
} as const;

/** "1.10.2" ≥ "1.9.0", by number. */
export function versionAtLeast(version: string, floor: string): boolean {
  const a = version.split(/[.+-]/).slice(0, 3).map((n) => Number.parseInt(n, 10) || 0);
  const b = floor.split(".").map((n) => Number.parseInt(n, 10) || 0);
  for (let i = 0; i < 3; i++) if ((a[i] ?? 0) !== (b[i] ?? 0)) return (a[i] ?? 0) > (b[i] ?? 0);
  return true;
}

/** Why a build can't ship as it is (empty: it can). */
export function releaseBlockers({ release, version, awaiting, helpReachable }: {
  release: boolean;
  version: string;
  awaiting: readonly string[];
  /** Settings › Help opens the page that needs the help commands (with ui_v2.settings on). */
  helpReachable: boolean;
}): string[] {
  const out: string[] = [];
  if (awaiting.length === 0) return out;
  if (versionAtLeast(version, AWAITING_CORE.removeBy)) {
    out.push(`version ${version} has reached ${AWAITING_CORE.removeBy}: the core must have ${awaiting.join(", ")}, and AWAITING_CORE must be empty`);
  }
  const help = awaiting.filter((c) => c.startsWith("help_") || c === "support_send");
  if (release && helpReachable && help.length > 0) {
    out.push(`a release can open Help v2 but the core lacks ${help.join(", ")}`);
  }
  return out;
}
