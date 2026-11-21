// The app's own messages in the new design (classic's toast: views/main/MainApp's toastView): one at a time, an error
// read out at once, gone after a while unless the pointer or the keyboard is on it; a message about something is itself
// the button that goes there (classic's), and Esc (on it) or × closes it.
import { Toast } from "../components";

export function AppToastV2({ text, error, onOpen, onClose, duration }: {
  text: string;
  error: boolean;
  /** Where the message is about (a recording, a project, Activity): pressing the message goes there. */
  onOpen?: () => void;
  onClose: () => void;
  duration: number;
}) {
  return (
    <div className={`el-toast-region el-app-toast${error ? " el-app-toast--error" : ""}`} role={error ? "alert" : "status"} aria-live={error ? "assertive" : "polite"}
      aria-label="Messages" data-testid="app-toast">
      <Toast message={onOpen ? <button type="button" className="el-toast__open" onClick={() => { onOpen(); onClose(); }}>{text}</button> : text}
        undo={false} onClose={onClose} duration={duration} />
    </div>
  );
}
