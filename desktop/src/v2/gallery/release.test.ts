// @vitest-environment node
// The gallery must never ship: a real release build (vite build, in memory) has no gallery chunk, no gallery module and
// no "v2gallery" anywhere in its output; nor the development-only interface flag override.
import { describe, expect, it } from "vitest";
import { build } from "vite";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import type { OutputAsset, OutputChunk, RollupOutput } from "rollup";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..", "..", "..");

describe("a release build", () => {
  it("leaves the v2 gallery out", async () => {
    // Vitest runs with NODE_ENV=test, which would build in development mode (DEV true); a release builds as production.
    const before = process.env.NODE_ENV;
    process.env.NODE_ENV = "production";
    const result = await build({
      root: desktop,
      configFile: join(desktop, "vite.config.ts"),
      mode: "production",
      logLevel: "silent",
      build: { write: false, reportCompressedSize: false },
    }).finally(() => { process.env.NODE_ENV = before; });
    const outputs = (Array.isArray(result) ? result : [result]) as RollupOutput[];
    const files: (OutputChunk | OutputAsset)[] = outputs.flatMap((o) => o.output);
    expect(files.some((f) => f.fileName.endsWith(".js"))).toBe(true);
    const chunks = files.filter((f): f is OutputChunk => f.type === "chunk");
    // The app itself is there (a string from its main window), so the scan is reading real output.
    expect(chunks.some((c) => c.code.includes("Starting EchoLedger"))).toBe(true);
    for (const chunk of chunks) {
      expect(chunk.fileName).not.toMatch(/gallery/i);
      expect(Object.keys(chunk.modules).filter((m) => /[/\\]v2[/\\]gallery[/\\]/.test(m))).toEqual([]);
      expect(chunk.code).not.toContain("v2gallery");
      expect(chunk.code).not.toContain("mountGallery");
      // The development-only flag override (v2/flags.ts) does not ship either.
      expect(chunk.code).not.toContain("uiflags");
    }
    for (const asset of files.filter((f): f is OutputAsset => f.type === "asset")) {
      expect(asset.fileName).not.toMatch(/gallery/i);
      if (typeof asset.source === "string") expect(asset.source).not.toContain("v2gallery");
    }
  }, 120_000);
});
