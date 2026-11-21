// The v2 gallery opens only from a development build with ?v2gallery in the address; main.tsx checks both inline
// and imports nothing from here, so a release build has none of the gallery (release.test.ts builds one to check).

export type GalleryParams = {
  screen: "library" | "recording" | "settings" | "assist" | "plan" | "kit";
  theme: "light" | "dark";
  lang: "en" | "ur";
  /** The screen as it is, or its loading, empty or error state (template rule 12). */
  state: "ready" | "loading" | "empty" | "error";
};

/** ?v2gallery&screen=library|recording|settings|assist|plan|kit&theme=light|dark&lang=en|ur&state=ready|loading|empty|error (text size: the browser's zoom). */
export function galleryParams(search: string): GalleryParams {
  const p = new URLSearchParams(search);
  const screen = p.get("screen");
  return {
    screen: screen === "recording" || screen === "settings" || screen === "assist" || screen === "plan" || screen === "kit" ? screen : "library",
    theme: p.get("theme") === "dark" ? "dark" : "light",
    lang: p.get("lang") === "ur" ? "ur" : "en",
    state: (["loading", "empty", "error"] as const).find((v) => v === p.get("state")) ?? "ready",
  };
}
