// Screenshots of the console pages in light and dark, wide and narrow, for docs/design/screens/web/.
//
//   php artisan db:seed && php artisan db:seed --class=ConsoleTourSeeder   (APP_ENV=local, a local SQLite file)
//   php artisan serve --port=8765            (a local or test database, never production)
//   node scripts/screenshots.mjs             (or: BASE_URL=... EMAIL=... PASSWORD=... ONLY=library,clients)
//
// Needs Playwright: `npm i --no-save playwright-core` here, or PLAYWRIGHT_MODULE pointing at an installed copy.
// CHROMIUM_PATH picks a browser when Playwright's own download is not there. With sharp installed (or SHARP_MODULE)
// the pictures are saved as palette PNGs, about a third of the size.
import { createRequire } from 'node:module';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE ?? 'playwright-core');
let sharp = null;
try {
    sharp = require(process.env.SHARP_MODULE ?? 'sharp');
} catch {
    // Plain PNGs then.
}

async function shrink(file) {
    if (sharp === null) return;
    const input = readFileSync(file);
    const output = await sharp(input).png({ palette: true, quality: 95, effort: 10, compressionLevel: 9 }).toBuffer();
    if (output.length < input.length) writeFileSync(file, output);
}

const base = (process.env.BASE_URL ?? 'http://127.0.0.1:8765').replace(/\/$/, '');
const email = process.env.EMAIL ?? 'owner@echoledger.local';
const password = process.env.PASSWORD ?? 'change-me-now-please';
const out = resolve(process.env.OUT_DIR ?? resolve(here, '../../docs/design/screens/web'));
const only = (process.env.ONLY ?? '').split(',').filter(Boolean);
const widths = (process.env.WIDTHS ?? '1440,390').split(',').map(Number);
const themes = (process.env.THEMES ?? 'light,dark').split(',');

// Pages by name. A function gets the page and resolves its path (for records whose ids change per database).
const pages = {
    login: { path: '/admin/login', guest: true },
    dashboard: { path: '/admin' },
    library: { path: '/admin/sessions' },
    'library-detail': { path: async (page) => `/admin/sessions?selected=${await recording(page, 'Retainer check-in')}` },
    'library-empty': { path: '/admin/sessions?search=zzzz-nothing-matches' },
    recording: { path: async (page) => `/admin/sessions/${await recording(page, 'Retainer check-in')}` },
    'recording-urdu': { path: async (page) => `/admin/sessions/${await recording(page, 'Invoice questions')}` },
    upload: { path: '/admin/upload' },
    activity: { path: '/admin/activity' },
    clients: { path: '/admin/clients' },
    client: { path: async (page) => `/admin/clients/${await firstClient(page)}` },
    'client-files': { path: async (page) => `/admin/clients/${await firstClient(page)}?tab=files` },
    'client-ask': { path: async (page) => `/admin/clients/${await firstClient(page)}?tab=ask` },
    'client-recordings': { path: async (page) => `/admin/clients/${await firstClient(page)}?tab=recordings` },
    search: { path: '/admin/search' },
    devices: { path: '/admin/devices' },
    'api-credentials': { path: '/admin/api-credentials' },
    settings: { path: '/admin/settings' },
    // The Recording tab holds "Always double-check recordings filed under a client" and the client AI budget.
    'settings-recording': { path: '/admin/settings', click: '.fi-tabs-item:has-text("Recording")' },
    usage: { path: '/admin/usage' },
    security: { path: '/admin/security' },
    workspace: { path: '/admin/workspace' },
    'recently-deleted': { path: '/admin/recently-deleted' },
};

// The first Library row whose title matches (the demo data: php artisan db:seed --class=ConsoleTourSeeder).
async function recording(page, title) {
    return page.evaluate(async (url) => {
        const html = await (await fetch(url)).text();
        const match = html.match(/data-recording="([0-9A-Za-z]{26})"/);
        return match ? match[1] : '';
    }, `${base}/admin/sessions?search=${encodeURIComponent(title)}`);
}

async function firstClient(page) {
    return page.evaluate(async (url) => {
        const html = await (await fetch(url)).text();
        const match = html.match(/\/admin\/clients\/([0-9A-Za-z]{26})(?!\/edit)/);
        return match ? match[1] : '';
    }, `${base}/admin/clients`);
}

mkdirSync(out, { recursive: true });
const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});

for (const theme of themes) {
    for (const width of widths) {
        const context = await browser.newContext({
            viewport: { width, height: width < 600 ? 844 : 900 },
            deviceScaleFactor: width < 600 ? 1.5 : 1,
            colorScheme: theme,
        });
        // Filament keeps the chosen theme in localStorage; "system" follows the colour scheme above.
        await context.addInitScript(() => localStorage.setItem('filament:theme:mode', 'system'));
        const page = await context.newPage();
        await page.goto(`${base}/admin/login`);
        await page.fill('input[type=email]', email);
        await page.fill('input[type=password]', password);
        await Promise.all([page.waitForURL((url) => !url.pathname.endsWith('/login')), page.click('button[type=submit]')]);

        for (const [name, spec] of Object.entries(pages)) {
            if (only.length && !only.includes(name)) continue;
            const path = typeof spec.path === 'function' ? await spec.path(page) : spec.path;
            const file = `${out}/web-${name}-${width}-${theme}.png`;
            if (spec.guest) {
                const guest = await browser.newContext({ viewport: { width, height: 844 }, colorScheme: theme, deviceScaleFactor: width < 600 ? 1.5 : 1 });
                const gp = await guest.newPage();
                await gp.goto(base + path, { waitUntil: 'networkidle' });
                await gp.screenshot({ path: file, fullPage: true, animations: 'disabled' });
                await guest.close();
            } else {
                await page.goto(base + path, { waitUntil: 'networkidle' });
                if (spec.click) {
                    await page.click(spec.click);
                    await page.waitForLoadState('networkidle');
                }
                // Alpine fades the sidebar labels in; give it time so every picture shows the settled page.
                await page.waitForTimeout(900);
                await page.screenshot({ path: file, fullPage: spec.full !== false, animations: 'disabled' });
            }
            await shrink(file);
            console.log(file.split('/').pop());
        }
        await context.close();
    }
}
await browser.close();
