/** "AS" for Ayesha Siddiqui; the email's first letter without a name. */
export function initials(name: string | null | undefined, email: string | null | undefined): string {
  const words = (name ?? "").trim().split(/\s+/).filter(Boolean);
  if (words.length > 0) return words.slice(0, 2).map((w) => [...w][0]!.toLocaleUpperCase()).join("");
  return ([...(email ?? "").trim()][0] ?? "?").toLocaleUpperCase();
}
