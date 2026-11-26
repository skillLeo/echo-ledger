// Shared by the Urdu picture scripts.

/**
 * Whether `words` are on the screen, read off the page: a text that holds them, drawn (some size, not hidden), in the
 * window and not scrolled or clipped out of any box it sits in (most of it shown). isVisible() would say yes to a line
 * scrolled out of its list.
 */
export const onScreen = (page, words) => page.evaluate((words) => {
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  for (let node = walker.nextNode(); node; node = walker.nextNode()) {
    if (!node.textContent.includes(words) || !node.parentElement) continue;
    const look = getComputedStyle(node.parentElement);
    if (look.visibility !== "visible" || look.display === "none") continue;
    const range = document.createRange();
    range.selectNodeContents(node);
    const r = range.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) continue;
    let [left, top, right, bottom] = [Math.max(r.left, 0), Math.max(r.top, 0), Math.min(r.right, innerWidth), Math.min(r.bottom, innerHeight)];
    for (let box = node.parentElement; box && box !== document.documentElement; box = box.parentElement) {
      const s = getComputedStyle(box);
      if (s.overflowX === "visible" && s.overflowY === "visible" && s.clipPath === "none") continue;
      const b = box.getBoundingClientRect();
      [left, top, right, bottom] = [Math.max(left, b.left), Math.max(top, b.top), Math.min(right, b.right), Math.min(bottom, b.bottom)];
    }
    if (right - left >= r.width * 0.6 && bottom - top >= r.height * 0.6) return true;
  }
  return false;
}, words);
