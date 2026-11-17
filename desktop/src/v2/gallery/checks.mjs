// The checks the bug bash and the final pass run in a page (page.evaluate): what a person would see as broken.
/** Runs in the page: what looks broken, in words. */
export function audit() {
  const found = [];
  const vw = window.innerWidth;
  const root = document.scrollingElement ?? document.documentElement;
  if (root.scrollWidth > vw + 1) found.push(`page scrolls sideways (${root.scrollWidth} > ${vw})`);
  const label = (el) => {
    const t = (el.getAttribute("aria-label") || el.textContent || "").trim().replace(/\s+/g, " ").slice(0, 60);
    return `${el.tagName.toLowerCase()}.${[...el.classList].slice(0, 2).join(".")} "${t}"`;
  };
  const scrollsX = (el) => {
    for (let p = el.parentElement; p; p = p.parentElement) {
      const o = getComputedStyle(p).overflowX;
      if (o === "auto" || o === "scroll") return true;
    }
    return false;
  };
  const visible = (el) => {
    const r = el.getBoundingClientRect();
    const s = getComputedStyle(el);
    // Hidden for the eye but kept for assistive tools (the rail's names, visually-hidden labels) is not "cut".
    const hiddenForEye = (e) => { for (let p = e; p; p = p.parentElement) if (getComputedStyle(p).clipPath !== "none") return true; return false; };
    return r.width > 0 && r.height > 0 && s.visibility !== "hidden" && s.display !== "none" && !el.closest(".el-visually-hidden") && !hiddenForEye(el);
  };
  // With a modal open, only the modal counts: the page behind it is covered by its scrim.
  const modal = document.querySelector(".el-root [aria-modal=true]");
  const scope = [...(modal ?? document).querySelectorAll(modal ? "*" : ".el-root *")].filter(visible);
  for (const el of scope) {
    const s = getComputedStyle(el);
    const clips = s.overflowX === "hidden" || s.overflowX === "clip";
    if (clips && el.scrollWidth > el.clientWidth + 2 && s.textOverflow !== "ellipsis" && el.textContent.trim() && !el.matches(".el-visually-hidden, .el-progress, .el-dialog, .el-sheet, .el-shell__main, .g-app, .el-shell")) {
      found.push(`cut without an ellipsis: ${label(el)}`);
    }
    const r = el.getBoundingClientRect();
    if ((r.right > vw + 1 || r.left < -1) && !scrollsX(el) && el.children.length === 0 && el.textContent.trim()) found.push(`past the window's edge: ${label(el)}`);
  }
  const controls = scope.filter((el) => el.matches("button, input, select, textarea, [role=switch], [role=combobox]") && !el.matches(".el-row__hit"));
  for (let i = 0; i < controls.length; i++) {
    for (let j = i + 1; j < controls.length; j++) {
      const a = controls[i].getBoundingClientRect();
      const b = controls[j].getBoundingClientRect();
      const w = Math.min(a.right, b.right) - Math.max(a.left, b.left);
      const h = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
      if (w > 2 && h > 2 && !controls[i].contains(controls[j]) && !controls[j].contains(controls[i])) found.push(`controls overlap: ${label(controls[i])} / ${label(controls[j])}`);
    }
  }
  return [...new Set(found)];
}
