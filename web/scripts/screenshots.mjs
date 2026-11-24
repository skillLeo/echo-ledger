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

/** JavaScript that right-clicks the Library's row [at] a little in from its start (UX-22's menu). */
const menuOn = (at) => `(() => { const row = document.querySelectorAll('.v2-library .v2-lib-row')[${at}]; const r = row.getBoundingClientRect();`
    + ` row.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: r.left + r.width * 0.3, clientY: r.top + r.height / 2 })); })()`;

/**
 * UX-25: the Library keeps your place, in a short window so that a few recordings already scroll. Back to top shows a
 * screen down, goes to the top and puts the focus on the first row; typing in the search while the list comes back from
 * a recording keeps the focus in the search; down the list, a recording opened and the browser's Back bring the same
 * place, the opened row's grey tint and the focus on it (left so for the picture). Answers what went wrong.
 */
async function keepPlace(page, base) {
    const wrong = [];
    // A busy machine answers slowly, and a row still moving keeps the list's quiet poll going: no wait for "network idle".
    page.setDefaultTimeout(90000);
    const open = async (url) => { await page.goto(url, { waitUntil: 'load' }); await page.waitForTimeout(1500); };
    const back = async () => { await page.goBack({ waitUntil: 'load' }); await page.waitForTimeout(3000); };
    await page.setViewportSize({ width: page.viewportSize().width, height: 600 });
    const list = `${base}/app/sessions`;
    await open(list);
    await page.waitForSelector('.v2-lib-row');
    const maxY = await page.evaluate(() => document.documentElement.scrollHeight - window.innerHeight);
    if (maxY < 200) return ['the list is too short to scroll even in a short window: seed more recordings'];
    // Back to top.
    await page.evaluate((y) => window.scrollTo(0, y), maxY);
    await page.waitForTimeout(800);
    if (! await page.evaluate(() => document.querySelector('[data-back-top]')?.classList.contains('is-shown'))) wrong.push('Back to top did not show a screen down');
    await page.click('[data-back-top]');
    await page.waitForTimeout(1500);
    const top = await page.evaluate(() => ({ y: Math.round(window.scrollY), first: document.activeElement?.closest('.fi-ta-record') === document.querySelector('.v2-library .fi-ta-record') }));
    if (top.y !== 0) wrong.push(`Back to top left the list at ${top.y} px`);
    if (! top.first) wrong.push('Back to top did not put the focus on the first row');
    // Down the list: the row a third of the way down the window is the one opened.
    await page.evaluate((y) => window.scrollTo(0, y), Math.round(maxY / 2));
    await page.waitForTimeout(800);
    const before = await page.evaluate(() => {
        const row = [...document.querySelectorAll('.v2-library .v2-lib-row')].find((r) => r.getBoundingClientRect().top > window.innerHeight / 3);
        return { y: Math.round(window.scrollY), id: row.dataset.recording, top: Math.round(row.getBoundingClientRect().top) };
    });
    // Typing in the search while the list comes back: the letters stay in the search, the focus too.
    await open(`${list}/${before.id}`);
    await page.goBack({ waitUntil: 'commit' });
    const search = '.v2-library .fi-ta-search-field input';
    await page.waitForSelector(search, { timeout: 15000 });
    await page.click(search);
    await page.keyboard.type('zq', { delay: 40 });
    await page.waitForTimeout(4000);
    const kept = await page.evaluate((sel) => ({ focus: document.activeElement?.matches(sel) === true, value: document.querySelector(sel)?.value ?? '' }), search);
    if (! kept.focus || ! kept.value.includes('zq')) wrong.push(`typing in the search while the list came back lost the focus (${JSON.stringify(kept)})`);
    // Back to the same place, the tint and the focus.
    await open(list);
    await page.waitForSelector('.v2-lib-row');
    await page.evaluate((y) => window.scrollTo(0, y), before.y);
    await page.waitForTimeout(800);
    await open(`${list}/${before.id}`);
    await back();
    await page.mouse.move(2, 2);
    const after = await page.evaluate((id) => {
        const record = document.querySelector(`.v2-lib-row[data-recording="${id}"]`)?.closest('.fi-ta-record');
        return { y: Math.round(window.scrollY), tinted: !! record?.classList.contains('is-last-opened'), focus: !! record?.contains(document.activeElement) };
    }, before.id);
    if (Math.abs(after.y - before.y) > 2) wrong.push(`came back at ${after.y} px, not ${before.y}`);
    if (! after.tinted) wrong.push('the opened row has no tint');
    if (! after.focus) wrong.push('the focus is not on the opened row');

    return wrong;
}

// Pages by name. A function gets the page and resolves its path (for records whose ids change per database). `run`
// is JavaScript run once the page has loaded (a menu opened, a key pressed); `full: false` keeps the window's height.
const pages = {
    login: { path: '/app/login', guest: true },
    // The auth pages (since v1.6.2): each step of the sign-in and sign-up card, and the account pages on the site.
    // `steps` are run one after another as a guest; `link` reads a signed address from AUTH_LINKS (a JSON file the
    // tour's tinker step writes: reset, invitation, device). Turnstile's test keys make the check pass by itself.
    register: { path: '/app/register', guest: true },
    'login-password': { path: '/app/login', guest: true, steps: [{ fill: ['input[type=email]', email] }, { click: 'button[type=submit]', wait: 'input[autocomplete=current-password]' }] },
    'login-check': { path: '/app/login', guest: true, steps: [{ fill: ['input[type=email]', 'new.person@example.com'] }, { click: 'button[type=submit]', wait: '[data-turnstile]' }] },
    'register-code': { path: '/app/register', guest: true, steps: [{ fill: ['input[type=email]', 'new.{run}@example.com'] }, { pause: 2500 }, { click: 'button[type=submit]', wait: 'input[autocomplete=one-time-code]:visible' }] },
    'register-profile': { path: '/app/register', guest: true, steps: [{ fill: ['input[type=email]', 'profile.{run}@example.com'] }, { pause: 2500 }, { click: 'button[type=submit]', wait: 'input[autocomplete=one-time-code]' }, { code: 'input[autocomplete=one-time-code]' }, { click: 'button[type=submit]', wait: 'input[autocomplete=name]' }] },
    'two-step': { path: '/app/login', guest: true, steps: [{ fill: ['input[type=email]', email] }, { click: 'button[type=submit]', wait: 'input[autocomplete=current-password]' }, { fill: ['input[autocomplete=current-password]', password] }, { click: 'button[type=submit]', wait: 'input[autocomplete=one-time-code]:visible' }] },
    'password-reset': { path: '/app/password-reset/request', guest: true },
    'password-reset-new': { link: 'reset', guest: true },
    invitation: { link: 'invitation' },
    'account-delete': { path: '/account/delete', guest: true },
    'device-sign-in': { link: 'device' },
    home: { path: '/app' },
    library: { path: '/app/sessions' },
    'library-drawer': { path: '/app/sessions', waitFor: '.el-row', run: "document.querySelector('.el-row')?.click()", full: false, wide: true },
    // UX-25: the place kept coming back from a recording, the opened row's tint, Back to top (keepPlace's checks).
    'library-keep-place': { path: '/app/sessions', waitFor: '.v2-lib-row', full: false, wide: true, scenario: keepPlace, settle: 300 },
    'library-table': { path: '/app/sessions', run: "document.querySelector('[data-layout=table]')?.click()", after: "document.querySelector('[data-layout=list]')?.click()", wide: true },
    // UX-22: a row's right-click menu at the pointer, its Labels › sub-menu, and the menu of two selected rows.
    'library-menu': { path: '/app/sessions', waitFor: '.v2-lib-row', run: menuOn(1), full: false, wide: true },
    'library-menu-sub': { path: '/app/sessions', waitFor: '.v2-lib-row', run: `${menuOn(1)};document.querySelector('.v2-ctx [aria-haspopup=menu]').dispatchEvent(new PointerEvent('pointerover', { bubbles: true }))`, full: false, wide: true },
    'library-menu-many': { path: '/app/sessions', waitFor: '.v2-lib-row', run: `[...document.querySelectorAll('.v2-library input.fi-ta-record-checkbox')].slice(0, 2).forEach((b) => b.click());${menuOn(0)}`, full: false, wide: true },
    'library-folder': { path: '/app/sessions?folder=whatsapp' },
    'library-failed': { path: '/app/sessions?tab=failed' },
    'library-empty': { path: '/app/sessions?search=zzzz-nothing-matches' },
    // The Library's filters and views (contract 2.0.0-p): the window as it opens, the whole page, the filter panel open
    // (a drawer under 1280 px), filters applied as pills, and nothing matching.
    // listTop: the guard of docs/PLAN_LIBRARY_FILTERS.md section 4 (below).
    // reach: every one of these is in the rail or can be scrolled to in the open place's panel, and focused from
    // either end, and is then seen, not covered (below).
    'lf-window': { path: '/app/sessions', full: false, listTop: true, reach: ['[data-place]', '[data-new-view]', '[data-label]', '[data-new-label]', '[data-module=library]', '[data-module=projects]', '[data-module=live]', '[data-module=ask]', '[data-module=activity]', '[data-module=settings]', '[data-module=help]', '[data-module=setup]'] },
    // The three levels (docs/PLAN_NAV_3_LEVELS.md): each place with its panel, the flyout, the filters beside or over
    // the list, Settings' inner list, and the browser checks of section 8 (navChecks, below).
    'nav-library': { path: '/app/sessions', full: false, listTop: true, checks: (page, width) => navChecks(page, width) },
    'nav-projects': { path: '/app/clients', full: false, reach: ['[data-projects-view]', '[data-new-project]', '.v2-panels [data-project]'] },
    'nav-live': { path: '/app/live', full: false },
    'nav-ask': { path: '/app/ask', full: false },
    'nav-activity': { path: '/app/activity?show=needs_you', full: false },
    'nav-settings': { path: '/app/settings/profile', full: false },
    // Setup & health, the rail's own place (no panel): Devices and Get the apps as its tabs.
    'nav-setup': { path: '/app/settings/devices', full: false },
    // Under the flyout width (1024): each place folded to the rail, and its panel open over the page from its icon.
    'nav-flyout': { path: '/app/sessions', full: false, click: '[data-rail="library"]' },
    'nav-projects-flyout': { path: '/app/clients', full: false, click: '[data-rail="projects"]' },
    'nav-settings-flyout': { path: '/app/settings', full: false, click: '[data-rail="settings"]' },
    'nav-settings-home': { path: '/app/settings', full: false },
    'nav-filters': { path: '/app/sessions', full: false, run: "window.dispatchEvent(new CustomEvent('el-filters', { detail: { open: true } }))" },
    'nav-folded': { path: '/app/sessions', full: false, run: "document.querySelector('[data-panel-toggle]')?.click()" },
    'nav-tab': { path: '/app/sessions?kind=calls', full: false },
    'lf-full': { path: '/app/sessions' },
    'lf-panel': { path: '/app/sessions', run: "window.dispatchEvent(new CustomEvent('el-filters', { detail: { open: true } }))", full: false },
    'lf-pills': { path: '/app/sessions?f[source][op]=is&f[source][values][0]=whatsapp&f[date][op]=is_not&f[date][values][0]=today', full: false },
    'lf-pill-edit': { path: '/app/sessions?f[source][op]=is&f[source][values][0]=whatsapp', click: '[data-pill=source]', full: false },
    'lf-sort': { path: '/app/sessions', click: '[data-menu=display]', full: false },
    'lf-record': { path: '/app/sessions', click: '[data-record-menu]', full: false },
    'lf-empty': { path: '/app/sessions?f[source][op]=is&f[source][values][0]=discord', full: false },
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
    'settings-plan-compare': { path: '/app/settings/plan', run: "document.querySelector('[data-setting=\"plan.compare\"]').open = true" },
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
    // Diagnostics (since v1.8): the calls the apps saw but did not record (php artisan db:seed --class=ConsoleTourSeeder).
    'admin-missed-recordings': { path: '/admin/missed-recordings' },
    // Diagnostics (since v1.8.1): the screen-share checks; the demo data has none, so this is the empty state.
    'admin-share-checks': { path: '/admin/share-checks' },
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

/**
 * docs/PLAN_NAV_3_LEVELS.md section 8 in a browser, on the Library: a row's source mark filters the list in place; ⌘[
 * goes back in the address and ⌘] forward; the rail's Library icon starts the Library over, and from another place
 * brings you back where you left it; ⌘\\ folds the panel and it stays folded for that place; under the flyout width a
 * rail icon opens the panel over the page and choosing closes it; the filters sit beside the list from 1440 and over it
 * below. Answers what went wrong.
 */
async function navChecks(page, width) {
    const wrong = [];
    const at = () => decodeURIComponent(new URL(page.url()).pathname + new URL(page.url()).search);
    const settle = async () => { await page.waitForLoadState('networkidle'); await page.waitForTimeout(500); };
    const mod = process.platform === 'darwin' ? 'Meta' : 'Control';
    await page.goto(base + '/app/sessions', { waitUntil: 'networkidle' });
    const forget = () => page.evaluate(() => { try { Object.keys(localStorage).filter((k) => k.startsWith('el-nav')).forEach((k) => localStorage.removeItem(k)); } catch (e) {} });
    await forget();
    // The memory is one person's in one workspace: someone else's, and the kind kept before it had a scope, go.
    await page.evaluate(() => { localStorage.setItem('el-nav-last:library', '/app/sessions?tab=unread'); localStorage.setItem('el-nav:000000000000.00000000:last:library', '/app/sessions?tab=important'); });
    await page.reload({ waitUntil: 'networkidle' });
    const memory = await page.evaluate(() => Object.keys(localStorage).filter((k) => k.startsWith('el-nav')));
    if (memory.includes('el-nav-last:library') || memory.includes('el-nav:000000000000.00000000:last:library')) wrong.push(`someone else's memory stayed: ${memory.join(', ')}`);
    if (!memory.some((k) => /^el-nav:[0-9a-f]{12}\.[0-9a-f]{8}:last:library$/.test(k))) wrong.push(`no memory of this place for this person: ${memory.join(', ')}`);
    // Alt+1…9 and ⌘1…⌘6 in a field type there: nothing opens, and the field keeps the focus and what was typed. (A field
    // of its own, put on the page for the check: the Library's search would search for what the keys typed.)
    await page.evaluate(() => { const f = Object.assign(document.createElement('input'), { id: 'el-nav-field', value: 'typed' }); Object.assign(f.style, { position: 'fixed', top: '8px', left: '50%', zIndex: '1' }); document.body.appendChild(f); f.focus(); });
    await page.keyboard.press('Alt+Digit1');
    await page.keyboard.press(mod + '+Digit2');
    await page.waitForTimeout(600);
    const field = await page.evaluate(() => ({ focus: document.activeElement?.id, value: document.getElementById('el-nav-field')?.value }));
    if (at() !== '/app/sessions') wrong.push(`Alt+1 or ${mod}+2 in a field went to ${at()}`);
    if (field.focus !== 'el-nav-field' || !String(field.value).startsWith('typed')) wrong.push(`Alt+1 or ${mod}+2 in a field took the focus or the words: ${JSON.stringify(field)}`);
    await page.evaluate(() => document.getElementById('el-nav-field')?.remove());
    // Each step waits (up to 5 s, on a busy machine) for the address it should reach, then says where it is.
    const reach = (ok) => page.waitForFunction(ok, null, { timeout: 5000 }).catch(() => {});
    const filtered = () => decodeURIComponent(location.search).includes('f[source]');
    // A row's source mark filters; the recording does not open (Livewire has started on the page first).
    await page.waitForFunction(() => window.Livewire !== undefined && document.querySelector('.v2-lib-row [data-quick-source]') !== null, null, { timeout: 10000 }).catch(() => {});
    await page.locator('.v2-lib-row [data-quick-source]').first().click();
    await reach(filtered);
    await settle();
    if (!at().startsWith('/app/sessions?') || !at().includes('f[source]')) wrong.push(`source mark went to ${at()}`);
    // Back and forward from the keyboard.
    await page.evaluate(() => document.activeElement?.blur());
    await page.keyboard.press(mod + '+BracketLeft');
    await reach(() => location.pathname === '/app/sessions' && location.search === '');
    await settle();
    if (at() !== '/app/sessions') wrong.push(`⌘[ left ${at()}`);
    await page.keyboard.press(mod + '+BracketRight');
    await reach(filtered);
    await settle();
    if (!at().includes('f[source]')) wrong.push(`⌘] left ${at()}`);
    // The Library's rail icon from inside the Library starts it over (All recordings, no filter), at every width.
    await page.click('[data-rail="library"]');
    await settle();
    if (at() !== '/app/sessions') wrong.push(`the Library's icon in the Library went to ${at()}`);
    if (width < 1100) {
        const flyout = () => page.evaluate(() => document.querySelector('.v2-frame-inner')?.classList.contains('is-flyout'));
        const state = (key) => page.evaluate((k) => ({ expanded: document.querySelector('[data-rail="' + k + '"]')?.getAttribute('aria-expanded'),
            focus: document.activeElement?.getAttribute('data-rail') ?? document.activeElement?.tagName }), key);
        // Under the flyout width its panel opens over the page too; choosing closes it.
        if (!(await flyout())) wrong.push('starting the Library over did not open its flyout');
        await page.click('.v2-panel[data-panel="library"] [data-place="unread"]');
        await settle();
        if (at() !== '/app/sessions?tab=unread') wrong.push(`choosing in the flyout went to ${at()}`);
        if (await flyout()) wrong.push('the flyout stayed open after choosing');
        // A dialog opened from the flyout (⋮ › Rename on a view) is the flyout's own: Esc closes the dialog, the flyout
        // stays open, and focus comes back to ⋮.
        if (await page.locator('.v2-panel[data-panel="library"] [data-view-menu]').count() > 0) {
            // The Library icon starts it over (All recordings) and opens its flyout on the new page.
            if (!(await flyout())) { await page.click('[data-rail="library"]'); await settle(); }
            await page.waitForFunction(() => document.querySelector('.v2-frame-inner')?.classList.contains('is-flyout'), null, { timeout: 5000 }).catch(() => {});
            const menu = page.locator('.v2-panel[data-panel="library"] [data-view-menu]').first();
            const menuId = await menu.getAttribute('data-view-menu');
            await menu.click();
            await page.locator('.v2-panel[data-panel="library"] [data-view-rename]').first().click();
            await page.waitForFunction(() => [...document.querySelectorAll('.fi-modal-open[aria-modal="true"], [role="dialog"][aria-modal="true"]')].some((el) => el.getClientRects().length > 0), null, { timeout: 5000 }).catch(() => {});
            // The dialog has opened when its name field has the focus (it opens with a short transition).
            await page.waitForFunction(() => document.activeElement?.closest('.fi-modal-open') !== null, null, { timeout: 5000 }).catch(() => {});
            await page.waitForTimeout(300);
            const opened = await flyout();
            await page.keyboard.press('Escape');
            await page.waitForTimeout(600);
            const after = await page.evaluate(() => ({ dialog: [...document.querySelectorAll('.fi-modal-open[aria-modal="true"]')].some((el) => el.getClientRects().length > 0), focus: document.activeElement?.getAttribute('data-view-menu') }));
            if (!opened) wrong.push('opening ⋮ › Rename closed the flyout');
            if (after.dialog) wrong.push('Esc did not close the Rename dialog');
            if (!(await flyout())) wrong.push('Esc in the Rename dialog closed the flyout');
            if (after.focus !== menuId) wrong.push(`after the Rename dialog focus is on ${after.focus ?? 'something else'}, not ⋮`);
            await page.click('.v2-panel[data-panel="library"] [data-panel-toggle]');
            await page.waitForTimeout(300);
        } else {
            wrong.push('no saved view in the Library panel to check ⋮ › Rename with');
        }
        // Another place's icon opens its flyout, expanded; Esc, ⊟ and a click outside close it, focus back on the icon.
        for (const close of ['Escape', 'toggle', 'outside']) {
            await page.click('[data-rail="projects"]');
            await page.waitForTimeout(300);
            const opened = await state('projects');
            if (!(await flyout()) || opened.expanded !== 'true') wrong.push(`Projects' flyout (${close}): open ${await flyout()}, aria-expanded ${opened.expanded}`);
            if (close === 'Escape') await page.keyboard.press('Escape');
            if (close === 'toggle') await page.click('.v2-panel[data-panel="projects"] [data-panel-toggle]');
            // A spot of the page with nothing to focus (a plain box put there for the check, far from the rail: the left
            // in Urdu), so the click leaves the focus nowhere and it comes back to the icon.
            if (close === 'outside') {
                const spot = await page.evaluate(() => {
                    const el = document.createElement('div');
                    el.id = 'el-nav-empty';
                    const rtl = document.documentElement.dir === 'rtl';
                    Object.assign(el.style, { position: 'fixed', bottom: '8px', [rtl ? 'left' : 'right']: '8px', width: '120px', height: '40px', zIndex: '1' });
                    document.body.appendChild(el);
                    const r = el.getBoundingClientRect();
                    return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
                });
                await page.mouse.click(spot.x, spot.y);
                await page.evaluate(() => document.getElementById('el-nav-empty')?.remove());
            }
            await page.waitForTimeout(300);
            const closed = await state('projects');
            if (await flyout()) wrong.push(`the flyout stayed open after ${close}`);
            if (closed.expanded !== 'false' || closed.focus !== 'projects') wrong.push(`after ${close}: aria-expanded ${closed.expanded}, focus on ${closed.focus}`);
        }
        // A click outside on something that takes focus itself keeps it there: audio controls, an element a script
        // made focusable (tabindex -1). Both are put on an empty spot of the page for the check, then removed.
        for (const probe of ['audio', 'focusable']) {
            await page.click('[data-rail="projects"]');
            await page.waitForTimeout(300);
            const box = await page.evaluate((kind) => {
                const el = kind === 'audio' ? Object.assign(document.createElement('audio'), { controls: true }) : Object.assign(document.createElement('div'), { tabIndex: -1, textContent: 'probe' });
                el.id = 'el-nav-probe';
                const rtl = document.documentElement.dir === 'rtl';
                Object.assign(el.style, { position: 'fixed', bottom: '8px', [rtl ? 'left' : 'right']: '8px', width: '240px', height: '40px', zIndex: '1' });
                document.body.appendChild(el);
                const r = el.getBoundingClientRect();
                return { x: r.left + 20, y: r.top + r.height / 2 };
            }, probe);
            await page.mouse.click(box.x, box.y);
            await page.waitForTimeout(300);
            const after = await page.evaluate(() => ({ focus: document.activeElement?.id || document.activeElement?.tagName, open: document.querySelector('.v2-frame-inner')?.classList.contains('is-flyout') }));
            if (after.open) wrong.push(`the flyout stayed open after a click on the ${probe} probe`);
            if (after.focus !== 'el-nav-probe') wrong.push(`a click on the ${probe} probe left focus on ${after.focus}`);
            await page.evaluate(() => document.getElementById('el-nav-probe')?.remove());
        }
        await forget();
        return wrong;
    }
    // Where you left it: Unread, then Projects, then the Library's icon brings Unread back.
    await page.click('[data-place="unread"]');
    await settle();
    await page.click('[data-rail="projects"]');
    await settle();
    if (!at().startsWith('/app/clients')) wrong.push(`Projects' icon went to ${at()}`);
    await page.click('[data-rail="library"]');
    await settle();
    if (at() !== '/app/sessions?tab=unread') wrong.push(`the Library's icon from Projects went to ${at()} (not where it was left)`);
    // ⌘\ folds the panel, and the Library stays folded when you come back.
    await page.evaluate(() => document.activeElement?.blur());
    await page.keyboard.press(mod + '+Backslash');
    await page.waitForTimeout(300);
    const folded = await page.evaluate(() => ! document.querySelector('#fi-main-sidebar')?.classList.contains('fi-sidebar-open'));
    if (!folded) wrong.push('⌘\\ did not fold the panel');
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(400);
    const stillFolded = await page.evaluate(() => ! document.querySelector('#fi-main-sidebar')?.classList.contains('fi-sidebar-open'));
    if (!stillFolded) wrong.push('the Library did not stay folded');
    await page.keyboard.press(mod + '+Backslash');
    await page.waitForTimeout(300);
    // The filters: beside the list (pushing it) from 1440, over it below.
    await page.evaluate(() => window.dispatchEvent(new CustomEvent('el-filters', { detail: { open: true } })));
    await page.waitForTimeout(500);
    const shell = await page.evaluate(() => { const s = document.querySelector('.v2-lib-shell'); return { push: s?.classList.contains('is-pushing'), drawer: s?.classList.contains('is-drawer') }; });
    if (width >= 1440 && !shell.push) wrong.push('the filters do not push the list at this width');
    if (width < 1440 && !shell.drawer) wrong.push('the filters are not over the list at this width');
    await page.evaluate(() => window.dispatchEvent(new CustomEvent('el-filters', { detail: { action: 'close' } })));
    await page.evaluate(() => { try { localStorage.removeItem('el-lib-filters'); } catch (e) {} });
    await forget();
    // Signed out, the login page forgets every place's memory (a fresh window here: no sign-in to lose).
    const guest = await page.context().browser().newContext();
    const login = await guest.newPage();
    await login.goto(base + '/app/login', { waitUntil: 'networkidle' });
    await login.evaluate(() => { localStorage.setItem('el-nav:0123456789ab.01234567:last:library', '/app/sessions?tab=unread'); localStorage.setItem('el-nav-panel:library', 'closed'); });
    await login.reload({ waitUntil: 'networkidle' });
    const left = await login.evaluate(() => Object.keys(localStorage).filter((k) => k.startsWith('el-nav')));
    if (left.length > 0) wrong.push(`the login page kept ${left.join(', ')}`);
    await guest.close();
    return wrong;
}

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
            await page.waitForSelector('input[autocomplete=current-password]');
            await page.fill('input[autocomplete=current-password]', password);
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
                // The Library's list starts within 120 px of the window's top on a computer (docs/PLAN_LIBRARY_FILTERS.md
                // section 4). What counts is the list's first item: the first group's heading, or the first row when the
                // list is not grouped; measured with no filter on (no pills row), from 1024 px wide.
                if (spec.listTop && width >= 1024) {
                    const top = await page.evaluate(() => {
                        const first = document.querySelector('.v2-library .fi-ta-content > *');
                        const row = document.querySelector('.v2-library .v2-lib-row');
                        return { first: first ? Math.round(first.getBoundingClientRect().top) : null, row: row ? Math.round(row.getBoundingClientRect().top) : null };
                    });
                    console.log(`${name} ${width} ${theme}: the list starts at ${top.first} px (first row at ${top.row} px)`);
                    if (top.first === null || top.first > 120) {
                        console.error(`${name} ${width} ${theme}: the list starts at ${top.first} px, more than 120 px from the top`);
                        process.exitCode = 1;
                    }
                }
                // Reach (docs/PLAN_NAV_3_LEVELS.md): every rail item is in view, and every item of the open place's panel can be
                // scrolled to in the panel (or, with the earlier sidebar, its scrolling middle) and focused from either end,
                // and is then the thing under the pointer at the middle of its name (what a fold holds, with the fold
                // opened). From 1024 px wide, where the frame sits beside the page.
                if (spec.reach && width >= 1024) {
                    const missed = await page.evaluate(async (selectors) => {
                        const frame = () => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
                        const out = [];
                        const scroller = (el) => el.closest('.v2-panels, [data-sidebar-scroll]') ?? el.closest('.v2-rail');
                        // Under the flyout width the panel is folded away: its rail icon opens it over the page (as here).
                        const frameData = window.Alpine && document.querySelector('.v2-frame-inner') ? window.Alpine.$data(document.querySelector('.v2-frame-inner')) : null;
                        if (frameData && frameData.narrow && frameData.place) frameData.flyout = frameData.place;
                        await frame();
                        for (const selector of selectors) {
                            const found = [...document.querySelectorAll(selector)].filter((el) => scroller(el) !== null && el.closest('[hidden]') === null);
                            if (found.length === 0) out.push(`${selector}: not in the rail or the open panel`);
                            for (const el of found) {
                                const box = scroller(el);
                                const folds = [];
                                for (let d = el.parentElement?.closest('details'); d; d = d.parentElement?.closest('details')) {
                                    if (!d.open && !(el.tagName === 'SUMMARY' && el.parentElement === d)) {
                                        d.open = true;
                                        folds.push(d);
                                    }
                                }
                                await frame();
                                const seen = () => {
                                    const r = el.getBoundingClientRect();
                                    const n = box.getBoundingClientRect();
                                    // At the middle of its name (a row's other end may hold its own button).
                                    const name = (el.querySelector('.v2-sb-label, .v2-rail-label') ?? el).getBoundingClientRect();
                                    const hit = document.elementFromPoint(name.left + name.width / 2, name.top + name.height / 2);
                                    return r.height > 0 && r.top >= n.top - 1 && r.bottom <= n.bottom + 1 && hit !== null && (el === hit || el.contains(hit));
                                };
                                const label = `${selector} "${(el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 30)}"`;
                                // Scrolled within its own box only (scrollIntoView would move the page too).
                                const before = el.getBoundingClientRect();
                                const area = box.getBoundingClientRect();
                                box.scrollTop += before.top - area.top - (area.height - before.height) / 2;
                                await frame();
                                if (!seen()) out.push(label);
                                // Reached with the keyboard, from either end: the browser scrolls it in, clear of the box's edges.
                                for (const [end, top] of [['top', 0], ['end', box.scrollHeight]]) {
                                    document.activeElement?.blur();
                                    box.scrollTop = top;
                                    await frame();
                                    el.focus();
                                    await frame();
                                    if (document.activeElement !== el || !seen()) out.push(`${label} by keyboard from the ${end}`);
                                }
                                el.blur();
                                for (const d of folds) d.open = false;
                                box.scrollTop = 0;
                            }
                        }
                        if (frameData) frameData.flyout = null;
                        await frame();
                        return out;
                    }, spec.reach);
                    if (missed.length > 0) {
                        console.error(`${name} ${width} ${theme}: out of reach: ${missed.join('; ')}`);
                        process.exitCode = 1;
                    } else {
                        console.log(`${name} ${width} ${theme}: every rail and panel item can be reached`);
                    }
                }
                // Browser checks of a page (docs/PLAN_NAV_3_LEVELS.md section 8): `checks` is an async function of the
                // page and the width that answers a list of what went wrong; the page is loaded again afterwards.
                if (spec.checks) {
                    const wrong = await spec.checks(page, width).catch((e) => [`threw: ${e.message.split('\n').slice(0, 3).join(' | ')}`]);
                    if (wrong.length > 0) {
                        console.error(`${name} ${width} ${theme}: ${wrong.join('; ')}`);
                        process.exitCode = 1;
                    } else {
                        console.log(`${name} ${width} ${theme}: checks passed`);
                    }
                    await page.goto(base + path, { waitUntil: 'networkidle' });
                }
                // A scenario of its own (keepPlace): what went wrong, and the page is pictured as it leaves it.
                if (spec.scenario) {
                    const wrong = await spec.scenario(page, base).catch((e) => [`threw: ${e.message.split('\n').slice(0, 3).join(' | ')}`]);
                    if (wrong.length > 0) {
                        console.error(`${name} ${width} ${theme}: ${wrong.join('; ')}`);
                        process.exitCode = 1;
                    } else {
                        console.log(`${name} ${width} ${theme}: scenario passed`);
                    }
                }
                // Alpine fades the sidebar labels in; give it time so every picture shows the settled page.
                await page.waitForTimeout(spec.settle ?? 900);
                // A whole-page picture: the flow's step bar and the phone's bottom tabs are drawn where they sit in the page.
                if (spec.full !== false) await page.addStyleTag({ content: '.el-flow-bar,.el-bottom-nav{position:static!important}' });
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
