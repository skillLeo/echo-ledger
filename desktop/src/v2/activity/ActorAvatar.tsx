// The person an Activity item is about (contract 2.0.0-aa, UX-18) in the place of the kind's icon, for the kinds that
// show a face (lib/activityPerson.ts): their picture, else their initials in their colour. A picture the server no
// longer gives (403, 404), a call that fails, or a picture that won't draw shows the initials quietly. The row's title
// names them, so the avatar itself is hidden from assistive tools.
import { useEffect, useState } from "react";
import { api, type ActivityActor } from "../../lib/ipc";
import { personColour } from "../../lib/activityPerson";
import { Avatar } from "../components";
import { initials } from "../settings/initials";

/** Seed for the colour when neither the server's nor the id's rule gives one: the same person, the same colour. */
export const actorSeed = (actor: Pick<ActivityActor, "id" | "name">) => (actor.name?.trim() || String(actor.id ?? ""));

/**
 * One request per picture for the whole window, kept by where it lives (the server, the person and the version, the
 * signature left out, as the core keeps its copy), a picture the server no longer gives (null: 403, 404, 410) included:
 * thirty shares from one teammate ask once. A call that fails (offline, a timeout, the server busy, locked) is not
 * kept, so it is asked again later. Forgotten at each sign-in and sign-out, as the core forgets its copies.
 */
const pictures = new Map<string, Promise<string | null>>();

const pictureKey = (person: ActivityActor | null): string | null => {
  if (!person?.avatar_url || person.avatar_version == null) return null;
  try {
    const at = new URL(person.avatar_url);
    return `${at.origin}${at.pathname}#${person.avatar_version}`;
  } catch {
    return null;
  }
};

function pictureOf(key: string, person: ActivityActor): Promise<string | null> {
  const known = pictures.get(key);
  if (known) return known;
  const asked = api.avatarImage(person.avatar_url, person.avatar_version!).then((src) => src ?? null, () => {
    pictures.delete(key);
    return null;
  });
  pictures.set(key, asked);
  return asked;
}

/** Forget the pictures asked for: another sign-in, or none (MainApp), and tests. */
export function forgetPersonPictures() {
  pictures.clear();
}

/** The person's picture as the window draws it, or null (their initials). */
export function usePersonPhoto(person: ActivityActor | null): string | null {
  const key = pictureKey(person);
  const [shown, setShown] = useState<{ key: string; src: string | null } | null>(null);
  useEffect(() => {
    if (!key || !person) return;
    let live = true;
    void pictureOf(key, person).then((src) => {
      if (live) setShown({ key, src });
    });
    return () => {
      live = false;
    };
    // The key holds everything the picture depends on.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);
  return shown && shown.key === key ? shown.src : null;
}

export function ActorAvatar({ actor }: { actor: ActivityActor }) {
  const photo = usePersonPhoto(actor);
  return <Avatar size="menu" photo={photo} color={personColour(actor)} seed={actorSeed(actor)} initials={initials(actor.name, null)} />;
}
