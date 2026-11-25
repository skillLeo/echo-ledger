// What the shell keeps on this computer for each account (its layout and lists: the panels shown, their widths, the
// projects' sort and Recent). `account` is the server, the email and the workspace, as for My views. While the account
// isn't known nothing is read or written. No components, so the window can use it without loading the new design.

/**
 * A choice kept for `account`. What an earlier version kept for the whole computer (`key` alone) can't be told apart
 * by account, so it is removed, unread, once an account is known: every account starts from the defaults.
 */
export function readKept(key: string, account: string | null | undefined): string | null {
  if (!account) return null;
  try {
    if (localStorage.getItem(key) !== null) localStorage.removeItem(key);
    return localStorage.getItem(`${key}:${account}`);
  } catch {
    return null;
  }
}

export function writeKept(key: string, account: string | null | undefined, value: string) {
  if (!account) return;
  try {
    localStorage.setItem(`${key}:${account}`, value);
  } catch {
    // Kept for this session only.
  }
}

/** Whose choices the projects' sort and Recent are (the shell's layoutFor); null until the account is known. */
let projectsAccount: string | null = null;
const PROJECTS_ACCOUNT_EVENT = "echoledger:projects-account";
export const projectsAccountNow = () => projectsAccount;
/** Another account (or the first one known): its own sort and Recent are read from now on. */
export function setProjectsAccount(account: string | null | undefined) {
  const next = account ?? null;
  if (next === projectsAccount) return;
  projectsAccount = next;
  if (typeof window !== "undefined") window.dispatchEvent(new Event(PROJECTS_ACCOUNT_EVENT));
}
export function onProjectsAccount(changed: () => void): () => void {
  window.addEventListener(PROJECTS_ACCOUNT_EVENT, changed);
  return () => window.removeEventListener(PROJECTS_ACCOUNT_EVENT, changed);
}
