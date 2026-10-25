// Test double for the Tauri JS API: `invoke`, `Channel`, `listen` and the current webview window, backed by in-memory
// registries, so views can be rendered in jsdom and driven through the same commands and events the Rust core uses.
import { act } from "@testing-library/react";
import { vi } from "vitest";

type Args = Record<string, unknown>;
type Handler = (args: Args) => unknown;
type Listener = (event: { event: string; id: number; payload: unknown }) => void;

const handlers = new Map<string, Handler>();
const listeners = new Map<string, Set<Listener>>();

/** Every command is routed here; a command nobody registered fails loudly instead of resolving to undefined. */
export const invoke = vi.fn(async (command: string, args: Args = {}): Promise<unknown> => {
  const handler = handlers.get(command);
  if (!handler) throw new Error(`No mock registered for command "${command}"`);
  return handler(args);
});

export const listen = vi.fn(async (event: string, callback: Listener): Promise<() => void> => {
  const set = listeners.get(event) ?? new Set<Listener>();
  set.add(callback);
  listeners.set(event, set);
  return () => {
    set.delete(callback);
  };
});

/** Stand-in for `@tauri-apps/api/core` Channel: tests push messages through `onmessage`. */
export class Channel<T = unknown> {
  static instances: Channel[] = [];
  id = Channel.instances.length + 1;
  onmessage: (message: T) => void;
  constructor(onmessage?: (message: T) => void) {
    this.onmessage = onmessage ?? (() => undefined);
    Channel.instances.push(this as Channel);
  }
}

/** Stand-in for the current webview window: its label and the drag call the pill and overlay start. */
export const currentWindow = {
  label: "main",
  startDragging: vi.fn(async (): Promise<void> => undefined),
};

export const coreModule = { invoke, Channel };
export const eventModule = { listen };
/** `vi.mock("@tauri-apps/api/webviewWindow", async () => (await import("../test/tauri")).windowModule)` */
export const windowModule = { getCurrentWebviewWindow: () => currentWindow };

/** Sets the label `getCurrentWebviewWindow()` reports (main, overlay, dictation, pill, review). */
export function setWindowLabel(label: string): void {
  currentWindow.label = label;
}

/** Registers a command that resolves to `value`. */
export function respond(command: string, value: unknown): void {
  handlers.set(command, () => value);
}

/** Registers several commands at once: `{ app_status: makeStatus(), recording_state: makeRecordingState() }`. */
export function respondAll(values: Record<string, unknown>): void {
  for (const [command, value] of Object.entries(values)) respond(command, value);
}

/** Registers a command with custom behaviour (inspect arguments, return a pending promise, ...). */
export function respondWith(command: string, handler: Handler): void {
  handlers.set(command, handler);
}

/** Registers a command that rejects the way the Rust side does: with a plain string message. */
export function fail(command: string, message: string): void {
  handlers.set(command, () => Promise.reject(message));
}

/** Arguments of every call made to `command`, in order. */
export function callsOf(command: string): Args[] {
  return invoke.mock.calls.filter(([name]) => name === command).map(([, args]) => (args ?? {}) as Args);
}

export function listenerCount(event: string): number {
  return listeners.get(event)?.size ?? 0;
}

/** Delivers a backend event (`app:status`, `library:updated`, ...) to every subscribed component. */
export async function emitEvent(event: string, payload?: unknown): Promise<void> {
  await act(async () => {
    listeners.get(event)?.forEach((callback) => callback({ event, id: 0, payload }));
  });
}

/** Lets in-flight command promises settle inside `act`, so late state updates do not leak out of a test. */
export async function flushAsync(): Promise<void> {
  await act(async () => {
    for (let i = 0; i < 25; i++) await Promise.resolve();
  });
}

/** Pushes a message to the most recent Channel passed to the backend. */
export async function pushChannelMessage<T>(message: T): Promise<void> {
  const channel = Channel.instances[Channel.instances.length - 1] as Channel<T> | undefined;
  if (!channel) throw new Error("No Channel has been created yet");
  await act(async () => {
    channel.onmessage(message);
  });
}

export function resetTauriMock(): void {
  handlers.clear();
  listeners.clear();
  Channel.instances = [];
  invoke.mockClear();
  listen.mockClear();
  currentWindow.label = "main";
  currentWindow.startDragging.mockClear();
}
