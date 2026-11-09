import { describe, expect, it } from "vitest";
import { DEFAULT_SERVER, cleanServer, serverHost } from "./server";
import serverSource from "./server.ts?raw";
import viteConfig from "../../vite.config.ts?raw";

describe("the built-in server (v1.6)", () => {
  it("comes from the build's environment, never from the sources", () => {
    expect(viteConfig).toContain("process.env.ECHOLEDGER_DEFAULT_SERVER");
    expect(viteConfig).toContain('"import.meta.env.VITE_DEFAULT_SERVER"');
    expect(serverSource).toContain("import.meta.env.VITE_DEFAULT_SERVER");
    expect(serverSource).not.toMatch(/https:\/\/[a-z0-9-]+\.[a-z]/i);
    // Tests build without one: the sign-in screen then asks for the address.
    expect(DEFAULT_SERVER).toBe("");
  });

  it("keeps only an https address with a host (http for this computer only)", () => {
    expect(cleanServer(" https://ledger.example.com/ ")).toBe("https://ledger.example.com");
    expect(cleanServer("https://example.com/echoledger")).toBe("https://example.com/echoledger");
    expect(cleanServer("http://localhost:8000")).toBe("http://localhost:8000");
    for (const bad of ["", "   ", "ledger.example.com", "http://ledger.example.com", "https://user:pw@ledger.example.com", "https://ledger.example.com/?x=1", "javascript:alert(1)", undefined, null]) {
      expect(cleanServer(bad)).toBe("");
    }
    expect(serverHost("https://ledger.example.com:8443/x")).toBe("ledger.example.com:8443");
    expect(serverHost("not a url")).toBe("not a url");
  });
});
