import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { applyTheme, getThemeChoice, nextThemeChoice, resolveTheme, saveThemeChoice, watchSystemTheme } from "./theme";

const KEY = "echoledger-theme";

/** Installs a controllable `window.matchMedia` and returns a way to flip the OS appearance. */
function mockSystemTheme(prefersLight: boolean) {
  let matches = prefersLight;
  const changeListeners: (() => void)[] = [];
  vi.stubGlobal(
    "matchMedia",
    vi.fn((query: string) => ({
      get matches() {
        return query.includes("light") ? matches : !matches;
      },
      media: query,
      addEventListener: (_type: string, cb: () => void) => changeListeners.push(cb),
      removeEventListener: () => undefined,
    })),
  );
  return {
    setLight(value: boolean) {
      matches = value;
      changeListeners.forEach((cb) => cb());
    },
  };
}

describe("theme", () => {
  beforeEach(() => {
    window.localStorage.clear();
    document.documentElement.removeAttribute("data-theme");
    document.documentElement.style.colorScheme = "";
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  describe("resolveTheme", () => {
    it("follows the system only for the system choice", () => {
      expect(resolveTheme("system", true)).toBe("light");
      expect(resolveTheme("system", false)).toBe("dark");
      expect(resolveTheme("light", false)).toBe("light");
      expect(resolveTheme("dark", true)).toBe("dark");
    });
  });

  describe("nextThemeChoice", () => {
    it("cycles system, light, dark and back to system", () => {
      expect(nextThemeChoice("system")).toBe("light");
      expect(nextThemeChoice("light")).toBe("dark");
      expect(nextThemeChoice("dark")).toBe("system");
    });
  });

  describe("getThemeChoice / saveThemeChoice", () => {
    it("defaults to system when nothing is stored", () => {
      expect(getThemeChoice()).toBe("system");
    });

    it("round-trips every valid choice", () => {
      for (const choice of ["light", "dark", "system"] as const) {
        saveThemeChoice(choice);
        expect(window.localStorage.getItem(KEY)).toBe(choice);
        expect(getThemeChoice()).toBe(choice);
      }
    });

    it("ignores a stored value that is not a theme", () => {
      window.localStorage.setItem(KEY, "sepia");
      expect(getThemeChoice()).toBe("system");
      window.localStorage.setItem(KEY, "");
      expect(getThemeChoice()).toBe("system");
    });

    it("survives unavailable storage on both read and write", () => {
      vi.spyOn(Storage.prototype, "getItem").mockImplementation(() => {
        throw new Error("denied");
      });
      vi.spyOn(Storage.prototype, "setItem").mockImplementation(() => {
        throw new Error("denied");
      });
      expect(getThemeChoice()).toBe("system");
      expect(() => saveThemeChoice("dark")).not.toThrow();
    });
  });

  describe("applyTheme", () => {
    it("writes the resolved theme to the root element and color-scheme", () => {
      mockSystemTheme(false);
      expect(applyTheme("light")).toBe("light");
      expect(document.documentElement.dataset.theme).toBe("light");
      expect(document.documentElement.style.colorScheme).toBe("light");
      expect(applyTheme("dark")).toBe("dark");
      expect(document.documentElement.dataset.theme).toBe("dark");
      expect(document.documentElement.style.colorScheme).toBe("dark");
    });

    it("resolves the system choice from prefers-color-scheme", () => {
      mockSystemTheme(true);
      expect(applyTheme("system")).toBe("light");
      expect(document.documentElement.dataset.theme).toBe("light");
      mockSystemTheme(false);
      expect(applyTheme("system")).toBe("dark");
      expect(document.documentElement.dataset.theme).toBe("dark");
    });

    it("falls back to dark for the system choice when matchMedia does not exist", () => {
      vi.stubGlobal("matchMedia", undefined);
      expect(applyTheme("system")).toBe("dark");
      expect(document.documentElement.dataset.theme).toBe("dark");
    });
  });

  describe("watchSystemTheme", () => {
    it("re-applies the theme on an OS change only while the choice is system", () => {
      const os = mockSystemTheme(false);
      watchSystemTheme();

      saveThemeChoice("system");
      os.setLight(true);
      expect(document.documentElement.dataset.theme).toBe("light");
      os.setLight(false);
      expect(document.documentElement.dataset.theme).toBe("dark");

      saveThemeChoice("dark");
      os.setLight(true);
      expect(document.documentElement.dataset.theme).toBe("dark");
    });

    it("does nothing without matchMedia", () => {
      vi.stubGlobal("matchMedia", undefined);
      expect(() => watchSystemTheme()).not.toThrow();
    });
  });
});
