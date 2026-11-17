// Commands the new recording controls need that the core does not have yet. Until it does, they fail with the core's
// "unknown command" error, which the controls show as their one-line error.
//   screens_capture_now              → RecordingState   keep one screen moment now (the pill's Capture now)
//   pending_keep_audio { id }        → void             keep the call as audio only: upload it, do not transcribe it
import { invoke } from "@tauri-apps/api/core";
import type { RecordingState } from "../../lib/ipc";

export const captureNow = () => invoke<RecordingState>("screens_capture_now");
export const keepAudioOnly = (id: string) => invoke<void>("pending_keep_audio", { id });

/** The seconds the after-call card waits before it saves the audio by itself (v1.8 P1.1). */
export const AFTER_CALL_SECONDS = 12;
