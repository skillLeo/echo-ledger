// The microphone a recording opens and the Bluetooth notice (v1.5), unit-tested in microphone.test.ts.
import type { InputDevice } from "./ipc";

/** The notice shown when the recording microphone is a Bluetooth headset or earbuds. */
export const BLUETOOTH_NOTE = "Accuracy is lower with Bluetooth mics";

/** The microphone a recording opens (same rule as the core): the chosen one while connected, else the system default. */
export function micInUse(devices: readonly InputDevice[], chosen: string | null | undefined): InputDevice | null {
  const picked = chosen ? devices.find((d) => d.id === chosen) : undefined;
  return picked ?? devices.find((d) => d.isDefault) ?? null;
}

/** The Bluetooth microphone a recording would use, or null (no devices known, or a wired one). */
export function bluetoothMic(devices: readonly InputDevice[], chosen: string | null | undefined): InputDevice | null {
  const mic = micInUse(devices, chosen);
  return mic?.bluetooth ? mic : null;
}
