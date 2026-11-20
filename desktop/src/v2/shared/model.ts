// The words a shared item is shown with on the v2 Shared page and in its read-only view (classic's SharedView words).
import type { SharedItem } from "../../lib/ipc";
import { formatWhen } from "../../lib/format";
import { recordingName } from "../../lib/library";
import { senderOf } from "../../views/main/SharedView";

/** A shared item's title: the recording's name, else the item's or the project's. */
export function sharedTitle(item: SharedItem): string {
  return item.session ? recordingName(item.title?.trim() || item.session.title) : item.title ?? item.client?.name ?? "Shared item";
}

/** "Recording from Imran · Oct 30 · 4:05 pm · “Please check the totals”" */
export function sharedMeta(item: SharedItem): string {
  return `${item.kind === "project" ? "Project" : "Recording"} from ${senderOf(item)}${item.sent_at ? ` · ${formatWhen(item.sent_at)}` : ""}${item.note ? ` · “${item.note}”` : ""}`;
}
