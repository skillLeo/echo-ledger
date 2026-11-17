// The recording controls' commands (lib/ipc.ts): keep one screen moment now (the pill's Capture now), and keep a call
// as audio only (uploaded, not transcribed).
import { api } from "../../lib/ipc";

export const captureNow = () => api.screensCaptureNow();
export const keepAudioOnly = (id: string) => api.pendingKeepAudio(id);

/** The seconds the after-call card waits before it saves the audio by itself (v1.8 P1.1). */
export const AFTER_CALL_SECONDS = 12;
