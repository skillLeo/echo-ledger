// Mark as read / Mark as unread for many recordings (contract 2.0.0-y): one request per 200 ids (POST or DELETE
// /api/sessions/opened) in place of one per recording. Only an answer like a server older than the route (the core's
// null: 405, 404, 403 for DELETE, or no `done`), first or later, gets today's one request per recording, and then for
// 200 at most in the run: the device's requests are limited (240 a minute), so a long run would hold up the sync and
// the uploads. Any other failure (the server down, busy or out of reach) stops the run: what was done stays, the rest
// is put back by the caller.
import { api } from "../../lib/ipc";

/** Ids per request (the contract's 200, the core's MAX_BULK), and the most marked one by one on an older server. */
export const OPENED_PER_REQUEST = 200;

/**
 * What is known so far, kept by the caller, so a failure partway still says what the server took: `done`, the ids it
 * didn't find (another workspace's, in the bin), and those left out on an older server, beyond the 200 it takes one by
 * one.
 */
export type OpenedProgress = { done: Set<string>; notFound: Set<string>; left: Set<string> };

export const openedProgress = (): OpenedProgress => ({ done: new Set(), notFound: new Set(), left: new Set() });

/**
 * Marks [ids] read ([opened]) or unread, filling [progress] as the answers come. Throws the first error (of a request
 * for many, or of one made one by one); what was done before it is in [progress].
 */
export async function markOpenedMany(ids: readonly string[], opened: boolean, progress: OpenedProgress): Promise<void> {
  let oneByOne = false;
  // Requests made one by one in this run: 200 at most, whichever answer started them.
  let single = 0;
  for (let at = 0; at < ids.length; at += OPENED_PER_REQUEST) {
    const chunk = ids.slice(at, at + OPENED_PER_REQUEST);
    if (!oneByOne) {
      const answer = await (opened ? api.markOpenedMany(chunk) : api.markUnopenedMany(chunk));
      if (answer && Array.isArray(answer.done)) {
        const sent = new Set(chunk);
        for (const id of answer.done) if (sent.has(id)) progress.done.add(id);
        for (const id of answer.not_found ?? []) if (sent.has(id) && !progress.done.has(id)) progress.notFound.add(id);
        continue;
      }
      oneByOne = true;
    }
    for (const id of chunk) {
      if (progress.done.has(id) || progress.notFound.has(id)) continue;
      if (single >= OPENED_PER_REQUEST) {
        progress.left.add(id);
        continue;
      }
      single++;
      await (opened ? api.markOpened(id) : api.markUnopened(id));
      progress.done.add(id);
    }
  }
}
