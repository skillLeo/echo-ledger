// Screenshots of the console pages in light and dark, wide and narrow, for docs/design/screens/web/.
//
//   php artisan db:seed && php artisan db:seed --class=ConsoleTourSeeder   (APP_ENV=local, a local SQLite file)
//   php artisan serve --port=8765            (a local or test database, never production)
//   node scripts/screenshots.mjs             (or: BASE_URL=... EMAIL=... PASSWORD=... ONLY=library,projects)
//   The admin pages need staff with two-step sign-in: TOTP_SECRET=<the local account's base32 secret> (sample only).
//
// Needs Playwright: `npm i --no-save playwright-core` here, or PLAYWRIGHT_MODULE pointing at an installed copy.
// CHROMIUM_PATH picks a browser when Playwright's own download is not there. With sharp installed (or SHARP_MODULE)
// the pictures are saved as palette PNGs, about a third of the size.
import { createHmac } from 'node:crypto';
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

// Pages by name. A function gets the page and resolves its path (for records whose ids change per database). `run`
// is JavaScript run once the page has loaded (a menu opened, a key pressed); `full: false` keeps the window's height.
const pages = {
    login: { path: '/app/login', guest: true },
    home: { path: '/app' },
    library: { path: '/app/sessions' },
    'library-drawer': { path: '/app/sessions', waitFor: '.el-row', run: "document.querySelector('.el-row')?.click()", full: false, wide: true },
    'library-table': { path: '/app/sessions', run: "document.querySelector('[data-layout=table]')?.click()", after: "document.querySelector('[data-layout=list]')?.click()", wide: true },
    'library-folder': { path: '/app/sessions?folder=whatsapp' },
    'library-failed': { path: '/app/sessions?tab=failed' },
    'library-empty': { path: '/app/sessions?search=zzzz-nothing-matches' },
    recording: { path: async (page) => `/app/sessions/${await recording(page, 'Retainer check-in')}` },
    'recording-urdu': { path: async (page) => `/app/sessions/${await recording(page, 'Invoice questions')}` },
    upload: { path: '/app/upload' },
    record: { path: '/app/upload?record=1' },
    activity: { path: '/app/activity' },
    projects: { path: '/app/clients' },
    project: { path: async (page) => `/app/clients/${await firstClient(page)}` },
    'project-files': { path: async (page) => `/app/clients/${await firstClient(page)}?tab=files` },
    'project-ask': { path: async (page) => `/app/clients/${await firstClient(page)}?tab=ask` },
    'project-recordings': { path: async (page) => `/app/clients/${await firstClient(page)}?tab=recordings` },
    'project-screens': { path: async (page) => `/app/clients/${await firstClient(page)}?tab=screens` },
    'shared-with-me': { path: '/app/shared-with-me' },
    'my-links': { path: '/app/shares' },
    'request-links': { path: '/app/request-links' },
    search: { path: '/app/search' },
    'recently-deleted': { path: '/app/recently-deleted' },
    'get-the-apps': { path: '/app/get-the-apps' },
    devices: { path: '/app/devices' },
    usage: { path: '/app/usage' },
    security: { path: '/app/security' },
    workspace: { path: '/app/workspace' },
    'account-profile': { path: '/app/profile?tab=profile' },
    'account-sign-in': { path: '/app/profile?tab=sign-in' },
    'account-devices': { path: '/app/profile?tab=devices' },
    'account-notifications': { path: '/app/profile?tab=notifications' },
    'account-preferences': { path: '/app/profile?tab=preferences' },
    'account-plan': { path: '/app/profile?tab=plan' },
    'account-privacy': { path: '/app/profile?tab=privacy' },
    // The shell itself: the rail, Find a page, the palette, the + menu, the avatar menu, Help & shortcuts.
    'shell-rail': { path: '/app/sessions', run: "Alpine.store('sidebar').close()", after: "Alpine.store('sidebar').open()", full: false, wide: true },
    'shell-find': { path: '/app', run: "(() => { const f = document.querySelector('.el-sb-find-box input'); f.focus(); f.value = 'dev'; f.dispatchEvent(new Event('input', { bubbles: true })); })()", full: false, wide: true },
    'shell-palette': { path: '/app', type: { selector: '.fi-global-search-field input', text: 'up' }, full: false },
    'shell-create': { path: '/app', click: '.el-tb-create', full: false, wide: true },
    'shell-avatar': { path: '/app', click: '.el-tb .fi-user-menu-trigger', full: false },
    'shell-help': { path: '/app', run: "window.dispatchEvent(new CustomEvent('el-help'))", full: false },
    'shell-more': { path: '/app', run: "Alpine.store('sidebar').open()", full: false, narrow: true },
    // The admin panel (staff, two-step sign-in on).
    'admin-overview': { path: '/admin' },
    'admin-customers': { path: '/admin/customers' },
    'admin-customer-peek': { path: '/admin/customers', run: "document.querySelector('.fi-ta-row button.fi-ta-col')?.click()", full: false, wide: true },
    'admin-customer': { path: async (page) => `/admin/customers/${await firstCustomer(page)}` },
    'admin-plans': { path: '/admin/plans' },
    'admin-upgrade-requests': { path: '/admin/upgrade-requests' },
    'admin-usage': { path: '/admin/server-usage' },
    'admin-sign-up': { path: '/admin/sign-up' },
    'admin-staff': { path: '/admin/staff' },
    'api-credentials': { path: '/admin/api-credentials' },
    settings: { path: '/admin/settings' },
    // The Recording tab holds "Always double-check recordings filed under a project" and the AI budget for projects.
    'settings-recording': { path: '/admin/settings', click: '.fi-tabs-item:has-text("Recording")' },
};

// The first Library row whose title matches (the demo data: php artisan db:seed --class=ConsoleTourSeeder).
async function recording(page, title) {
    return page.evaluate(async (url) => {
        const html = await (await fetch(url)).text();
        const match = html.match(/data-recording="([0-9A-Za-z]{26})"/);
        return match ? match[1] : '';
    }, `${base}/app/sessions?search=${encodeURIComponent(title)}`);
}

async function firstCustomer(page) {
    return page.evaluate(async (url) => {
        const html = await (await fetch(url)).text();
        const match = html.match(/data-customer-peek="(\d+)"|\/admin\/customers\/(\d+)/);
        return match ? (match[1] ?? match[2]) : '1';
    }, `${base}/admin/customers`);
}

// A time-based code for staff's two-step sign-in (RFC 6238, SHA-1, 30 s), from TOTP_SECRET in base32.
function totp(secret) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of secret.replace(/=+$/, '').toUpperCase()) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
    const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
    const hmac = createHmac('sha1', key).update(counter).digest();
    const offset = hmac[hmac.length - 1] & 15;
    return String((hmac.readUInt32BE(offset) & 0x7fffffff) % 1000000).padStart(6, '0');
}

async function firstClient(page) {
    return page.evaluate(async (url) => {
        const html = await (await fetch(url)).text();
        const match = html.match(/\/app\/clients\/([0-9A-Za-z]{26})(?!\/edit)/);
        return match ? match[1] : '';
    }, `${base}/app/clients`);
}

mkdirSync(out, { recursive: true });
const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});
let signedIn = null;

for (const theme of themes) {
    for (const width of widths) {
        const context = await browser.newContext({
            viewport: { width, height: width < 600 ? 844 : 900 },
            deviceScaleFactor: width < 600 ? 1.5 : 1,
            isMobile: width < 600,
            hasTouch: width < 600,
            colorScheme: theme,
            ...(signedIn !== null ? { storageState: signedIn } : {}),
        });
        // Filament keeps the chosen theme in localStorage; "system" follows the colour scheme above.
        await context.addInitScript(() => localStorage.setItem('filament:theme:mode', 'system'));
        const page = await context.newPage();
        // One sign-in for the whole run (the console limits sign-in attempts a minute), reused by every context.
        if (signedIn === null) {
            await page.goto(`${base}/app/login`);
            // Since v1.6 the sign-in asks for the email first, then the password.
            await page.fill('input[type=email]', email);
            await page.click('button[type=submit]');
            await page.waitForSelector('input[type=password]');
            await page.fill('input[type=password]', password);
            await page.click('button[type=submit]');
            // Staff with two-step sign-in are asked for the code next.
            const otp = 'input[autocomplete=one-time-code]:visible';
            await page.waitForFunction(() => !location.pathname.endsWith('/login')
                || [...document.querySelectorAll('input[autocomplete=one-time-code]')].some((i) => i.offsetParent !== null));
            if (page.url().endsWith('/login') && process.env.TOTP_SECRET) {
                // Filament's code field is one box per digit: type the code from the first box (the last digit sends it).
                await page.locator(otp).first().click();
                await page.keyboard.type(totp(process.env.TOTP_SECRET), { delay: 50 });
            }
            await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30000 });
            await page.goto(`${base}/app`, { waitUntil: 'networkidle' });
            if (page.url().endsWith('/login')) throw new Error('Not signed in: check EMAIL, PASSWORD and TOTP_SECRET, or wait a minute.');
            signedIn = await context.storageState();
        }

        for (const [name, spec] of Object.entries(pages)) {
            if (only.length && !only.includes(name)) continue;
            if ((spec.wide && width < 600) || (spec.narrow && width >= 600)) continue;
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
                if (spec.full !== false) {
                    // Widgets below the fold load when they are scrolled to.
                    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
                    await page.waitForTimeout(1500);
                    await page.waitForLoadState('networkidle');
                    await page.evaluate(() => window.scrollTo(0, 0));
                }
                if (spec.waitFor) await page.waitForSelector(spec.waitFor, { timeout: 15000 }).catch(() => {});
                if (spec.run) {
                    await page.evaluate(spec.run).catch((e) => console.log(`${name}: ${e.message.split('\n')[0]}`));
                    await page.waitForTimeout(400);
                    await page.waitForLoadState('networkidle');
                }
                if (spec.type) {
                    await page.click(spec.type.selector);
                    await page.keyboard.type(spec.type.text, { delay: 60 });
                    await page.waitForTimeout(1200);
                    await page.waitForLoadState('networkidle');
                }
                // Alpine fades the sidebar labels in; give it time so every picture shows the settled page.
                await page.waitForTimeout(900);
                await page.screenshot({ path: file, fullPage: spec.full !== false, animations: 'disabled' });
                if (spec.after) {
                    await page.evaluate(spec.after);
                    await page.waitForTimeout(600);
                    await page.waitForLoadState('networkidle');
                }
            }
            await shrink(file);
            console.log(file.split('/').pop());
        }
        await context.close();
    }
}
await browser.close();
