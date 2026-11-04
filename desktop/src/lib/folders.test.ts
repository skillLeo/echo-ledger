import { describe, expect, it } from "vitest";
import { folderApp, folderLabel, inFolderHere, shownFolders, SMART_FOLDERS } from "./folders";
import { makeItem } from "../test/fixtures";

describe("smart folders", () => {
  it("are the ten folders of the contract, in the sidebar's order", () => {
    expect(SMART_FOLDERS.map(([f]) => f)).toEqual(["inbox", "whatsapp", "whatsapp_business", "google_meet", "zoom", "teams", "phone", "voice_notes", "imported", "shared"]);
    expect(folderLabel("teams")).toBe("Teams");
    expect(folderLabel("shared")).toBe("Shared with me");
    expect(folderApp("teams")).toBe("microsoft_teams");
    expect(folderApp("phone")).toBe("phone_call");
    expect(folderApp("imported")).toBeNull();
  });

  it("tell from a saved recording what they can, and leave the rest to the server", () => {
    const inProject = makeItem("a", { client: { id: "c1", name: "Acme" }, source_app: "zoom" });
    const inInbox = makeItem("b", { client: null, source_app: "whatsapp_web" });
    expect(inFolderHere(inInbox, "inbox")).toBe(true);
    expect(inFolderHere(inProject, "inbox")).toBe(false);
    expect(inFolderHere(inProject, "zoom")).toBe(true);
    expect(inFolderHere(inInbox, "whatsapp")).toBe(true);
    expect(inFolderHere(inInbox, "whatsapp_business")).toBe(false);
    expect(inFolderHere(makeItem("c", { source_app: "voice_note" }), "voice_notes")).toBe(true);
    expect(inFolderHere(inProject, "imported")).toBeNull();
    expect(inFolderHere(inProject, "shared")).toBeNull();
    expect(inFolderHere(makeItem("d", { deleted: true, client: null }), "inbox")).toBe(false);
  });

  it("list the Inbox and Shared with me always and the others while they hold something or are open", () => {
    const counts = { inbox: 0, whatsapp: 31, zoom: 0, imported: 2, shared: 0 };
    expect(shownFolders(counts, null).map((f) => [f.folder, f.count])).toEqual([["inbox", 0], ["whatsapp", 31], ["imported", 2], ["shared", 0]]);
    expect(shownFolders(counts, "zoom").map((f) => f.folder)).toEqual(["inbox", "whatsapp", "zoom", "imported", "shared"]);
    expect(shownFolders({ inbox: 4, shared: 1 }, null).map((f) => f.label)).toEqual(["Inbox", "Shared with me"]);
  });

  it("list nothing on a server that sends no folders", () => {
    expect(shownFolders(undefined, null)).toEqual([]);
    expect(shownFolders(null, "inbox")).toEqual([]);
  });

  it("never offer Shared with me on a server that does not count it", () => {
    expect(shownFolders({ inbox: 1 }, "shared").map((f) => f.folder)).toEqual(["inbox"]);
  });
});
