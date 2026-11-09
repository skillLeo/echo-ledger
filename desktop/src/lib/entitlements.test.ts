import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, renderHook } from "@testing-library/react";
import { allFeatures, badgeText, featureWords, featuresSummary, forgetEntitlements, isLocked, lowestPlan, needsOwnKey, pendingRequest, refreshEntitlements, resetEntitlementsForTests, useEntitlements } from "./entitlements";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FEATURE_REGISTRY, makeEntitlements, sampleEntitlements } from "../test/fixtures";
import { callsOf, fail, resetTauriMock, respond } from "../test/tauri";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../test/tauri")).eventModule);

describe("what the plan allows (v1.6)", () => {
  it("locks only what the answer names as off; an older server locks nothing", () => {
    const e = makeEntitlements();
    expect(isLocked(e, "views")).toBe(true);
    expect(isLocked(e, "record")).toBe(false);
    expect(isLocked(e, "something_new")).toBe(false);
    expect(isLocked(null, "views")).toBe(false);
    expect(isLocked(undefined, "views")).toBe(false);
    expect(needsOwnKey(e)).toBe(true);
    expect(needsOwnKey({ ...e, features: { ...e.features, platform_transcription: { ...e.features.platform_transcription, enabled: true } } })).toBe(false);
  });

  it("finds the lowest plan with the feature and writes its one badge", () => {
    const e = makeEntitlements();
    expect(lowestPlan(e, "views")?.name).toBe("Pro");
    expect(lowestPlan(e, "live_translate")?.color).toBe("#0f766e");
    expect(badgeText(lowestPlan(e, "views")!)).toBe("PRO ✦");
    expect(badgeText(lowestPlan(e, "ai_notes")!)).toBe("BUSINESS ✦");
    // A lowest plan that is not on offer still gets a badge from its key.
    const offerless = makeEntitlements({ plans: [] });
    expect(badgeText(lowestPlan(offerless, "views")!)).toBe("PRO ✦");
    expect(lowestPlan(offerless, "record")).toBeNull();
  });

  it("uses the server's words for a feature, else the app's", () => {
    const e = makeEntitlements();
    expect(featureWords(e, "views")).toEqual({ name: "Transcript views", description: "Read any transcript in Roman Urdu, English or Urdu script." });
    expect(featureWords(e, "screen_moments").name).toBe("Screen moments");
    expect(featureWords(e, "screen_moments").description).toMatch(/meeting's screen/);
    expect(featureWords(e, "brand_new_thing").name).toBe("Brand new thing");
  });

  it("knows a pending request from a decided one", () => {
    const request = { id: "01JF", plan: "pro", status: "pending", message: null, payment_instructions: null, created_at: null, decided_at: null, reply: null };
    expect(pendingRequest(makeEntitlements({ upgrade_request: request }))).toEqual(request);
    expect(pendingRequest(makeEntitlements({ upgrade_request: { ...request, status: "declined" } }))).toBeNull();
    expect(pendingRequest(null)).toBeNull();
  });
});

describe("reading the entitlements", () => {
  beforeEach(() => {
    resetTauriMock();
    resetEntitlementsForTests();
  });

  it("reads once at a time, keeps the last answer when offline, and reads none from an older server", async () => {
    const { result } = renderHook(() => useEntitlements());
    expect(result.current).toBeUndefined();
    respond("entitlements_get", makeEntitlements());
    await act(async () => {
      await Promise.all([refreshEntitlements(), refreshEntitlements()]);
    });
    expect(callsOf("entitlements_get")).toHaveLength(1);
    expect(result.current?.plan?.key).toBe("free");

    fail("entitlements_get", "Network error");
    await act(() => refreshEntitlements());
    expect(result.current?.plan?.key).toBe("free");

    respond("entitlements_get", null);
    await act(() => refreshEntitlements());
    expect(result.current).toBeNull();
    act(() => forgetEntitlements());
    expect(result.current).toBeUndefined();
  });
});

describe("All features (v1.6.2)", () => {
  // The real answer (docs/api-samples, kept true by the server's own test), read from disk.
  const real = JSON.parse(readFileSync(join(dirname(fileURLToPath(import.meta.url)), "../../../docs/api-samples/entitlements.json"), "utf8")) as {
    features: Record<string, { name: string; description: string; lowest_plan: string | null }>;
  };

  it("the fixtures' registry is the real sample's: the same fifteen features, names, words and order", () => {
    expect(FEATURE_REGISTRY.map(([key, name, description, lowest]) => [key, name, description, lowest]))
      .toEqual(Object.entries(real.features).map(([key, f]) => [key, f.name, f.description, f.lowest_plan]));
  });

  it("keeps the server's order and counts what the plan has", () => {
    expect(allFeatures(sampleEntitlements("free")).keys).toEqual(Object.keys(real.features));
    expect(featuresSummary(sampleEntitlements("free"))).toBe("Your plan has 6 of 15 features");
    expect(featuresSummary(sampleEntitlements("pro"))).toBe("Your plan has 11 of 15 features");
    expect(featuresSummary(sampleEntitlements("ultra"))).toBe("Your plan has 15 of 15 features");
    expect(featuresSummary(makeEntitlements({ features: { record: { enabled: true, name: null, description: null, cost: "free", lowest_plan: null } } })))
      .toBe("Your plan has 1 of 1 feature");
  });
});
