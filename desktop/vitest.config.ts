/// <reference types="vitest/config" />
import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";

// Vitest config kept separate from the Tauri vite.config.ts (dev-server options don't apply to tests).
export default defineConfig({
  plugins: [react()],
  test: {
    environment: "jsdom",
    globals: true,
    setupFiles: ["./vitest.setup.ts"],
    include: ["src/**/*.test.{ts,tsx}"],
    // As CI runs it (desktop-mac.yml, desktop-windows.yml: --testTimeout=30000): a test that passes there no longer
    // fails here only because a busy computer is slower. Slow tests are still fixed at their cause (D-166, D-167).
    testTimeout: 30_000,
  },
});
