// A client's round avatar (two letters or an emoji on its colour) and its one-line summary, shared by the client list
// and the workspace header.
import type { ClientFolder } from "../../lib/ipc";
import { folderBadge } from "../../lib/organise";
import { formatWhen } from "../../lib/format";

/** The tile colours of client avatars, picked from the name so a client keeps its colour (the phone does the same). */
const AVATAR_TILES = ["var(--tile-video)", "var(--tile-chat)", "var(--tile-voice)", "var(--accent)", "var(--client)"];

export function avatarTile(client: Pick<ClientFolder, "name" | "color">): string {
  if (client.color && /^#[0-9a-f]{6}$/i.test(client.color)) return client.color;
  let hash = 0;
  for (const ch of client.name) hash = (hash * 31 + ch.charCodeAt(0)) >>> 0;
  return AVATAR_TILES[hash % AVATAR_TILES.length];
}

export function ClientAvatar({ client, large = false }: { client: Pick<ClientFolder, "name" | "color" | "avatar">; large?: boolean }) {
  return <span className={`client-avatar ${large ? "large" : ""}`} style={{ ["--tile" as string]: avatarTile(client) }} aria-hidden="true">{folderBadge(client)}</span>;
}

/** "2 recordings · Last call Oct 24" or "No recordings yet". */
export function clientSummary(count: number, lastCall: string | undefined): string {
  if (count === 0) return "No recordings yet";
  const when = lastCall ? formatWhen(lastCall).split(" · ")[0] : "";
  return `${count} recording${count === 1 ? "" : "s"}${when ? ` · Last call ${when}` : ""}`;
}
