// Native notifications from the main window, for work that finishes while the person looks elsewhere: a brief or call
// notes Claude wrote, a client file that was read, an answer to Ask. The activity centre's OS notifications are sent by
// the core (activity.rs); these are only for jobs this window started and is waiting on.
import { isPermissionGranted, requestPermission, sendNotification } from "@tauri-apps/plugin-notification";

/** Shows an OS notification when the window is not in front; false when it was not shown. */
export async function notifyWhenAway(title: string, body: string): Promise<boolean> {
  if (typeof document !== "undefined" && document.hasFocus() && document.visibilityState === "visible") return false;
  try {
    let allowed = await isPermissionGranted();
    if (!allowed) allowed = (await requestPermission()) === "granted";
    if (allowed) sendNotification({ title, body });
    return allowed;
  } catch {
    // Outside Tauri (tests, the browser preview) there is no notification plugin.
    return false;
  }
}
