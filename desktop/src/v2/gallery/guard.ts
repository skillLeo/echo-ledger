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
  /** Text size in percent (100, 130, 200): the page is zoomed, as the app is when the system text grows. */
  text: number;
};

/** ?v2gallery&screen=library|recording|settings&theme=light|dark&lang=en|ur&text=100|130|200 */
export function galleryParams(search: string): GalleryParams {
  const p = new URLSearchParams(search);
  const screen = p.get("screen");
  const text = Number(p.get("text"));
  return {
    screen: screen === "recording" || screen === "settings" ? screen : "library",
    theme: p.get("theme") === "dark" ? "dark" : "light",
    lang: p.get("lang") === "ur" ? "ur" : "en",
    text: [130, 200].includes(text) ? text : 100,
  };
}
