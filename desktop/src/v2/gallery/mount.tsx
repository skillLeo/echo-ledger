import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { Gallery } from "./Gallery";
import { galleryParams } from "./guard";

/**
 * Shows the gallery in place of the app (development builds only, see guard.ts). The classic stylesheet that main.tsx
 * loaded is taken out first, so the v2 screens show only the v2 styles.
 */
export function mountGallery(root: HTMLElement, search = location.search) {
  for (const style of document.querySelectorAll("style[data-vite-dev-id]")) {
    if (/[/\\]src[/\\]styles\.css$/.test(style.getAttribute("data-vite-dev-id") ?? "")) style.remove();
  }
  const params = galleryParams(search);
  const html = document.documentElement;
  html.dataset.theme = params.theme;
  html.style.colorScheme = params.theme;
  html.lang = params.lang;
  document.body.classList.add("g-body");
  document.title = `EchoLedger v2: ${params.screen}`;
  createRoot(root).render(
    <StrictMode>
      <Gallery screen={params.screen} lang={params.lang} />
    </StrictMode>,
  );
}
