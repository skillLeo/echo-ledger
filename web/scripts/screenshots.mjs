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
// A name for the account in the file names (web-settings-plan-free-…), for runs with another EMAIL.
const suffix = process.env.SUFFIX ? `-${process.env.SUFFIX}` : '';

// Pages by name. A function gets the page and resolves its path (for records whose ids change per database). `run`
// is JavaScript run once the page has loaded (a menu opened, a key pressed); `full: false` keeps the window's height.
const pages = {
    login: { path: '/app/login', guest: true },
    // The auth pages (since v1.6.2): each step of the sign-in and sign-up card, and the account pages on the site.
    // `steps` are run one after another as a guest; `link` reads a signed address from AUTH_LINKS (a JSON file the
    // tour's tinker step writes: reset, invitation, device). Turnstile's test keys make the check pass by itself.
    register: { path: '/app/register', guest: true },
    'login-password': { path: '/app/login', guest: true, steps: [{ fill: ['input[type=email]', email] }, { click: 'button[type=submit]', wait: 'input[type=password]' }] },
    'login-check': { path: '/app/login', guest: true, steps: [{ fill: ['input[type=email]', 'new.person@example.com'] }, { click: 'button[type=submit]', wait: '[data-turnstile]' }] },
    'register-code': { path: '/app/register', guest: true, steps: [{ fill: ['input[type=email]', 'new.{run}@example.com'] }, { pause: 2500 }, { click: 'button[type=submit]', wait: 'input[autocomplete=one-time-code]:visible' }] },
    'register-profile': { path: '/app/register', guest: true, steps: [{ fill: ['input[type=email]', 'profile.{run}@example.com'] }, { pause: 2500 }, { click: 'button[type=submit]', wait: 'input[autocomplete=one-time-code]' }, { code: 'input[autocomplete=one-time-code]' }, { click: 'button[type=submit]', wait: 'input[autocomplete=name]' }] },
    'two-step': { path: '/app/login', guest: true, steps: [{ fill: ['input[type=email]', email] }, { click: 'button[type=submit]', wait: 'input[type=password]' }, { fill: ['input[type=password]', password] }, { click: 'button[type=submit]', wait: 'input[autocomplete=one-time-code]:visible' }] },
    'password-reset': { path: '/app/password-reset/request', guest: true },
    'password-reset-new': { link: 'reset', guest: true },
    invitation: { link: 'invitation' },
    'account-delete': { path: '/account/delete', guest: true },
    'device-sign-in': { link: 'device' },
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
    workspace: { path: '/app/workspace' },
    // Settings (since v1.7). Per account: EMAIL=free@echoledger.local SUFFIX=free (php artisan db:seed --class=SettingsTourSeeder).
    'settings-overview': { path: '/app/settings' },
    'settings-plan': { path: '/app/settings/plan' },
    'settings-plan-compare': { path: '/app/settings/plan', run: "document.querySelector('#setting-plan\\.compare').open = true" },
    'settings-language': { path: '/app/settings/language' },
    'settings-key': { path: '/app/settings/transcription-key' },
    'settings-appearance': { path: '/app/settings/appearance' },
    'settings-notifications': { path: '/app/settings/notifications' },
    'settings-storage': { path: '/app/settings/storage' },
    'settings-shortcuts': { path: '/app/settings/shortcuts' },
    'settings-profile': { path: '/app/settings/profile' },
    'settings-security': { path: '/app/settings/security' },
    'settings-activity': { path: '/app/settings/security/activity' },
    'settings-devices': { path: '/app/settings/devices' },
    'settings-privacy': { path: '/app/settings/privacy' },
    'settings-help': { path: '/app/settings/help' },
    'settings-search': { path: '/app/settings', type: { selector: '[data-settings-search]', text: 'dark' } },
    'settings-search-payment': { path: '/app/settings', type: { selector: '[data-settings-search]', text: 'payment' } },
    'settings-search-empty': { path: '/app/settings', type: { selector: '[data-settings-search]', text: 'xyzzy' } },
    'settings-highlight': { path: '/app/settings/appearance?highlight=appearance.theme', full: false, settle: 1400 },
    'palette-settings': { path: '/app', type: { selector: '.fi-global-search-field input', text: 'dark mode' }, full: false },
    // The upgrade flow: the account's newest request (the tour's stage accounts have one each).
    'upgrade-choose': { path: '/app/settings/plan/upgrade' },
    'upgrade-pay': { path: async (page) => requestPath(page, '/pay') },
    'upgrade-receipt': { path: async (page) => requestPath(page, '/receipt') },
    'upgrade-status': { path: async (page) => requestPath(page, '') },
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

// The address of the account's newest upgrade request (from Plan & usage), plus [$tail]; '' when it has none.
async function requestPath(page, tail) {
    return page.evaluate(async ([url, tail]) => {
        const html = await (await fetch(url)).text();
        const match = html.match(/\/app\/settings\/plan\/requests\/([0-9a-z]{26})/i);
        return match ? `/app/settings/plan/requests/${match[1]}${tail}` : '';
    }, [`${base}/app/settings/plan`, tail]);
}

// The first Library row whose title matches (the demo data: php artisan db:seed --class=ConsoleTourSeeder).
async function recording(page, title) {
    return page.evaluate(async (url) => {
        const html = await (await fetch(url)).text();
        const match = html.match(/data-recording="([0-9A-Za-z]{26})"/);
        return match ? match[1] : '';
    }, `${base}/app/sessions?search=${encodeURIComponent(title)}`);
}

// Signed addresses for the auth pages (reset, invitation, device sign-in), relative to BASE_URL.
function authLinks() {
    try {
        return JSON.parse(readFileSync(process.env.AUTH_LINKS ?? '', 'utf8'));
    } catch {
        return {};
    }
}

// The newest sign-in code in the local mail log (MAIL_MAILER=log): sample accounts only.
function latestCode() {
    const log = readFileSync(resolve(here, '../storage/logs/laravel.log'), 'utf8');
    const codes = [...log.matchAll(/Enter this code in EchoLedger:[\s\S]*?\b(\d{6})\b/g)].map((m) => m[1]);
    return codes[codes.length - 1] ?? '000000';
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
            const path = spec.link ? authLinks()[spec.link] : (typeof spec.path === 'function' ? await spec.path(page) : spec.path);
            if (path === undefined || path === '') continue;
            const file = `${out}/web-${name}${suffix}-${width}-${theme}.png`;
            if (spec.guest) {
                const guest = await browser.newContext({ viewport: { width, height: 844 }, colorScheme: theme, deviceScaleFactor: width < 600 ? 1.5 : 1 });
                const gp = await guest.newPage();
                // 'load', not 'networkidle': Turnstile's frame keeps talking to Cloudflare.
                await gp.goto(base + path, { waitUntil: 'load' });
                await gp.waitForTimeout(1500);
                for (const step of spec.steps ?? []) {
                    // {run}: a sample address of its own per width and theme (a code is sent once a minute per address).
                    if (step.fill) await gp.fill(step.fill[0], step.fill[1].replace('{run}', `${width}${theme}`));
                    if (step.pause) await gp.waitForTimeout(step.pause);
                    if (step.code) await gp.fill(step.code, latestCode());
                    if (step.click) await gp.click(step.click);
                    if (step.wait) await gp.waitForSelector(step.wait, { timeout: 15000 });
                    await gp.waitForTimeout(600);
                }
                await gp.waitForTimeout(1200);
                await gp.screenshot({ path: file, fullPage: true, animations: 'disabled' });
                await guest.close();
                // The sign-in allows a few tries a minute per network: STEP_PAUSE_MS spaces the pages with steps.
                if (spec.steps) await new Promise((r) => setTimeout(r, Number(process.env.STEP_PAUSE_MS ?? 0)));
            } else {
                await page.goto(base + path, { waitUntil: spec.link ? 'load' : 'networkidle' });
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
                await page.waitForTimeout(spec.settle ?? 900);
                // A whole-page picture: the flow's step bar, sticky on a phone, is drawn where it sits in the page.
                if (spec.full !== false) await page.addStyleTag({ content: '.el-flow-bar{position:static!important}' });
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
