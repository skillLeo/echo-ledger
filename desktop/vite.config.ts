import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
// @ts-expect-error type error without @types/node package
import process from "node:process";
const host = process.env.TAURI_DEV_HOST;
// The production server the sign-in uses (v1.6): set by the build (CI passes the repository variable), never written
// in the sources. Empty: the sign-in screen asks for the server address. Read in one place: src/lib/server.ts.
const defaultServer = process.env.ECHOLEDGER_DEFAULT_SERVER ?? "";

// https://vite.dev/config/
export default defineConfig(() => ({
  plugins: [react()],
  define: {
    "import.meta.env.VITE_DEFAULT_SERVER": JSON.stringify(defaultServer),
  },

  // Vite options tailored for Tauri development and only applied in `tauri dev` or `tauri build`
  //
  // 1. prevent Vite from obscuring rust errors
  clearScreen: false,
  // 2. tauri expects a fixed port, fail if that port is not available
  server: {
    port: 1420,
    strictPort: true,
    host: host || false,
    hmr: host
      ? {
          protocol: "ws",
          host,
          port: 1421,
        }
      : undefined,
    watch: {
      // 3. tell Vite to ignore watching `src-tauri`
      ignored: ["**/src-tauri/**"],
    },
  },
}));
