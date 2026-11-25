// The person an Activity item is about (contract 2.0.0-aa, UX-18) in the place of the kind's icon: their picture,
// kept on this computer by their version like every avatar (api.avatarImage), else their initials in their colour. A
// picture the server no longer gives (403, 404) shows the initials quietly. The row's title names them, so the avatar
// itself is hidden from assistive tools.
import type { ActivityActor } from "../../lib/ipc";
import { useAvatar } from "../../views/main/AccountFlows";
import { Avatar } from "../components";
import { initials } from "../settings/initials";

/** Seed for the colour when the server sent none: the same person always gets the same one. */
export const actorSeed = (actor: Pick<ActivityActor, "id" | "name">) => (actor.name?.trim() || String(actor.id ?? ""));

export function ActorAvatar({ actor }: { actor: ActivityActor }) {
  const photo = useAvatar(actor);
  return <Avatar size="menu" photo={photo} color={actor.avatar_color} seed={actorSeed(actor)} initials={initials(actor.name, null)} />;
}
