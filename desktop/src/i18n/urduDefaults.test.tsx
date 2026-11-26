// D-257: a shared control's own words, when its caller gives none, are the window's language: the confirm dialog's
// Cancel (an Urdu window showed "Cancel" beside ریکارڈ نہ کریں), a banner's and a toast's ✕, a toast's Undo, Back, the
// toast region and the breadcrumbs' names, Delete list…'s words. A caller's own words still win; English (and classic,
// which is always English) reads as before.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { resetLanguageForTests, setLanguage, t } from "./index";
import { ConfirmDialog } from "../views/main/SettingsControls";
import { Banner, Breadcrumbs, StickyItemBar, Toast, ToastRegion, deleteListWords } from "../v2/components";

const urdu = async () => {
  resetLanguageForTests();
  await act(() => setLanguage("ur", { root: false }));
};
const isMac = () => /mac/i.test(navigator.platform || navigator.userAgent);
const undoKey = () => fireEvent.keyDown(window, { key: "z", metaKey: isMac(), ctrlKey: !isMac() });

beforeEach(() => resetLanguageForTests({}));
afterEach(() => resetLanguageForTests());

function Defaults() {
  return (
    <>
      <ConfirmDialog title="Don't record calls with Bilal?" confirm="Don't record" onConfirm={() => undefined} onClose={() => undefined} />
      <Banner onClose={() => undefined}>Saved</Banner>
      <StickyItemBar title="Budget review" onBack={() => undefined} />
      <Breadcrumbs crumbs={[{ label: "Library", onOpen: () => undefined }, { label: "Zoom" }]} />
      <ToastRegion><Toast message="Moved to Trash" onAction={() => undefined} onClose={() => undefined} /></ToastRegion>
    </>
  );
}

describe("D-257: a shared control's default words", () => {
  it("English: Cancel, Close, Back, Dismiss, Undo, as before", () => {
    render(<Defaults />);
    expect(screen.getByRole("button", { name: "Cancel" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Close" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Back" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Dismiss" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Undo" })).toBeInTheDocument();
    expect(screen.getByLabelText("Notifications")).toBeInTheDocument();
    expect(screen.getByLabelText("Breadcrumbs")).toBeInTheDocument();
    expect(deleteListWords("Clients")).toMatchObject({ title: "Delete Clients?", confirm: "Delete", cancel: "Cancel" });
  });

  it("Urdu: in the window's language (منسوخ کریں beside the dialog's own button)", async () => {
    await urdu();
    render(<Defaults />);
    expect(screen.getByRole("button", { name: "منسوخ کریں" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Cancel" })).toBeNull();
    expect(screen.getByRole("button", { name: "بند کریں" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "واپس جائیں" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "ہٹا دیں" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "واپس کریں" })).toBeInTheDocument();
    expect(screen.getByLabelText("اطلاعات")).toBeInTheDocument();
    expect(screen.getByLabelText("راستہ")).toBeInTheDocument();
    expect(deleteListWords("Clients")).toMatchObject({ title: t("Delete :name?", { name: "Clients" }), confirm: "حذف کریں", cancel: "منسوخ کریں" });
  });

  it("Urdu: a caller's own words still win", async () => {
    await urdu();
    render(
      <>
        <ConfirmDialog title="Sign out?" cancel="Keep me in" confirm="Sign out" onConfirm={() => undefined} onClose={() => undefined} />
        <Banner onClose={() => undefined} closeLabel="Hide this">Saved</Banner>
        <StickyItemBar title="Budget review" onBack={() => undefined} backLabel="Library" />
        <Toast message="Copied" actionLabel="Open" onAction={() => undefined} onClose={() => undefined} closeLabel="Got it" />
      </>,
    );
    for (const name of ["Keep me in", "Hide this", "Library", "Open", "Got it"]) expect(screen.getByRole("button", { name })).toBeInTheDocument();
    expect(deleteListWords("Clients", { confirm: "Remove", cancel: "Keep" })).toMatchObject({ confirm: "Remove", cancel: "Keep" });
  });

  it("Urdu: an Undo toast is still an Undo toast (Cmd+Z / Ctrl+Z), named by the caller or by default", async () => {
    await urdu();
    for (const actionLabel of [undefined, t("Undo")]) {
      const onAction = vi.fn();
      const view = render(<Toast message="ردی میں چلی گئی" actionLabel={actionLabel} onAction={onAction} onClose={() => undefined} />);
      expect(screen.getByRole("button", { name: "واپس کریں" })).toHaveAttribute("aria-keyshortcuts");
      undoKey();
      expect(onAction).toHaveBeenCalledTimes(1);
      view.unmount();
    }
    // Another action is not Undo: no shortcut.
    const other = vi.fn();
    render(<Toast message="Copied" actionLabel={t("Open")} onAction={other} onClose={() => undefined} />);
    undoKey();
    expect(other).not.toHaveBeenCalled();
  });
});
