// W-203: the classic pages still in English inside the Urdu (right-to-left) frame: Usage and a project's pages. In a
// browser at 720 and 1280 px it finds English text drawn out of its own order: its first character drawn after its
// last on one line ("B 68.0" for 68.0 B, "min · no limit 0.0", a sentence's full stop first). Fails when any is found.
//
//   A local server with the parity world (tests/Feature/V2/Support/parity_world.php) in a local SQLite file, never
//   production, the new frame on and the person's console in Urdu:
//     BASE_URL=http://127.0.0.1:8793 EMAIL=... PASSWORD=... PROJECT=<client id> node rtl-order.mjs
//   Needs Playwright (PLAYWRIGHT_MODULE).
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE ?? 'playwright-core');
const base = (process.env.BASE_URL ?? 'http://127.0.0.1:8793').replace(/\/$/, '');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const page = await (await browser.newContext()).newPage();
await page.goto(`${base}/app/login`);
await page.fill('input[type=email]', process.env.EMAIL);
await page.click('button[type=submit]');
await page.waitForTimeout(2000);
await page.fill('input[type=password]', process.env.PASSWORD);
await page.locator('button[type=submit]').last().click();
await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 30000 });

const pages = { usage: '/app/usage', project: `/app/clients/${process.env.PROJECT}` };
let bad = 0;
for (const [name, path] of Object.entries(pages)) {
    for (const width of [720, 1280]) {
        await page.setViewportSize({ width, height: 1200 });
        await page.goto(base + path);
        await page.waitForLoadState('networkidle', { timeout: 6000 }).catch(() => {});
        await page.waitForTimeout(500);
        const found = await page.evaluate(() => {
            const ARABIC = /[؀-ۿݐ-ݿﭐ-﷿ﹰ-﻿]/;
            const main = document.querySelector('main') ?? document.body;
            const out = [];
            const walker = document.createTreeWalker(main, NodeFilter.SHOW_TEXT);
            for (let t = walker.nextNode(); t; t = walker.nextNode()) {
                const raw = t.textContent;
                const start = raw.search(/\S/), end = raw.search(/\S\s*$/);
                const el = t.parentElement;
                if (start < 0 || end - start < 2 || !/[A-Za-z0-9]/.test(raw) || ARABIC.test(raw) || !el) continue;
                const r0 = el.getBoundingClientRect();
                if (r0.width < 2 || r0.height < 2 || getComputedStyle(el).visibility === 'hidden') continue;
                const box = (i) => { const r = document.createRange(); r.setStart(t, i); r.setEnd(t, i + 1); return r.getClientRects()[0]; };
                const first = box(start), last = box(end);
                if (first && last && Math.abs(first.top - last.top) < 4 && first.left > last.left + 1) out.push(raw.trim().slice(0, 50));
            }
            return { dir: document.documentElement.dir, root: document.querySelector('.fi-page')?.className ?? '', out: [...new Set(out)] };
        });
        if (found.out.length) bad++;
        console.log(JSON.stringify({ page: name, width, ...found }));
    }
}
await browser.close();
if (bad > 0) {
    console.error(`${bad} page(s) with English text out of its own order`);
    process.exit(1);
}
