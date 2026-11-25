// Mark as read / Mark as unread for many recordings (contract 2.0.0-y): one request per 200 ids (POST or DELETE
// /api/sessions/opened) in place of one per recording. An older server, which has no such route (anything but a 200
// with `done`), gets today's one request per recording.
import { api } from "../../lib/ipc";

/** Ids per request (the contract's 200, the core's MAX_BULK). */
export const OPENED_PER_REQUEST = 200;

/** What is known so far: kept by the caller, so a failure partway still says what the server took. */
export type OpenedProgress = { done: Set<string>; notFound: Set<string> };

/**
 * Marks [ids] read ([opened]) or unread, filling [progress] as the answers come. Ids the server did not find (another
 * workspace's, in the bin) go in `notFound`, never an error. Throws the first error of a request made one by one.
 */
export async function markOpenedMany(ids: readonly string[], opened: boolean, progress: OpenedProgress): Promise<void> {
  let oneByOne = false;
  for (let at = 0; at < ids.length; at += OPENED_PER_REQUEST) {
    const chunk = ids.slice(at, at + OPENED_PER_REQUEST);
    const answer = oneByOne ? null : await (opened ? api.markOpenedMany(chunk) : api.markUnopenedMany(chunk)).catch(() => null);
    if (!answer || !Array.isArray(answer.done)) oneByOne = true;
    const sent = new Set(chunk);
    for (const id of oneByOne ? [] : answer!.done) if (sent.has(id)) progress.done.add(id);
    for (const id of oneByOne ? [] : answer!.not_found ?? []) if (sent.has(id) && !progress.done.has(id)) progress.notFound.add(id);
    // One by one: on an older server, and for any id an answer left out (a chunk of the core's that failed).
    for (const id of chunk) {
      if (progress.done.has(id) || progress.notFound.has(id)) continue;
      await (opened ? api.markOpened(id) : api.markUnopened(id));
      progress.done.add(id);
    }
  }
}
