// Send to WhatsApp (v1.6, P5.5), as on the phone: the text of the view being read, the text with the voice, or a link
// that works for 7 days. The computer opens wa.me (WhatsApp, else WhatsApp Web) with the message ready; WhatsApp takes
// no file through a link, so with the voice the audio is saved in Downloads to attach there. The text always goes on
// the clipboard too: a long one is cut in the message (the core says so), and WhatsApp may drop text sent with a file.
import { useState } from "react";
import { api, errorMessage } from "../../lib/ipc";
import { Sheet } from "./ProjectDialogs";
import { CheckIcon } from "../../components/ControlIcons";

/** A link from Send to WhatsApp works this long (the phone's choice). */
export const WHATSAPP_LINK_SECONDS = 7 * 24 * 3600;
export const ATTACH_NOTE = "Attach the saved voice file in WhatsApp";

/** The message for a link: the recording's title on its own line, then the link. */
export function linkMessage(title: string | null, url: string): string {
  const name = title?.trim();
  return name ? `${name}\n${url}` : url;
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
  notify: (text: string, error?: boolean) => void;
  onClose: () => void;
}) {
  const [working, setWorking] = useState<"text" | "voice" | "link" | null>(null);
  const [saved, setSaved] = useState<string | null>(null);

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
    if (cut) notify(copied ? "WhatsApp opens with the start of the text. The whole text is copied: paste it there." : "WhatsApp opens with the start of the text.");
    return cut;
  };

  const sendText = async () => {
    if (!text || working) return;
    setWorking("text");
    try {
      const copied = await copy(text);
      const cut = await open(text, copied);
      if (!cut) notify("Opening WhatsApp with the text");
      onClose();
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setWorking(null);
    }
  };

  const sendVoice = async () => {
    if (!text || working) return;
    setWorking("voice");
    try {
      const path = await api.saveAudioToDownloads(sessionId);
      const copied = await copy(text);
      await open(text, copied);
      setSaved(path);
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setWorking(null);
    }
  };

  const sendLink = async () => {
    if (working) return;
    setWorking("link");
    try {
      const link = await api.createShare(sessionId, WHATSAPP_LINK_SECONDS, null, hasAudio);
      if (!link.url) throw new Error("The server made the link but did not send it. Try again.");
      await copy(link.url);
      await open(linkMessage(title, link.url), false);
      notify("Link made (it works for 7 days) and copied");
      onClose();
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setWorking(null);
    }
  };

  return (
    <Sheet title="Send to WhatsApp" onClose={onClose} busy={working !== null} className="whatsapp-sheet">
      {saved ? (
        <div className="whatsapp-done" aria-live="polite">
          <p className="whatsapp-saved"><CheckIcon size={16} />Saved in Downloads: <b dir="auto">{fileName(saved)}</b></p>
          <p><b>{ATTACH_NOTE}</b>: in the chat, click <b>+</b> (or the paper clip), then <b>Document</b>, and choose this file. The text is in the message, and copied too.</p>
          <div className="sheet-actions"><span className="spacer" /><button type="button" className="btn primary" onClick={onClose}>Done</button></div>
        </div>
      ) : (
        <>
          <p className="small muted">Opens WhatsApp, or WhatsApp Web, with the message ready to send to the person you choose.</p>
          {!text && <p className="small muted" data-testid="whatsapp-no-text">There is no transcript yet: only the link can be sent.</p>}
          <div className="whatsapp-options">
            <button type="button" className="btn primary whatsapp-option" disabled={!text || working !== null} onClick={() => void sendText()}>
              {working === "text" ? "Opening WhatsApp…" : "Text only"}
            </button>
            <span className="small muted">The text of the view you are reading.</span>
            {hasAudio && (
              <>
                <button type="button" className="btn whatsapp-option" disabled={!text || working !== null} onClick={() => void sendVoice()}>
                  {working === "voice" ? "Saving the voice…" : "Text and voice"}
                </button>
                <span className="small muted">The text in the message; the voice saved in Downloads to attach.</span>
              </>
            )}
            <button type="button" className="btn whatsapp-option" disabled={working !== null} onClick={() => void sendLink()}>
              {working === "link" ? "Making the link…" : "Link (works for 7 days)"}
            </button>
            <span className="small muted">Anyone with it can read{hasAudio ? " and listen" : ""} for 7 days.</span>
          </div>
          <div className="sheet-actions"><span className="spacer" /><button type="button" className="btn ghost" onClick={onClose}>Cancel</button></div>
        </>
      )}
    </Sheet>
  );
}
