// The sheet's preview follows the same rules as the core (files.rs): the cases below are the Rust test's cases.
import { describe, expect, it } from "vitest";
import { fileName, IMPORT_EXTENSIONS, importNotice, importTitle, isImportable, MAX_TITLE_CHARS } from "./importFiles";
import filesRs from "../../src-tauri/src/files.rs?raw";

describe("import preview", () => {
  it("takes WhatsApp voice notes, audio and the two video types", () => {
    for (const name of ["PTT-20261030-WA0003.opus", "a.OGG", "a.oga", "a.mp3", "a.m4a", "a.aac", "a.wav", "a.flac", "a.amr", "clip.mp4", "clip.WEBM"]) {
      expect(isImportable(`C:\\Users\\me\\${name}`), name).toBe(true);
    }
    for (const name of ["notes.txt", "photo.jpg", "movie.mkv", "no_extension", ".opus"]) {
      expect(isImportable(`/Users/me/${name}`), name).toBe(false);
    }
  });

  it("accepts exactly the extensions the core accepts", () => {
    const rust = /IMPORT_EXTENSIONS: \[&str; \d+\] = \[([^\]]+)\]/.exec(filesRs)?.[1] ?? "";
    expect([...rust.matchAll(/"(\w+)"/g)].map((m) => m[1])).toEqual([...IMPORT_EXTENSIONS]);
  });

  it("titles a recording after its file: no extension, underscores as spaces, 200 characters at most", () => {
    expect(importTitle("C:/Users/me/Call_with_Acme_Oct.m4a")).toBe("Call with Acme Oct");
    expect(importTitle("C:\\Users\\me\\Call_with_Acme_Oct.m4a")).toBe("Call with Acme Oct");
    expect(importTitle("/tmp/PTT-20261030-WA0003.opus")).toBe("PTT-20261030-WA0003");
    expect(importTitle("__budget__  review_.mp3")).toBe("budget review");
    expect(importTitle("archive.v2.wav")).toBe("archive.v2");
    expect(importTitle("___.wav")).toBeNull();
    expect([...(importTitle(`${"ب".repeat(250)}.mp3`) ?? "")]).toHaveLength(MAX_TITLE_CHARS);
    expect(fileName("D:\\a\\b\\clip.webm")).toBe("clip.webm");
  });

  it("says what was queued and what was skipped", () => {
    expect(importNotice({ queued: 1, duplicates: 0, unsupported: 0, failed: 0 })).toBe("Importing 1 file. It uploads in the background.");
    expect(importNotice({ queued: 3, duplicates: 1, unsupported: 2, failed: 0 })).toBe(
      "Importing 3 files. They upload in the background. Skipped 1 file already waiting to upload. Skipped 2 files that are not a voice note, audio or video file.",
    );
    expect(importNotice({ queued: 0, duplicates: 0, unsupported: 0, failed: 0 })).toBe("Nothing to import.");
  });
});
