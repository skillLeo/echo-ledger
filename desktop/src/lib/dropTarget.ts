// Files dropped on the main window open the import sheet (MainApp), unless a page claims them for itself, like a
// client's Files tab, which adds PDF, Word, text and Markdown files to the workspace instead.
type DropHandler = (paths: string[]) => void;

let claimed: DropHandler | null = null;

/** Takes file drops for this page until the returned release is called. */
export function claimFileDrops(handler: DropHandler): () => void {
  claimed = handler;
  return () => {
    if (claimed === handler) claimed = null;
  };
}

/** The page that takes drops now, if any. */
export function fileDropClaim(): DropHandler | null {
  return claimed;
}
