// The server EchoLedger signs in to (v1.6, P1): the one place it is set. It comes in at build time from the
// environment (ECHOLEDGER_DEFAULT_SERVER, handed to the page by vite.config.ts), never from the sources. Empty (local
// development, tests): the sign-in screen asks for the server address as before. Set: the address is not asked for,
// and "Use a different server" sits under Advanced.

/** An address worth using: https (http only for this computer), a host, no user name, no query; trailing "/" dropped. */
export function cleanServer(value: string | null | undefined): string {
  const text = (value ?? "").trim().replace(/\/+$/, "");
  let url: URL;
  try {
    url = new URL(text);
  } catch {
    return "";
  }
  const local = url.protocol === "http:" && ["localhost", "127.0.0.1", "[::1]"].includes(url.hostname);
  if ((url.protocol !== "https:" && !local) || !url.hostname || url.username || url.password || url.search || url.hash) return "";
  return text;
}

/** The production server built into this app, or "" when the build has none. */
export const DEFAULT_SERVER: string = cleanServer(import.meta.env.VITE_DEFAULT_SERVER);

/** The host of an address for plain sentences ("ledger.example.com"), or the text itself. */
export function serverHost(url: string): string {
  try {
    return new URL(url).host || url;
  } catch {
    return url;
  }
}
