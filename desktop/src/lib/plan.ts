// The workspace's plan and the person's role as the windows show them (since v1.5, docs/API.md "Plans and features",
// "Workspaces, roles and privacy"). The core reads them from GET /api/config (AppStatus.plan).
import type { AppStatus, PlanInfo, WorkspaceRole } from "./ipc";
import { fileSize } from "./format";

export const ROLE_LABEL: Record<WorkspaceRole, string> = { owner: "Owner", admin: "Admin", member: "Member", viewer: "Viewer" };

/** What a viewer's app says instead of recording (the same sentence as the core and the phone). */
export function viewerSentence(workspace: string | null | undefined): string {
  const name = workspace?.trim() || "this workspace";
  return `You are a viewer in ${name}: you can open what is shared with you, not record.`;
}

/** A viewer opens what is shared with them and records nothing; an unknown role acts as a member. */
export function isViewer(status: Pick<AppStatus, "plan"> | null | undefined): boolean {
  return status?.plan?.role === "viewer";
}

/** "123 of 600 minutes this month", or "123 minutes this month (no limit)". */
export function minutesLine(plan: PlanInfo): string {
  const used = Math.round(plan.usedMinutes ?? 0).toLocaleString("en-US");
  if (plan.monthlyMinutes === null) return `${used} minutes this month (no limit)`;
  return `${used} of ${Math.round(plan.monthlyMinutes).toLocaleString("en-US")} minutes this month`;
}

/** "1.2 GB of 5 GB", or "1.2 GB (no limit)"; none when the server did not say what is used. */
export function storageLine(plan: PlanInfo): string | null {
  if (plan.usedStorageBytes === null) return plan.storageBytes === null ? null : `Up to ${fileSize(plan.storageBytes)}`;
  if (plan.storageBytes === null) return `${fileSize(plan.usedStorageBytes)} stored (no limit)`;
  return `${fileSize(plan.usedStorageBytes)} of ${fileSize(plan.storageBytes)} stored`;
}

/** How full a limit is, 0..100; none without a limit. */
export function percentUsed(used: number | null, limit: number | null): number | null {
  if (limit === null || limit <= 0) return null;
  return Math.min(100, Math.max(0, Math.round(((used ?? 0) / limit) * 100)));
}

/** The plan's features by name, for the account page: what is in the plan and what is not. */
export function planFeatures(plan: PlanInfo): [string, boolean][] {
  return [
    ["Live Translate", plan.liveTranslate],
    ["Screen moments", plan.screenMoments],
    ["Call notes, memory and Ask", plan.aiNotes],
    ["Claude and ChatGPT connector", plan.connector],
  ];
}
