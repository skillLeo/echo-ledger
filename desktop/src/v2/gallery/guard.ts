// The v2 gallery opens only from a development build with ?v2gallery in the address. main.tsx tests
// `import.meta.env.DEV` inline before calling this, so a release build drops the gallery and this check entirely.

/** Whether the address asks for the v2 gallery (development builds only; main.tsx checks the build first). */
export function wantsV2Gallery(search: string): boolean {
  return new URLSearchParams(search).has("v2gallery");
}

export type GalleryParams = {
  screen: "library" | "recording" | "settings";
  theme: "light" | "dark";
  lang: "en" | "ur";
};

/** ?v2gallery&screen=library|recording|settings&theme=light|dark&lang=en|ur (text size: the browser's zoom). */
export function galleryParams(search: string): GalleryParams {
  const p = new URLSearchParams(search);
  const screen = p.get("screen");
  return {
    screen: screen === "recording" || screen === "settings" ? screen : "library",
    theme: p.get("theme") === "dark" ? "dark" : "light",
    lang: p.get("lang") === "ur" ? "ur" : "en",
  };
}
