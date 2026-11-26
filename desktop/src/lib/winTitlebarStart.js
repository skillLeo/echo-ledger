// The main window's first paint on Windows with its own title row (UX-20, v21_win_titlebar). The core runs this before
// the page only when the window is built without the system's title bar (wintitle.rs), so the page's first frame already
// draws its row with the window's buttons: never a frame with no way to move, minimize or close the window.
(() => {
  const mark = (root) => {
    root.dataset.titlebar = "win";
  };
  if (document.documentElement) {
    mark(document.documentElement);
    return;
  }
  const wait = new MutationObserver(() => {
    if (!document.documentElement) return;
    wait.disconnect();
    mark(document.documentElement);
  });
  wait.observe(document, { childList: true });
})();
