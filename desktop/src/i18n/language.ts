// Which language the desktop speaks (desktop Urdu, Phase 0). English unless Urdu is on: the server's v21_urdu flag, in
// the new design only (not classic, the v2 shell on). Then:
//   - signed in, the account's (`language` on GET /api/account, the same setting as the web console's and the phone's,
//     BE-06); while the account is still loading or can't be read, the last one known on this computer; never the
//     system's;
//   - signed out, the system's (the server's flags aren't read then, so whether Urdu is on is the last answer known on
//     this computer).
// Settings › Appearance › Language changes it on the server (PATCH /api/account), so it changes everywhere.
//
// The app's other windows (the Live Translate overlay) have no account of their own: they speak the main window's
// language, from what it keeps on this computer (useKeptLanguage below).
import { useEffect, useRef } from "react";
import type { Account } from "../lib/ipc";
import { language, setLanguage, useLanguage, type Lang } from "./index";

/**
 * The system's language: Urdu when the person's first language is Urdu (ur, ur-PK, ur-IN), else English. Checked on a
 * Mac (macOS 27) with Urdu first: the web view says "ur" though the app has no Urdu localisation of its own, so the
 * core isn't asked.
 */
export function systemLanguage(first: string | undefined = typeof navigator !== "undefined" ? navigator.languages?.[0] ?? navigator.language : undefined): Lang {
  return (first ?? "").toLowerCase().startsWith("ur") ? "ur" : "en";
}

/** The account's language, when it says one this app speaks. */
export function accountLanguage(account: Pick<Account, "language"> | null | undefined): Lang | null {
  const lang = account?.language;
  return lang === "en" || lang === "ur" ? lang : null;
}

/** What this computer keeps: whether Urdu was on at the last answer of the server's, and the account's last language. */
export type KeptLanguage = { on: boolean; account: Lang | null };
export const KEPT_LANGUAGE_KEY = "echoledger-language";

export function readKeptLanguage(): KeptLanguage {
  try {
    const kept = JSON.parse(localStorage.getItem(KEPT_LANGUAGE_KEY) ?? "null") as Partial<KeptLanguage> | null;
    return { on: kept?.on === true, account: kept?.account === "en" || kept?.account === "ur" ? kept.account : null };
  } catch {
    return { on: false, account: null };
  }
}

function keepLanguage(change: Partial<KeptLanguage>) {
  const kept = { ...readKeptLanguage(), ...change };
  try {
    localStorage.setItem(KEPT_LANGUAGE_KEY, JSON.stringify(kept));
  } catch {
    // Storage is off: the next start waits for the account, in English.
  }
}

/**
 * The language to speak (pure). English with Urdu off. Signed out, the system's. Signed in, the account's, else the one
 * kept on this computer, else English (the system's never decides for an account).
 */
export function chosenLanguage({ on, signedIn, account, kept, system = systemLanguage() }: {
  on: boolean;
  signedIn: boolean;
  /** The account: undefined while it loads, null when it couldn't be read. */
  account: Pick<Account, "language"> | null | undefined;
  /** The account's language last known on this computer. */
  kept: Lang | null;
  system?: Lang;
}): Lang {
  if (!on) return "en";
  if (!signedIn) return system;
  return accountLanguage(account) ?? kept ?? "en";
}

/**
 * The main window follows the choice. `urdu` is whether Urdu is on (v21_urdu in the new design), `known` whether the
 * server's flags have been read, `signedIn` null until the core says. Signed in, nothing changes until the flags are
 * known (the window may still be drawing the classic shell); signed out, the last answer kept here stands. Turned off
 * again, back to English; while Urdu stays off, <html> is left as it is.
 */
export function useFollowLanguage(urdu: boolean, known: boolean, signedIn: boolean | null, account: Pick<Account, "language"> | null | undefined) {
  const followed = useRef(false);
  const kept = readKeptLanguage();
  const on = signedIn === null ? null : signedIn ? (known ? urdu : null) : kept.on;
  const fromAccount = accountLanguage(account);
  // Kept for the next start and the next sign-out: the server's answer (only while signed in: signed out, the flags
  // aren't read again) and the account's language whenever it is read or changed.
  useEffect(() => {
    if (signedIn && known) keepLanguage({ on: urdu });
  }, [signedIn, known, urdu]);
  useEffect(() => {
    if (signedIn && fromAccount) keepLanguage({ account: fromAccount });
  }, [signedIn, fromAccount]);
  const want = on === null || signedIn === null ? null : chosenLanguage({ on, signedIn, account, kept: kept.account });
  useEffect(() => {
    if (want === null || on === null) return;
    if (!on && !followed.current) return;
    followed.current = on;
    if (language() !== want || on) void setLanguage(want);
  }, [want, on]);
}

/**
 * The language of a window that isn't the main one, from what the main window keeps on this computer (readKeptLanguage:
 * whether Urdu is on, from the server's flags in the new design, and the account's language): the account's while Urdu
 * is on, else English. Such a window shows only while signed in (Live Translate runs for the account), so the system's
 * language never decides, as chosenLanguage signed in.
 */
export function keptWindowLanguage(kept: KeptLanguage = readKeptLanguage()): Lang {
  return chosenLanguage({ on: kept.on, signedIn: true, account: null, kept: kept.account });
}

/**
 * A window that isn't the main one (the Live Translate overlay) speaks the main window's language: what it keeps on this
 * computer when the window opens, and again whenever the main window keeps another (Settings › Appearance › Language,
 * the account read again, the new design turned off). Every window of the app shares the same storage (one origin), so
 * the main window's write reaches this one as a `storage` event, as the theme does (lib/theme.ts). The window's <html
 * lang dir> follow, and every useT() renders again; returns the language now.
 */
export function useKeptLanguage(): Lang {
  useEffect(() => {
    const follow = () => void setLanguage(keptWindowLanguage());
    follow();
    const fromAnother = (e: StorageEvent) => {
      if (e.key === KEPT_LANGUAGE_KEY || e.key === null) follow();
    };
    window.addEventListener("storage", fromAnother);
    return () => window.removeEventListener("storage", fromAnother);
  }, []);
  return useLanguage();
}
