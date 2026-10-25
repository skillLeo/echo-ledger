// Short start / stop sounds for dictation (plan B14), made with Web Audio so no sound files ship with the app.

/** Start rises, stop falls; each is two short tones. */
export const TONES = { start: [660, 880], stop: [880, 520] } as const;

let context: AudioContext | null = null;

/** Plays the start or stop sound; does nothing where Web Audio is missing or blocked. */
export function playTone(kind: keyof typeof TONES, volume = 0.08): boolean {
  try {
    const Ctor = (globalThis as { AudioContext?: typeof AudioContext }).AudioContext;
    if (!Ctor) return false;
    context ??= new Ctor();
    const now = context.currentTime;
    TONES[kind].forEach((frequency, i) => {
      const osc = context!.createOscillator();
      const gain = context!.createGain();
      osc.type = "sine";
      osc.frequency.value = frequency;
      gain.gain.setValueAtTime(0, now + i * 0.09);
      gain.gain.linearRampToValueAtTime(volume, now + i * 0.09 + 0.01);
      gain.gain.linearRampToValueAtTime(0, now + i * 0.09 + 0.08);
      osc.connect(gain).connect(context!.destination);
      osc.start(now + i * 0.09);
      osc.stop(now + i * 0.09 + 0.09);
    });
    return true;
  } catch {
    return false;
  }
}
