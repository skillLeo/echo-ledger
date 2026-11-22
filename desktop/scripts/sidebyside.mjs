// Classic | new design, side by side (docs/PLAN_V2_PARITY.md 1 and 8): each screen as classic shows it and as the new
// design shows it, from the same fixture data (scripts/screens/main.tsx), light and dark, into one picture each:
// docs/v2/parity/desktop/<name>-<theme>.png. Every v2 merge request on the parity work shows the pictures of the
// screens it touches.
//   node scripts/sidebyside.mjs [--screen=name] [--out=DIR]
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const arg = (name) => process.argv.find((a) => a.startsWith(`--${name}=`))?.split("=")[1];
const out = resolve(arg("out") ?? join(desktop, "..", "docs", "v2", "parity", "desktop"));
const only = arg("screen");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";

const classic = (screen) => `scripts/screens/index.html?screen=${screen}`;
const v2 = (screen) => `scripts/screens/index.html?screen=${screen}&uiflags=${FLAGS}`;
const click = (name) => async (page) => {
  await page.getByRole("button", { name, exact: true }).first().click();
  await page.waitForTimeout(900);
};
/** Ticks the first `n` recordings in selection mode (classic's list or the new design's rows). */
const pickRows = (n) => async (page) => {
  const rows = page.locator("button[data-row-id], .list .item, [role=listitem] button").filter({ visible: true });
  for (let i = 0; i < n; i++) await rows.nth(i).click();
  await page.waitForTimeout(400);
};
const steps = (...all) => async (page) => {
  for (const s of all) await s(page);
};

/** [name, what it shows, classic: [path, steps], new design: [path, steps]] */
export const PAIRS = [
  ["appearance", "Settings › Appearance: the design switch both ways",
    [classic("settings"), steps(click("Appearance"))],
    [v2("settings"), steps(click("Appearance"))]],
  ["live-assist", "Live Assist: classic's Home (where classic starts things) and the new design's Live tab",
    [`${classic("library")}&uiflags=live_assist`, steps(async (page) => {
      await page.locator(".sidebar").getByRole("button", { name: /^Home/ }).click();
      await page.waitForTimeout(1500);
      await page.getByRole("region", { name: "Live Assist" }).scrollIntoViewIfNeeded();
    })],
    [v2("library"), steps(async (page) => {
      await page.getByRole("navigation", { name: "Main" }).getByRole("button", { name: "Live", exact: true }).click();
      await page.waitForTimeout(1500);
    })]],
  ["selection", "Library selection: classic's bulk bar with More open | the new design's bar (× · count · Select all · Move, Label, Share, Delete · ⋮) with ⋮ › Export ▸ open",
    // Classic's list column clips its More popover at 1280 wide (a classic layout limit); a wider window shows it whole.
    [classic("library"), steps(click("Select"), pickRows(2), async (page) => {
      await page.locator(".bulk-bar summary", { hasText: "More" }).click();
      await page.waitForTimeout(400);
      await page.locator(".bulk-pop").last().scrollIntoViewIfNeeded();
    }), { width: 1800, height: 1000 }],
    [v2("library"), steps(click("Select"), pickRows(2), async (page) => {
      await page.getByRole("button", { name: "More actions" }).click();
      await page.getByRole("menuitem", { name: "Export" }).hover();
      await page.waitForTimeout(400);
    })]],
  ["row-menu", "A recording's menu: classic's row ⋯ | the new design's row ⋮ (Organise · Export · Danger, the same menu as a right-click)",
    [classic("library"), steps(async (page) => {
      await page.getByRole("button", { name: /^More for / }).first().click({ force: true });
      await page.waitForTimeout(400);
    })],
    [v2("library"), steps(async (page) => {
      await page.getByRole("button", { name: /^More for / }).first().click({ force: true });
      await page.waitForTimeout(400);
    })]],
  ["recording", "The recording page: classic's detail | the new design's page with ⋮ open (Organise · Export · Danger)",
    [classic("library"), null],
    [v2("library"), steps(async (page) => {
      await page.getByRole("button", { name: "Kick-off: Contoso website" }).first().click();
      await page.waitForTimeout(1200);
      await page.getByRole("button", { name: "Recording actions" }).click();
      await page.waitForTimeout(400);
    })]],
  ["recording-menus", "Copy and Export: classic's copy row and Export… select | the new design's Copy ▾ and Export ▾ menus with Show as",
    [classic("library"), steps(async (page) => {
      await page.locator("select", { hasText: "Export…" }).first().focus();
    })],
    [v2("library"), steps(async (page) => {
      await page.getByRole("button", { name: "Kick-off: Contoso website" }).first().click();
      await page.waitForTimeout(1200);
      await page.getByRole("button", { name: "Export", exact: true }).click();
      await page.waitForTimeout(400);
    })]],
  ["filters", "Find: classic's Filters panel | the new design's chips (Views ▾, Source ▾ open with two ticks), sections with counts",
    [classic("library"), steps(click("Filters"))],
    [v2("library"), steps(async (page) => {
      await page.getByRole("button", { name: "Source", exact: true }).click();
      await page.getByRole("menuitemcheckbox").nth(0).click();
      await page.getByRole("menuitemcheckbox").nth(1).click();
      await page.waitForTimeout(400);
    })]],
  ["sort-group", "Sort and group: classic's View menu | the new design's Sort and group menu",
    [classic("library"), steps(async (page) => { await page.getByRole("button", { name: /^View:/ }).first().click(); await page.waitForTimeout(400); })],
    [v2("library"), steps(async (page) => {
      await page.getByRole("button", { name: "Sort and group" }).click();
      await page.waitForTimeout(400);
    })]],
  ["library-rows", "Library rows: classic's app tiles | the new design's real source logos with the device badge",
    [classic("library"), null],
    [v2("library"), null]],
  ["sidebar", "The sidebar: classic's | plan 2's (● Record with its menu; Library, Projects, Live, Ask; Activity with its count, Settings, the account)",
    [classic("library"), null],
    [v2("library"), steps(async (page) => {
      await page.getByRole("button", { name: "More ways to record" }).click();
      await page.waitForTimeout(400);
    })]],
  ["settings-groups", "Settings: classic's list | the new design's goal groups, each with its icon and a one-line description",
    [classic("settings"), null],
    [v2("settings"), null]],
  ["palette", "The command palette (Ctrl/⌘+K) with \"go\" typed: classic's | the same rows in the new design",
    [classic("library"), steps(async (page) => {
      await page.getByRole("button", { name: /^Search/ }).first().click();
      await page.keyboard.type("go");
      await page.waitForTimeout(600);
    })],
    [v2("library"), steps(async (page) => {
      await page.getByRole("button", { name: /^Search/ }).first().click();
      await page.keyboard.type("go");
      await page.waitForTimeout(600);
    })]],
  ["library-top", "Library as the start screen: classic's Home (get started, needs action) | the new Library's get-started card, and what needs action as its header's button",
    [`${classic("library")}`, steps(async (page) => {
      await page.locator(".sidebar").getByRole("button", { name: /^Home/ }).click();
      await page.waitForTimeout(1200);
    })],
    [`${v2("library")}&calls=notready`, null]],
  ["library-queue", "The top of the Library list: waiting calls (Review open: Keep all, Discard all, Keep, Discard, Rename, Choose project, Language), Importing with Cancel, Uploads that need you (Retry, Discard), Waiting for another account (Discard all 2), Checking upload",
    [`${classic("library")}&uploads=1`, steps(click("Review")), { width: 1600, height: 1400 }],
    [`${v2("library")}&uploads=1`, steps(async (page) => {
      await page.getByTestId("waiting-line").getByRole("button", { name: "Review" }).click();
      await page.waitForTimeout(900);
    }), { width: 1600, height: 1400 }]],
  ["activity", "Activity: needs action (Keep or discard? · Review, Uploads that need you · Open, problems), skipped short calls (Show, Restore), Today and Earlier with Unread and Mark all read",
    [`${classic("activity")}`, steps(click("Show")), { width: 1280, height: 1100 }],
    [`${v2("activity")}`, steps(click("Show")), { width: 1280, height: 1100 }]],
  ["transcript-search", "Search inside transcripts: classic's \"In transcripts\" | the new design's, with the words marked",
    [classic("library"), steps(async (page) => {
      await page.getByPlaceholder("Search", { exact: true }).first().fill("launch");
      await page.waitForTimeout(1200);
    })],
    [v2("library"), steps(async (page) => {
      await page.getByRole("searchbox", { name: "Search recordings" }).fill("launch");
      await page.waitForTimeout(1200);
    })]],
  ["recording-tools", "The transcript tools: classic's | the new design's Find with its marks and N of M, and the tools row",
    [classic("library"), steps(async (page) => {
      await page.keyboard.press("Control+f");
      await page.keyboard.type("the");
      await page.waitForTimeout(600);
    })],
    [v2("library"), steps(async (page) => {
      await page.getByRole("button", { name: "Kick-off: Contoso website" }).first().click();
      await page.waitForTimeout(1200);
      await page.getByRole("button", { name: "Find", exact: true }).click();
      await page.keyboard.type("the");
      await page.waitForTimeout(600);
    })]],
  ["recording-edit", "Editing: classic's Edit text | the new design's line editor (Show as is off while editing)",
    [classic("library"), steps(async (page) => {
      await page.getByRole("button", { name: "Edit text" }).first().click();
      await page.waitForTimeout(500);
    })],
    [v2("library"), steps(async (page) => {
      await page.getByRole("button", { name: "Kick-off: Contoso website" }).first().click();
      await page.waitForTimeout(1200);
      await page.getByRole("button", { name: "Edit text" }).click();
      await page.locator(".el-line-edit").first().click();
      await page.waitForTimeout(500);
    })]],
  ["call-notes", "Call notes: classic's section | the new design's Call notes tab",
    [classic("library"), steps(async (page) => {
      await page.getByRole("heading", { name: "Call notes" }).first().scrollIntoViewIfNeeded().catch(() => undefined);
      await page.waitForTimeout(500);
    })],
    [v2("library"), steps(async (page) => {
      await page.getByRole("button", { name: "Kick-off: Contoso website" }).first().click();
      await page.waitForTimeout(1200);
      await page.getByRole("tab", { name: "Call notes" }).click();
      await page.waitForTimeout(800);
    })]],
  ["library-table", "Show as Table: classic's | the new design's, with the Columns menu",
    [classic("library"), steps(async (page) => {
      await page.locator(".layout-switch button[title=Table]").click();
      await page.waitForTimeout(800);
    })],
    [v2("library"), steps(async (page) => {
      await page.getByRole("radio", { name: "Table" }).click();
      await page.getByRole("button", { name: "Columns" }).click();
      await page.waitForTimeout(500);
    })]],
  ["sidebar-sublists", "The sidebar's sub-lists: classic's Projects and Shared | the new design's pinned projects under Projects and Shared's places under Library",
    [classic("library"), null],
    [v2("library"), null]],
  ["table-drawer", "A recording from the table: classic's drawer | the new design's drawer over the table",
    [classic("library"), steps(async (page) => {
      // The open recording shows in classic's drawer as soon as the table is on.
      await page.locator(".layout-switch button[title=Table]").click();
      await page.waitForTimeout(1200);
    })],
    [v2("library"), steps(async (page) => {
      await page.getByRole("radio", { name: "Table" }).click();
      await page.getByRole("button", { name: "Kick-off: Contoso website" }).first().click();
      await page.waitForTimeout(1200);
    })]],
  ["recording-header", "The recording's header: classic's | the new design's Share, the project link, Transcribed in, Automatic title, the script warning, bookmarks and the keys hint",
    [classic("library"), null],
    [v2("library"), steps(async (page) => {
      await page.getByRole("button", { name: "Kick-off: Contoso website" }).first().click();
      await page.waitForTimeout(1400);
    })]],
  ["recording-details", "Details: classic's Organise panel | the new design's Details tab (engine, kept, labels, pinned)",
    [classic("library"), null],
    [v2("library"), steps(async (page) => {
      await page.getByRole("button", { name: "Kick-off: Contoso website" }).first().click();
      await page.waitForTimeout(1200);
      await page.getByRole("tab", { name: "Details" }).click();
      await page.waitForTimeout(400);
    })]],
  ["record-menu", "Record's menu: classic's + menu | the new design's Record ▾ (Voice note, Upload files, New project, Request link, Record page)",
    [classic("library"), steps(async (page) => {
      await page.locator(".create-btn").first().click();
      await page.waitForTimeout(500);
    })],
    [v2("library"), steps(async (page) => {
      await page.getByRole("button", { name: "More ways to record" }).click();
      await page.waitForTimeout(400);
    })]],
];

const server = await createServer({ root: desktop, configFile: join(desktop, "vite.config.ts"), server: { port: 1480, strictPort: false }, logLevel: "error" });
await server.listen();
const base = server.resolvedUrls.local[0];
await mkdir(out, { recursive: true });
const browser = await chromium.launch();

async function shoot([path, step, viewport], theme) {
  const page = await browser.newPage({ viewport: viewport ?? { width: 1280, height: 800 }, colorScheme: theme, reducedMotion: "reduce" });
  await page.goto(`${base}${path}&theme=${theme}`);
  await page.waitForTimeout(2200);
  if (step) await step(page);
  await page.waitForTimeout(300);
  const png = await page.screenshot();
  await page.close();
  return png.toString("base64");
}

try {
  for (const [name, what, before, after] of PAIRS) {
    if (only && name !== only) continue;
    for (const theme of ["light", "dark"]) {
      const [left, right] = [await shoot(before, theme), await shoot(after, theme)];
      const sheet = await browser.newPage({ viewport: { width: 2600, height: 900 }, colorScheme: theme });
      const ink = theme === "dark" ? "#e8e8e6" : "#1b1b19";
      const paper = theme === "dark" ? "#111113" : "#f4f4f2";
      await sheet.setContent(`<body style="margin:0;padding:20px;background:${paper};color:${ink};font:15px system-ui">
        <div style="margin:0 0 10px">${what}</div>
        <div style="display:flex;gap:20px">
          <figure style="margin:0"><figcaption style="margin:0 0 6px;font-weight:600">Classic</figcaption><img style="width:1270px;border:1px solid #888" src="data:image/png;base64,${left}"></figure>
          <figure style="margin:0"><figcaption style="margin:0 0 6px;font-weight:600">New design</figcaption><img style="width:1270px;border:1px solid #888" src="data:image/png;base64,${right}"></figure>
        </div></body>`);
      await sheet.waitForTimeout(200);
      await sheet.screenshot({ path: join(out, `${name}-${theme}.png`), fullPage: true });
      await sheet.close();
      console.log(`${name}-${theme}.png`);
    }
  }
} finally {
  await browser.close();
  await server.close();
}
