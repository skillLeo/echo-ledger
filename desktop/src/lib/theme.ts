// Light / dark / system theme for every window. The choice is stored locally (never sent anywhere) and shared by the
// windows of the app (same origin), so the caption overlay, pill, review card and dictation bar follow the main window.

export type ThemeChoice = "system" | "light" | "dark";
export type ResolvedTheme = "light" | "dark";

const KEY = "echoledger-theme";

export function resolveTheme(choice: ThemeChoice, systemPrefersLight: boolean): ResolvedTheme {
  if (choice === "system") return systemPrefersLight ? "light" : "dark";
  return choice;
}

export function getThemeChoice(): ThemeChoice {
  try {
    const v = window.localStorage.getItem(KEY);
    return v === "light" || v === "dark" || v === "system" ? v : "system";
  } catch {
    return "system";
  }
}

export function nextThemeChoice(choice: ThemeChoice): ThemeChoice {
  return choice === "system" ? "light" : choice === "light" ? "dark" : "system";
}

export function applyTheme(choice: ThemeChoice): ResolvedTheme {
  const prefersLight = typeof window.matchMedia === "function" && window.matchMedia("(prefers-color-scheme: light)").matches;
  const resolved = resolveTheme(choice, prefersLight);
  document.documentElement.dataset.theme = resolved;
  document.documentElement.style.colorScheme = resolved;
  return resolved;
}

export function saveThemeChoice(choice: ThemeChoice): void {
  try {
    window.localStorage.setItem(KEY, choice);
  } catch {
    // Storage can be unavailable; the choice then lasts until the app restarts.
  }
}

/** Re-applies the theme when the OS appearance changes while "system" is selected, and when another window changes the choice. */
export function watchSystemTheme(): void {
  window.addEventListener("storage", (e) => {
    if (e.key === KEY || e.key === null) applyTheme(getThemeChoice());
  });
  if (typeof window.matchMedia !== "function") return;
  window.matchMedia("(prefers-color-scheme: light)").addEventListener("change", () => {
    if (getThemeChoice() === "system") applyTheme("system");
  });
}
