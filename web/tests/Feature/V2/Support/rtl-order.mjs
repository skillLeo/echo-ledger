// W-203 / W-209: Usage and a project's pages inside the Urdu (right-to-left) frame, every project tab, at 720 and
// 1280 px. It finds English text drawn out of its own order (its first character drawn after its last on one line:
// "B 68.0" for 68.0 B, a sentence's full stop first) and Urdu sentences turned left to right (a sentence that starts
// with a name, "Qureshi Textiles میں تلاش کریں", drawn with the name at the left end). Fails when any is found.
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

const pages = { usage: '/app/usage', ...Object.fromEntries(['overview', 'recordings', 'screens', 'files', 'ask', 'rules']
    .map((tab) => [`project-${tab}`, `/app/clients/${process.env.PROJECT}?tab=${tab}`])) };
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
                // The first and last characters drawn: spaces and the invisible isolate marks (U+2066-U+2069) don't count.
                const start = raw.search(/[^\s\u2066-\u2069]/), end = raw.search(/[^\s\u2066-\u2069][\s\u2066-\u2069]*$/);
                const el = t.parentElement;
                if (start < 0 || end - start < 2 || !el) continue;
                const urdu = ARABIC.test(raw);
                if (!urdu && !/[A-Za-z0-9]/.test(raw)) continue;
                const r0 = el.getBoundingClientRect();
                if (r0.width < 2 || r0.height < 2 || getComputedStyle(el).visibility === 'hidden') continue;
                const box = (i) => { const r = document.createRange(); r.setStart(t, i); r.setEnd(t, i + 1); return r.getClientRects()[0]; };
                const first = box(start), last = box(end);
                if (!first || !last || Math.abs(first.top - last.top) >= 4) continue;
                // English reads left to right (first char at the left); an Urdu sentence right to left (first at the right).
                // A line of values ("18:24 · Qureshi Textiles") follows the page's order; each value keeps its own.
                if (!urdu && /\s·\s/.test(raw)) {
                    let at = 0;
                    for (const part of raw.split('·')) {
                        const s = at + part.search(/\S/), e = at + part.search(/\S\s*$/);
                        at += part.length + 1;
                        if (s < at - part.length - 1 || e - s < 2 || !/[A-Za-z]/.test(part)) continue;
                        const a = box(s), z = box(e);
                        if (a && z && Math.abs(a.top - z.top) < 4 && a.left > z.left + 1) out.push(part.trim().slice(0, 50));
                    }
                    continue;
                }
                if (!urdu && first.left > last.left + 1) out.push(raw.trim().slice(0, 50));
                if (urdu && first.left + 1 < last.left) out.push(`turned LTR: ${raw.trim().slice(0, 50)}`);
            }
            for (const input of main.querySelectorAll('input[placeholder], textarea[placeholder]')) {
                const ph = input.getAttribute('placeholder');
                if (!ARABIC.test(ph) || !/^[\u2068]?[^\u0600-\u06FF]*[A-Za-z]/.test(ph)) continue;
                // A placeholder that starts with a name: drawn by its own first letter unless the name is isolated.
                if (!ph.startsWith('\u2068') && input.getAttribute('dir') !== 'rtl') out.push(`placeholder turned LTR: ${ph.slice(0, 50)}`);
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
