// The app's own messages in the new design (classic's toast: views/main/MainApp's toastView): one at a time, an error
// read out at once, gone after a while unless the pointer or the keyboard is on it; a message about something is itself
// the button that goes there (classic's), and Esc (on it) or × closes it. The two live regions (polite, and assertive
// for errors) stay in the page from the start, so screen readers hear a message the moment its words come in.
import { Toast } from "../components";

export type AppMessage = { id: number; text: string; error: boolean; onOpen?: () => void };

export function AppToastV2({ message, onClose, duration }: {
  /** The message on screen, or none. */
  message: AppMessage | null;
  onClose: () => void;
  duration: (message: AppMessage) => number;
}) {
  const toast = (m: AppMessage) => (
    <div className="el-app-toast__item" data-testid="app-toast" key={m.id}>
      <Toast message={m.onOpen ? <button type="button" className="el-toast__open" onClick={() => { m.onOpen!(); onClose(); }}>{m.text}</button> : m.text}
        undo={false} error={m.error} onClose={onClose} duration={duration(m)} />
    </div>
  );
  return (
    <>
      <div className="el-toast-region el-app-toast" role="status" aria-live="polite" aria-label="Messages" data-testid="app-messages">
        {message && !message.error && toast(message)}
      </div>
      <div className="el-toast-region el-app-toast el-app-toast--error" role="alert" aria-live="assertive" aria-label="Problems" data-testid="app-problems">
        {message?.error && toast(message)}
      </div>
    </>
  );
}
