// The main window's first paint on a Mac (D-214). The core runs this in the main window before its page (a WebKit user
// script at document start, lifecycle.rs build_main), so the page's first frame already leaves the window's buttons
// their strip: nothing is drawn under them and then moved down. The strip is the normal window's; whether the window is
// full screen is the page's to ask (lib/titlebar.ts), and it keeps its last answer here for the page that comes after a
// reload. A new window is never full screen when it is built, so its first frame has the strip.
(() => {
  const mark = (root) => {
    root.dataset.platform = "macos";
    let kept = null;
    try {
      kept = window.sessionStorage.getItem("echoledger-titlebar");
    } catch {
      // Storage off: the normal window's strip.
    }
    if (kept !== "none") root.dataset.titlebar = "strip";
  };
  // WebKit runs this once <html> exists; an engine that runs it earlier gets it as soon as <html> is made, which is
  // still before anything is drawn.
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
