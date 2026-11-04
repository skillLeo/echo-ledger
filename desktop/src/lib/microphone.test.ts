import { describe, expect, it } from "vitest";
import { BLUETOOTH_NOTE, bluetoothMic, micInUse } from "./microphone";
import type { InputDevice } from "./ipc";

const device = (id: string, overrides: Partial<InputDevice> = {}): InputDevice => ({ id, name: id, isDefault: false, bluetooth: false, ...overrides });

describe("the microphone a recording opens (v1.5)", () => {
  const devices = [device("laptop", { isDefault: true }), device("airpods", { name: "AirPods Pro", bluetooth: true })];

  it("is the chosen one while it is connected, else the system default", () => {
    expect(micInUse(devices, "airpods")?.id).toBe("airpods");
    expect(micInUse(devices, "unplugged")?.id).toBe("laptop");
    expect(micInUse(devices, null)?.id).toBe("laptop");
    expect(micInUse([], null)).toBeNull();
    expect(micInUse([device("x")], null)).toBeNull();
  });

  it("names the Bluetooth one, and nothing for a wired one", () => {
    expect(bluetoothMic(devices, "airpods")?.name).toBe("AirPods Pro");
    expect(bluetoothMic(devices, null)).toBeNull();
    expect(bluetoothMic([device("headset", { isDefault: true, bluetooth: true })], null)?.id).toBe("headset");
    expect(BLUETOOTH_NOTE).toBe("Accuracy is lower with Bluetooth mics");
  });

  it("treats a device list from an older core (no bluetooth field) as wired", () => {
    const old = [{ id: "a", name: "Headset", isDefault: true }] as unknown as InputDevice[];
    expect(bluetoothMic(old, null)).toBeNull();
  });
});
