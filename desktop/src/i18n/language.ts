// Which language the desktop speaks (desktop Urdu, Phase 0): English unless the server's v21_urdu flag is on; then the
// account's (`language` on GET /api/account, the same setting as the web console's and the phone's, BE-06), and the
// system's while signed out or with a server that doesn't say. Settings › Appearance › Language changes it on the
// server (PATCH /api/account), so it changes everywhere.
import { useEffect, useRef } from "react";
import type { Account } from "../lib/ipc";
import { language, setLanguage, type Lang } from "./index";

/** The system's language: Urdu when the person's first language is Urdu (ur, ur-PK, ur-IN), else English. */
export function systemLanguage(first: string | undefined = typeof navigator !== "undefined" ? navigator.languages?.[0] ?? navigator.language : undefined): Lang {
  return (first ?? "").toLowerCase().startsWith("ur") ? "ur" : "en";
}

/** The account's language, when it says one this app speaks. */
export function accountLanguage(account: Pick<Account, "language"> | null | undefined): Lang | null {
  const lang = account?.language;
  return lang === "en" || lang === "ur" ? lang : null;
}

/** The language to speak (pure): English with the flag off; else the account's, else the system's. */
export function chosenLanguage(on: boolean, account: Pick<Account, "language"> | null | undefined, system: Lang = systemLanguage()): Lang {
  if (!on) return "en";
  return accountLanguage(account) ?? system;
}

/**
 * The main window follows the choice: with v21_urdu on, the account's language (the system's until it is read, or when
 * signed out); turned off again, back to English. Until the flags are known, and while the flag stays off, <html> is
 * left as it is.
 */
export function useFollowLanguage(on: boolean, known: boolean, account: Pick<Account, "language"> | null | undefined) {
  const followed = useRef(false);
  const want = known ? chosenLanguage(on, account) : null;
  useEffect(() => {
    if (want === null) return;
    if (!on && !followed.current) return;
    followed.current = on;
    if (language() !== want || on) void setLanguage(want);
  }, [want, on]);
}
