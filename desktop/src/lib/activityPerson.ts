// The person an Activity item is about (contract 2.0.0-aa) and when their face takes the kind's icon's place: only for
// the neutral kinds, as the web console does (lead decision 27, PERSON_KINDS in web/app/Filament/Pages/Activity.php).
// A warning, a failure or a receipt asked for keeps its tile, the only sign of what it is, whoever it is about. Pure,
// so the new look and the classic page read it the same way, and the phone mirrors it.
import type { ActivityActor, ActivityItem } from "./ipc";

/** The kinds whose person replaces the icon: a recording sent to you, and an upgrade asked for. */
export const PERSON_KINDS: ReadonlySet<string> = new Set(["shared_with_you", "upgrade_requested"]);

/** The tokens' avatar colours (User::AVATAR_COLORS on the server). */
const COLOURS = 8;

/**
 * The actor as the window can use it, or null: an object whose `name` is a string or null. Anything else the server
 * might send ("Ana", a number for a name) is no person, so the row keeps its icon and never breaks, as the core's
 * lenient reading does.
 */
export function personOf(value: unknown): ActivityActor | null {
  if (!value || typeof value !== "object" || Array.isArray(value)) return null;
  const v = value as Record<string, unknown>;
  if (v.name !== undefined && v.name !== null && typeof v.name !== "string") return null;
  const id = typeof v.id === "number" || typeof v.id === "string" ? v.id : null;
  return {
    id: id ?? "",
    name: typeof v.name === "string" ? v.name : null,
    avatar_url: typeof v.avatar_url === "string" && v.avatar_url ? v.avatar_url : null,
    avatar_version: typeof v.avatar_version === "number" && Number.isFinite(v.avatar_version) ? v.avatar_version : null,
    avatar_color: typeof v.avatar_color === "number" && Number.isInteger(v.avatar_color) ? v.avatar_color : null,
  };
}

/** The person whose face an item shows in its icon's place, or null (the kind's icon). */
export function shownPerson(item: Pick<ActivityItem, "kind" | "actor">): ActivityActor | null {
  return item.kind && PERSON_KINDS.has(item.kind) ? personOf(item.actor) : null;
}

/** Their colour: the server's, else its own rule (the id modulo 8), else null (the window seeds one from the name). */
export function personColour(person: Pick<ActivityActor, "id" | "avatar_color">): number | null {
  const sent = person.avatar_color;
  if (typeof sent === "number" && Number.isInteger(sent) && sent >= 0 && sent < COLOURS) return sent;
  const id = typeof person.id === "number" ? person.id : typeof person.id === "string" && /^\d+$/.test(person.id) ? Number(person.id) : NaN;
  return Number.isSafeInteger(id) && id >= 0 ? id % COLOURS : null;
}
