// Classic | new design, side by side (docs/PLAN_V2_PARITY.md 1 and 8): each console screen as classic draws it and as
// the new design draws it, for the same person and recordings, light and dark, English and Urdu, into one picture
// each: docs/v2/parity/web/<name>-<theme>-<lang>.png. Classic has no Urdu, so its half stays English.
//
//   A local server with the parity world (tests/Feature/V2/Support/parity_world.php) in a local SQLite file, never
//   production; SWITCH is a command that turns the design on or off and sets the console language for that person:
//     SWITCH="php world.php" BASE_URL=http://127.0.0.1:8791 EMAIL=... PASSWORD=... node scripts/sidebyside.mjs
//   ONLY=library-group,library-table picks screens. Needs Playwright (PLAYWRIGHT_MODULE) and optionally sharp.
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE ?? 'playwright-core');
const sharp = require(process.env.SHARP_MODULE ?? 'sharp');

const base = (process.env.BASE_URL ?? 'http://127.0.0.1:8791').replace(/\/$/, '');
const out = resolve(process.env.OUT_DIR ?? resolve(here, '../../docs/v2/parity/web'));
const only = (process.env.ONLY ?? '').split(',').filter(Boolean);
const [switchCmd, ...switchArgs] = (process.env.SWITCH ?? '').split(' ').filter(Boolean);
const sw = (...args) => execFileSync(switchCmd, [...switchArgs, ...args], { stdio: 'inherit' });
const settle = (page, ms = 700) => page.waitForTimeout(ms);
const openMenu = (selector) => async (page) => {
    await page.locator(selector).filter({ visible: true }).first().click();
    await settle(page);
};

/**
 * The screens: where each opens in both designs, and what is done before the picture in each (null: as it opens).
 * A screen the classic design has no such control for shows classic as it is at that place.
 */
const screens = [
    { name: 'library-views', path: '/app/sessions', classic: null, v2: openMenu('[data-menu="views"]') },
    { name: 'library-group', path: '/app/sessions', classic: openMenu('[data-menu="group"]'), v2: openMenu('[data-menu="group"]') },
    { name: 'library-table', path: '/app/sessions', classic: openMenu('[data-layout="table"]'), v2: openMenu('[data-layout="table"]'), after: openMenu('[data-layout="list"]') },
    { name: 'library-rows', path: '/app/sessions', classic: null, v2: null },
    { name: 'nav-create', path: '/app/search?q=proforma', classic: openMenu('.el-tb-create'), v2: openMenu('.v2-tb-create') },
    { name: 'settings-appearance', path: '/app/settings/appearance', classic: null, v2: null },
    { name: 'nav-phone', path: '/app/sessions', width: 390, classic: null, v2: null },
];

async function signIn(context) {
    const page = await context.newPage();
    await page.goto(`${base}/app/login`);
    await page.fill('input[type=email]', process.env.EMAIL);
    // The address first, then the password on the next step.
    await page.click('button[type=submit]');
    await settle(page, 2000);
    await page.fill('input[type=password]', process.env.PASSWORD);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await settle(page, 3000);
    if (new URL(page.url()).pathname.endsWith('/login')) throw new Error('Not signed in: ' + (await page.locator('body').innerText()).slice(0, 300));
    return page;
}

async function shoot(theme, design) {
    const context = await browser.newContext({ viewport: { width: 1280, height: 820 }, colorScheme: theme, deviceScaleFactor: 1, storageState: signedIn });
    await context.addInitScript((t) => { try { localStorage.setItem('theme', t); } catch {} }, theme);
    const page = await context.newPage();
    const shots = {};
    for (const s of screens.filter((s) => only.length === 0 || only.includes(s.name))) {
        await page.setViewportSize({ width: s.width ?? 1280, height: 820 });
        await page.goto(base + s.path);
        await settle(page, 1200);
        if (s[design]) await s[design](page);
        shots[s.name] = await page.screenshot();
        if (s.after) await s.after(page);
    }
    await context.close();
    return shots;
}

async function pair(left, right, file) {
    const [l, r] = await Promise.all([sharp(left).metadata(), sharp(right).metadata()]);
    const gap = 16;
    const picture = await sharp({ create: { width: l.width + gap + r.width, height: Math.max(l.height, r.height), channels: 3, background: '#8a8f98' } })
        .composite([{ input: left, left: 0, top: 0 }, { input: right, left: l.width + gap, top: 0 }])
        .png({ palette: true, quality: 95, effort: 10, compressionLevel: 9 }).toBuffer();
    writeFileSync(file, picture);
}

mkdirSync(out, { recursive: true });
// Signed in once: the sign-in page allows a few tries a minute.
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const first = await browser.newContext();
await signIn(first);
const signedIn = await first.storageState();
await first.close();
for (const theme of ['light', 'dark']) {
    sw('classic');
    sw('lang', 'en');
    const classic = await shoot(theme, 'classic');
    sw('v2');
    for (const lang of ['en', 'ur']) {
        sw('lang', lang);
        const v2 = await shoot(theme, 'v2');
        for (const name of Object.keys(v2)) {
            await pair(classic[name], v2[name], resolve(out, `${name}-${theme}-${lang}.png`));
            console.log(`${name}-${theme}-${lang}.png`);
        }
    }
}
sw('lang', 'en');
await browser.close();
