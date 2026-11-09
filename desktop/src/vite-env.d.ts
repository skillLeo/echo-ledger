/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** The production server, from ECHOLEDGER_DEFAULT_SERVER at build time (vite.config.ts); empty when not set. */
  readonly VITE_DEFAULT_SERVER?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
