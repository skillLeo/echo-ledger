// Native notifications from the main window, for work that finishes while the person looks elsewhere: a brief or call
// notes Claude wrote, a client file that was read, an answer to Ask. The core shows them through their category
// (notifications.rs, Phase 1C): its switch, its sound and the quiet hours. The activity centre's are sent by the core.
import { api, type NotificationCategory } from "./ipc";

/** Shows an OS notification when the window is not in front; false when it was not shown. */
export async function notifyWhenAway(title: string, body: string, category: NotificationCategory = "enhancement"): Promise<boolean> {
  if (typeof document !== "undefined" && document.hasFocus() && document.visibilityState === "visible") return false;
  try {
    return await api.notificationSend(category, title, body);
  } catch {
    // Outside Tauri (the browser preview) there is no core to show it.
    return false;
  }
}
