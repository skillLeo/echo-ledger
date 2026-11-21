// Send to WhatsApp (v1.6, P5.5), as on the phone: the text of the view being read, the text with the voice, or a link
// that works for 7 days. The computer opens wa.me (WhatsApp, else WhatsApp Web) with the message ready, and the text
// goes on the clipboard too (a long one is cut in the message; the core says so).
// Text and voice (F6): the core saves the audio in Downloads and hands the text and the file to the system's share
// (WhatsApp's). Without it, wa.me opens with the text and the file is put on the clipboard, so pasting in the chat
// attaches it; the text is then not copied over it (it is in the message; when cut, a button copies the whole text).
// While one way runs, only its button shows it; the others look as they are and a click on them is ignored.
import { useRef, useState } from "react";
import { api, errorMessage, type WhatsAppVoiceOutcome } from "../../lib/ipc";
import { Sheet } from "./ProjectDialogs";
import { CheckIcon } from "../../components/ControlIcons";
import { NO_TARGET, type Notify } from "../../lib/notice";

/** A link from Send to WhatsApp works this long (the phone's choice). */
export const WHATSAPP_LINK_SECONDS = 7 * 24 * 3600;
export const ATTACH_NOTE = "Attach the saved voice file in WhatsApp";

/** The message for a link: the recording's title on its own line, then the link. */
export function linkMessage(title: string | null, url: string): string {
  const name = title?.trim();
  return name ? `${name}\n${url}` : url;
}

/** The line under a voice put on the clipboard: how to attach it in the chat (`keys`: ⌘V or Ctrl+V, from the core). */
export function pasteNote(keys: string): string {
  return `The voice file is copied: in the chat, press ${keys} to attach it.`;
}

/** The file's name from a saved path (either kind of slash). */
export function fileName(path: string): string {
  return path.split(/[\\/]/).filter(Boolean).pop() ?? path;
}

export function WhatsAppSheet({ sessionId, title, hasAudio, text, notify, onClose }: {
  sessionId: string;
  title: string | null;
  /** The recording's audio is on the server (Text and voice). */
  hasAudio: boolean;
  /** The text of the view being read; null while there is no transcript. */
  text: string | null;
  notify: Notify;
  onClose: () => void;
}) {
  const [working, setWorking] = useState<"text" | "voice" | "link" | null>(null);
  // Set at once on the click (state arrives with the next render): a second click in between starts nothing.
  const running = useRef(false);
  const [saved, setSaved] = useState<Extract<WhatsAppVoiceOutcome, { kind: "fallback" }> | null>(null);
  const [copiedWhole, setCopiedWhole] = useState(false);

  const copy = async (value: string) => {
    try {
      await navigator.clipboard.writeText(value);
      return true;
    } catch {
      return false;
    }
  };
  /** Opens WhatsApp with `message`; says so, and that the whole text is on the clipboard when it had to be cut. */
  const open = async (message: string, copied: boolean) => {
    const cut = await api.whatsappOpen(message);
    if (cut) notify(copied ? "WhatsApp opens with the start of the text. The whole text is copied: paste it there." : "WhatsApp opens with the start of the text.", false, NO_TARGET);
    return cut;
  };

  /** Runs one way at a time: a click while another runs is ignored. */
  const run = async (kind: "text" | "voice" | "link", fn: () => Promise<void>) => {
    if (running.current) return;
    running.current = true;
    setWorking(kind);
    try {
      await fn();
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      running.current = false;
      setWorking(null);
    }
  };

  const sendText = () => run("text", async () => {
    if (!text) return;
    const copied = await copy(text);
    const cut = await open(text, copied);
    if (!cut) notify("Opening WhatsApp with the text", false, NO_TARGET);
    onClose();
  });

  const sendVoice = () => run("voice", async () => {
    if (!text) return;
    const outcome = await api.whatsappShareVoice(sessionId, text);
    if (outcome.kind === "shared") {
      notify("Text and voice sent to WhatsApp", false, NO_TARGET);
      onClose();
    } else if (outcome.kind === "fallback") {
      // The clipboard holds the file when it could be put there; otherwise the text, as before.
      if (!outcome.fileOnClipboard) await copy(text);
      setSaved(outcome);
    }
    // Cancelled: the person closed the share; the sheet stays as it was.
  });

  const sendLink = () => run("link", async () => {
    const link = await api.createShare(sessionId, WHATSAPP_LINK_SECONDS, null, hasAudio);
    if (!link.url) throw new Error("The server made the link but did not send it. Try again.");
    await copy(link.url);
    await open(linkMessage(title, link.url), false);
    notify("Link made (it works for 7 days) and copied", false, { kind: "shared", tab: "links" });
    onClose();
  });

  const copyWhole = async () => {
    if (text && (await copy(text))) setCopiedWhole(true);
  };

  return (
    <Sheet title="Send to WhatsApp" onClose={onClose} busy={working !== null} className="whatsapp-sheet">
      {saved ? (
        <div className="whatsapp-done" aria-live="polite">
          <p className="whatsapp-saved"><CheckIcon size={16} />Saved in Downloads: <b dir="auto">{fileName(saved.path)}</b></p>
          {saved.fileOnClipboard ? (
            <p data-testid="whatsapp-paste"><b>{pasteNote(saved.pasteKeys)}</b> The text is in the message.</p>
          ) : (
            <p><b>{ATTACH_NOTE}</b>: in the chat, click <b>+</b> (or the paper clip), then <b>Document</b>, and choose this file. The text is in the message, and copied too.</p>
          )}
          {saved.textCut && (
            <p className="small muted" data-testid="whatsapp-cut">
              The message has the start of the text: it was too long to send whole. After attaching the voice, copy the whole text and paste it there.
            </p>
          )}
          <div className="row sheet-actions">
            {saved.textCut && saved.fileOnClipboard && (
              <button type="button" className="btn" onClick={() => void copyWhole()}>{copiedWhole ? "Copied" : "Copy the whole text"}</button>
            )}
            <span className="spacer" />
            <button type="button" className="btn primary" onClick={onClose}>Done</button>
          </div>
        </div>
      ) : (
        <>
          <p className="small muted">Opens WhatsApp, or WhatsApp Web, with the message ready to send to the person you choose.</p>
          {!text && <p className="small muted" data-testid="whatsapp-no-text">There is no transcript yet: only the link can be sent.</p>}
          <div className="whatsapp-options">
            <button type="button" className="btn primary whatsapp-option" disabled={!text} aria-busy={working === "text" || undefined} onClick={() => void sendText()}>
              {working === "text" ? "Opening WhatsApp…" : "Text only"}
            </button>
            <span className="small muted">The text of the view you are reading.</span>
            {hasAudio && (
              <>
                <button type="button" className="btn whatsapp-option" disabled={!text} aria-busy={working === "voice" || undefined} onClick={() => void sendVoice()}>
                  {working === "voice" ? "Saving the voice…" : "Text and voice"}
                </button>
                <span className="small muted">The text and the voice, sent through WhatsApp's share (also saved in Downloads).</span>
              </>
            )}
            <button type="button" className="btn whatsapp-option" aria-busy={working === "link" || undefined} onClick={() => void sendLink()}>
              {working === "link" ? "Making the link…" : "Link (works for 7 days)"}
            </button>
            <span className="small muted">Anyone with it can read{hasAudio ? " and listen" : ""} for 7 days.</span>
          </div>
          <div className="row sheet-actions"><span className="spacer" /><button type="button" className="btn ghost" onClick={onClose}>Cancel</button></div>
        </>
      )}
    </Sheet>
  );
}
