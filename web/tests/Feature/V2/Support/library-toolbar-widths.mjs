// W-202: the classic Library toolbar (Show, Folder, List / Table, Sort by, View) measured in a browser at 768-1280 px,
// light theme, the classic frame. It fails when the toolbar has to scroll sideways (a control cut at its edge, as
// "Sort by" was) or when a control breaks inside itself (List / Table stacked onto two lines).
//
//   A local server with the parity world (tests/Feature/V2/Support/parity_world.php) in a local SQLite file, never
//   production, the new frame off:
//     BASE_URL=http://127.0.0.1:8793 EMAIL=... PASSWORD=... node library-toolbar-widths.mjs
//   Needs Playwright (PLAYWRIGHT_MODULE).
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE ?? 'playwright-core');
const base = (process.env.BASE_URL ?? 'http://127.0.0.1:8793').replace(/\/$/, '');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const context = await browser.newContext();
const page = await context.newPage();
await page.goto(`${base}/app/login`);
await page.fill('input[type=email]', process.env.EMAIL);
await page.click('button[type=submit]');
await page.waitForTimeout(2000);
await page.fill('input[type=password]', process.env.PASSWORD);
await page.getByRole('button', { name: 'Sign in', exact: true }).click();
await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 30000 });

let bad = 0;
for (const width of [768, 820, 900, 1024, 1180, 1280]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(`${base}/app/sessions`);
    await page.waitForLoadState('networkidle', { timeout: 6000 }).catch(() => {});
    await page.waitForTimeout(400);
    const m = await page.evaluate(() => {
        const bar = document.querySelector('.el-library-toolbar');
        if (!bar) return { missing: true };
        const controls = [...bar.querySelectorAll(':scope > .el-tool, :scope > .el-seg, :scope > [wire\\:key] .el-tool, :scope > div > .el-tool')]
            .filter((el) => el.getBoundingClientRect().width > 0);
        // A control "breaks" when its content needs more than one line of controls (List / Table stacked).
        const broken = controls.filter((el) => {
            const kids = [...el.children].filter((c) => c.getBoundingClientRect().width > 0);
            const tops = new Set(kids.map((c) => Math.round(c.getBoundingClientRect().top / 4)));
            return tops.size > 1 || el.getBoundingClientRect().height > 44;
        }).map((el) => el.getAttribute('data-menu') ?? el.className.split(' ').slice(0, 2).join('.'));
        const box = bar.getBoundingClientRect();
        const cut = controls.filter((el) => { const r = el.getBoundingClientRect(); return r.right > box.right + 1 || r.left < box.left - 1; })
            .map((el) => el.getAttribute('data-menu') ?? el.className.split(' ').slice(0, 2).join('.'));
        return { available: bar.clientWidth, needs: bar.scrollWidth, scrolls: bar.scrollWidth > bar.clientWidth + 1, cut, broken, rows: new Set(controls.map((el) => Math.round(el.getBoundingClientRect().top / 8))).size };
    });
    const fail = m.missing || m.scrolls || m.cut.length > 0 || m.broken.length > 0;
    if (fail) bad++;
    console.log(JSON.stringify({ width, ...m, ok: !fail }));
}
await browser.close();
if (bad > 0) {
    console.error(`${bad} width(s) where the toolbar scrolls, cuts a control or breaks one`);
    process.exit(1);
}
