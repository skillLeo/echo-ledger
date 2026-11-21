// W-201: the console's four wide tables (a recording's Share links, My links, Devices, Request links) in the new frame,
// measured in a browser at 1024 and 1280 px, light and dark, in English and Urdu, and photographed (each table, and the
// row's ⋮ menu open). It fails when a table has less than MIN_SLACK px (60) between its box and the narrowest it can be.
// docs/v2/design-review/W201/widths.md holds its last run.
//
//   A local server with the parity world (tests/Feature/V2/Support/parity_world.php) in a local SQLite file, never
//   production; SWITCH is a command that sets the console language for that person ("<SWITCH> lang ur"):
//     SWITCH="php world.php" BASE_URL=http://127.0.0.1:8791 EMAIL=... PASSWORD=... OUT_DIR=... node table-widths.mjs
//   Needs Playwright (PLAYWRIGHT_MODULE) and, for pictures, sharp (SHARP_MODULE); without OUT_DIR it only measures.
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE ?? 'playwright-core');
const base = (process.env.BASE_URL ?? 'http://127.0.0.1:8791').replace(/\/$/, '');
const out = process.env.OUT_DIR ?? '';
const minSlack = Number(process.env.MIN_SLACK ?? 60);
const [switchCmd, ...switchArgs] = (process.env.SWITCH ?? '').split(' ').filter(Boolean);
const sw = (...args) => switchCmd && execFileSync(switchCmd, [...switchArgs, ...args], { stdio: 'inherit' });
const sharp = out ? require(process.env.SHARP_MODULE ?? 'sharp') : null;
if (out) mkdirSync(out, { recursive: true });

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const signIn = await browser.newContext();
const login = await signIn.newPage();
await login.goto(`${base}/app/login`);
await login.fill('input[type=email]', process.env.EMAIL);
await login.click('button[type=submit]');
await login.waitForTimeout(2000);
await login.fill('input[type=password]', process.env.PASSWORD);
await login.getByRole('button', { name: 'Sign in', exact: true }).click();
await login.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 30000 });
// The recording: the one My links' first link points to (its page has that share link).
await login.goto(`${base}/app/shares`);
const recording = new URL(await login.locator('.fi-ta a[href*="/app/sessions/"]').first().getAttribute('href'), base).pathname;
const state = await signIn.storageState();
await signIn.close();

const tables = { 'share-links': recording, 'my-links': '/app/shares', devices: '/app/devices', 'request-links': '/app/request-links' };
const save = (png, file) => sharp(png).png({ palette: true, quality: 90, effort: 10, compressionLevel: 9 }).toFile(file);
let short = 0;
for (const lang of ['en', 'ur']) {
    sw('lang', lang);
    for (const theme of ['light', 'dark']) {
        const context = await browser.newContext({ storageState: state, colorScheme: theme, deviceScaleFactor: 1 });
        await context.addInitScript((t) => { try { localStorage.setItem('theme', t); } catch {} }, theme);
        const page = await context.newPage();
        for (const [name, path] of Object.entries(tables)) {
            for (const width of [1024, 1280]) {
                await page.setViewportSize({ width, height: 900 });
                await page.goto(base + path);
                await page.waitForLoadState('networkidle', { timeout: 6000 }).catch(() => {});
                await page.waitForTimeout(500);
                const m = await page.evaluate(() => {
                    const box = [...document.querySelectorAll('.fi-ta-content-ctn')].pop();
                    const table = box.querySelector('table');
                    const was = table.style.width;
                    table.style.width = 'min-content';
                    const needs = Math.ceil(table.getBoundingClientRect().width);
                    table.style.width = 'max-content';
                    const natural = Math.ceil(table.getBoundingClientRect().width);
                    table.style.width = was;
                    return { available: box.clientWidth, needs, natural, scrolls: box.scrollWidth > box.clientWidth + 1 };
                });
                const slack = m.available - m.needs;
                if (slack < minSlack || m.scrolls) short++;
                console.log(JSON.stringify({ table: name, width, theme, lang, ...m, slack }));
                if (!out) continue;
                const ta = page.locator('.fi-ta').last();
                await ta.scrollIntoViewIfNeeded();
                await save(await ta.screenshot(), `${out}/${name}-${width}-${theme}-${lang}.png`);
                if (width === 1024) {
                    // The row's ⋮ menu open: its actions, the red ones last.
                    await ta.locator('.fi-ta-actions button').filter({ visible: true }).last().click();
                    await page.waitForTimeout(500);
                    await save(await page.screenshot(), `${out}/${name}-${width}-${theme}-${lang}-menu.png`);
                    await page.keyboard.press('Escape');
                }
            }
        }
        await context.close();
    }
}
sw('lang', 'en');
await browser.close();
if (short > 0) {
    console.error(`${short} measurement(s) with less than ${minSlack} px to spare`);
    process.exit(1);
}
