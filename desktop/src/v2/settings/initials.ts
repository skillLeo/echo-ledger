/**
 * "AS" for Ayesha Siddiqui; the email's first letter without a name. An Urdu name's two letters would join into one
 * word ("عخ"), so a zero-width non-joiner keeps them two letters, as the web's PersonAvatar does.
 */
export function initials(name: string | null | undefined, email: string | null | undefined): string {
  const words = (name ?? "").trim().split(/\s+/).filter(Boolean);
  if (words.length > 0) {
    const letters = words.slice(0, 2).map((w) => [...w][0]!.toLocaleUpperCase());
    return letters.join(letters.length > 1 && letters.some((l) => /\p{Script=Arabic}/u.test(l)) ? "\u200C" : "");
  }
  return ([...(email ?? "").trim()][0] ?? "?").toLocaleUpperCase();
}
