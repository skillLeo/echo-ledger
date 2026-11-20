// The words a shared item is shown with on the v2 Shared page and in its read-only view (classic's SharedView words).
import type { SharedItem } from "../../lib/ipc";
import { recordingName } from "../../lib/library";

/** A shared item's title: the recording's name, else the item's or the project's. */
export function sharedTitle(item: SharedItem): string {
  return item.session ? recordingName(item.title?.trim() || item.session.title) : item.title ?? item.client?.name ?? "Shared item";
}
