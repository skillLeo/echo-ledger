// The console's accessibility check (FIX-06, web): axe-core on the new console's main pages (Library, a recording,
// Activity, Settings, Workspace, Usage) in English and Urdu, light and dark, wide and narrow, as the account signed in;
// then the keyboard checks (keyboard(), below; KEYS=off skips them).
//
//   php artisan db:seed && php artisan db:seed --class=ConsoleTourSeeder   (APP_ENV=local, a local SQLite file)
//   php artisan serve --port=8765            (a local or test database, never production)
//   node scripts/a11y.mjs                    (or: BASE_URL=... EMAIL=... PASSWORD=... ONLY=library,settings OUT=report.json)
//
// Needs Playwright (`npm i --no-save playwright-core`, or PLAYWRIGHT_MODULE) and axe-core 4.14.0, as the desktop's check
// uses (`npm i --no-save axe-core@4.14.0`, or AXE_MODULE). Rules: WCAG 2.0, 2.1 and 2.2 A and AA, and axe's best
// practices. Exits 1 on any critical or serious finding (FIX-06: "axe finds zero serious issues"); moderate and minor
// ones are listed. The account's language is set back to English at the end.
import { createRequire } from 'node:module';
import { readFileSync, writeFileSync } from 'node:fs';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE ?? 'playwright-core');
const axeSource = readFileSync(require.resolve(process.env.AXE_MODULE ?? 'axe-core/axe.min.js'), 'utf8');

const base = (process.env.BASE_URL ?? 'http://127.0.0.1:8765').replace(/\/$/, '');
const email = process.env.EMAIL ?? 'owner@echoledger.local';
const password = process.env.PASSWORD ?? 'change-me-now-please';
const only = (process.env.ONLY ?? '').split(',').filter(Boolean);
const widths = (process.env.WIDTHS ?? '1440,390').split(',').map(Number);
const themes = (process.env.THEMES ?? 'light,dark').split(',');
const languages = (process.env.LANGUAGES ?? 'en,ur').split(',');
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22a', 'wcag22aa', 'best-practice'];

/** The first recording of the Library, for its page. */
async function firstRecording(page) {
    return page.evaluate(async (url) => {
        const html = await (await fetch(url)).text();
        const match = html.match(/data-recording="([0-9A-Za-z]{26})"/);
        return match ? `/app/sessions/${match[1]}` : '';
    }, `${base}/app/sessions`);
}

// The pages by name; a function resolves a path that depends on the data.
const pages = {
    library: '/app/sessions',
    recording: firstRecording,
    activity: '/app/activity',
    settings: '/app/settings',
    'settings-profile': '/app/settings/profile',
    workspace: '/app/workspace',
    usage: '/app/usage',
};

/** The console's language for the account signed in (Settings › Appearance), as a person sets it. */
async function consoleLanguage(page, code) {
    await page.goto(`${base}/app/settings/appearance`, { waitUntil: 'load' });
    const radio = page.locator(`[data-setting="appearance.app_language"] [role=radio][lang=${code}]`);
    if ((await radio.getAttribute('aria-checked')) === 'true') return;
    await Promise.all([page.waitForEvent('load'), radio.click()]);
    await page.waitForTimeout(1500);
}

/**
 * FIX-06's keyboard checks on the Library, at 1440 px: the rail is one Tab stop that the arrows move through; Esc closes
 * a tooltip; Tab walks the page with a visible focus ring at every stop (an outline of 2 px or more, or a ring drawn
 * as a box shadow); ↓ ↑ walk the list and an Urdu layout's J and K move the current row; typing fast into the search
 * keeps every key. Answers what went wrong.
 */
async function keyboard(page) {
    const wrong = [];
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`${base}/app/sessions`, { waitUntil: 'load' });
    await page.waitForTimeout(1500);
    // The rail: one stop of Tab, the arrows move, Tab leaves it.
    await page.evaluate(() => document.activeElement?.blur());
    let inRail = false;
    for (let i = 0; i < 12 && !inRail; i++) {
        await page.keyboard.press('Tab');
        inRail = await page.evaluate(() => !!document.activeElement?.closest('.v2-rail'));
    }
    if (!inRail) wrong.push('Tab never reached the rail');
    else {
        const first = await page.evaluate(() => document.activeElement.getAttribute('data-module') || document.activeElement.textContent.trim().slice(0, 20));
        await page.keyboard.press('ArrowDown');
        const second = await page.evaluate(() => ({ inRail: !!document.activeElement?.closest('.v2-rail'), name: document.activeElement.getAttribute('data-module') || document.activeElement.textContent.trim().slice(0, 20) }));
        if (!second.inRail || second.name === first) wrong.push(`ArrowDown in the rail did not move on from ${first}`);
        await page.keyboard.press('Tab');
        if (await page.evaluate(() => !!document.activeElement?.closest('.v2-rail'))) wrong.push('Tab did not leave the rail after one stop');
    }
    // Esc closes a tooltip: Previous recording's, on a recording.
    const recording = await firstRecording(page);
    if (recording) {
        await page.goto(base + recording, { waitUntil: 'load' });
        await page.waitForTimeout(1500);
        const tip = page.locator('[data-recording-nav-link]').first();
        if (await tip.count()) {
            await tip.hover();
            await page.waitForTimeout(800);
            const shown = await page.evaluate(() => !!document.querySelector('[data-tippy-root] .tippy-box[data-state="visible"]'));
            await page.keyboard.press('Escape');
            await page.waitForTimeout(500);
            const after = await page.evaluate(() => !!document.querySelector('[data-tippy-root] .tippy-box[data-state="visible"]'));
            if (!shown) wrong.push('no tooltip showed on Previous recording to close');
            else if (after) wrong.push('Esc did not close the tooltip');
        }
    }
    // Tab through the Library: every stop shows its focus.
    await page.goto(`${base}/app/sessions`, { waitUntil: 'load' });
    await page.waitForTimeout(1500);
    await page.evaluate(() => document.activeElement?.blur());
    const seen = new Set();
    for (let i = 0; i < 45; i++) {
        await page.keyboard.press('Tab');
        await page.waitForTimeout(60);
        const stop = await page.evaluate(() => {
            const el = document.activeElement;
            if (!el || el === document.body) return null;
            const ring = (s) => (s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) >= 2) || (s.boxShadow && s.boxShadow !== 'none');
            // The focus may show on the element, on a shape it draws (Record's menu: its circle, ::before) or on the box
            // drawn around a field (Filament's input wrapper).
            const shows = ring(getComputedStyle(el)) || ring(getComputedStyle(el, '::before'))
                || (el.closest('.fi-input-wrp') && ring(getComputedStyle(el.closest('.fi-input-wrp'))));
            const name = (el.getAttribute('aria-label') || el.textContent || el.getAttribute('placeholder') || el.tagName).trim().replace(/\s+/g, ' ').slice(0, 40);
            return { key: el.tagName + '|' + name, name, shows };
        });
        if (!stop || seen.has(stop.key)) continue;
        seen.add(stop.key);
        if (!stop.shows) wrong.push(`no visible focus on "${stop.name}"`);
    }
    if (seen.size < 15) wrong.push(`Tab reached only ${seen.size} stops of the Library`);
    console.log(`keyboard: ${seen.size} stops of the Library checked for their focus ring`);
    // The list by keys (the desktop's D-220 and D-221): ↓ on a row's link moves the focus to the next row's, ↑ back;
    // on an Urdu layout J (ج at KeyJ) and K (ک at KeyK) move the current row as the Latin keys do.
    await page.goto(`${base}/app/sessions`, { waitUntil: 'load' });
    await page.waitForTimeout(1500);
    const rows = page.locator('.v2-library .fi-ta-record .fi-ta-record-content');
    if ((await rows.count()) >= 3) {
        await rows.nth(0).focus();
        await page.keyboard.press('ArrowDown');
        await page.keyboard.press('ArrowDown');
        await page.keyboard.press('ArrowUp');
        const at = await page.evaluate(() => [...document.querySelectorAll('.v2-library .fi-ta-record .fi-ta-record-content')].indexOf(document.activeElement));
        if (at !== 1) wrong.push(`↓ ↓ ↑ from the first row left the focus on row ${at + 1}, not row 2`);
        await page.goto(`${base}/app/sessions`, { waitUntil: 'load' });
        await page.waitForTimeout(1500);
        const current = await page.evaluate(() => {
            const press = (key, code) => document.body.dispatchEvent(new KeyboardEvent('keydown', { key, code, bubbles: true, cancelable: true }));
            const at = () => [...document.querySelectorAll('.v2-library [data-recording]')].findIndex((el) => (el.closest('.fi-ta-record') || el).classList.contains('el-kb-row'));
            press('ج', 'KeyJ'); press('ج', 'KeyJ'); press('ج', 'KeyJ'); press('ک', 'KeyK');
            return at();
        });
        // From no current row the first J takes the first row: J J J K ends on the second.
        if (current !== 1) wrong.push(`an Urdu layout's J J J K left the current row at ${current + 1}, not 2`);
    } else wrong.push('the Library has fewer than 3 rows to walk');
    // Typing fast keeps every key (the desktop's D-218): 30 ms a key into the Library's search, the answers coming late.
    const search = page.locator('.v2-lib-shell .fi-ta-search-field input').first();
    if (await search.count()) {
        await search.click();
        await page.keyboard.type('invoice', { delay: 30 });
        await page.waitForTimeout(4000);
        const typed = await search.inputValue();
        if (typed !== 'invoice') wrong.push(`typed fast, the Library's search reads "${typed}", not "invoice"`);
        await search.fill('');
    }
    return wrong;
}

const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});
const report = [];
let worst = 0;
try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    page.setDefaultTimeout(90000);
    await page.goto(`${base}/app/login`, { waitUntil: 'load' });
    await page.fill('input[type=email]', email);
    await page.click('button[type=submit]');
    await page.waitForSelector('input[autocomplete=current-password]');
    await page.fill('input[autocomplete=current-password]', password);
    await page.click('button[type=submit]');
    await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 60000 });
    const paths = {};
    for (const [name, path] of Object.entries(pages)) {
        if (only.length && !only.includes(name)) continue;
        paths[name] = typeof path === 'function' ? await path(page) : path;
    }
    try {
        for (const language of languages) {
            await consoleLanguage(page, language);
            for (const theme of themes) {
                await page.emulateMedia({ colorScheme: theme });
                for (const width of widths) {
                    await page.setViewportSize({ width, height: width < 600 ? 844 : 900 });
                    for (const [name, path] of Object.entries(paths)) {
                        if (!path) continue;
                        await page.goto(base + path, { waitUntil: 'load' });
                        await page.waitForTimeout(1500);
                        await page.addScriptTag({ content: axeSource });
                        const result = await page.evaluate(async (tags) => {
                            const r = await window.axe.run(document, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] });
                            return r.violations.map((v) => ({
                                id: v.id, impact: v.impact, help: v.help,
                                nodes: v.nodes.slice(0, 6).map((n) => ({ target: n.target.join(' '), html: n.html.slice(0, 220), summary: (n.failureSummary || '').slice(0, 300) })),
                                count: v.nodes.length,
                            }));
                        }, TAGS);
                        const counts = { critical: 0, serious: 0, moderate: 0, minor: 0 };
                        for (const v of result) counts[v.impact] = (counts[v.impact] ?? 0) + v.count;
                        if (counts.critical + counts.serious > 0) worst = 1;
                        report.push({ page: name, path, language, theme, width, counts, violations: result });
                        console.log(`${name} ${language} ${theme} ${width}: critical ${counts.critical}, serious ${counts.serious}, moderate ${counts.moderate}, minor ${counts.minor}`
                            + (result.length ? `  [${result.map((v) => `${v.id}(${v.impact}×${v.count})`).join(' ')}]` : ''));
                    }
                }
            }
        }
        if (process.env.KEYS !== 'off') {
            for (const language of languages) {
                await consoleLanguage(page, language);
                await page.emulateMedia({ colorScheme: 'light' });
                const wrong = await keyboard(page);
                console.log(`keyboard ${language}: ${wrong.length ? wrong.join('; ') : 'passes'}`);
                if (wrong.length) worst = 1;
                report.push({ page: 'keyboard', language, problems: wrong });
            }
        }
    } finally {
        await page.emulateMedia({ colorScheme: 'light' });
        await consoleLanguage(page, 'en');
    }
} finally {
    await browser.close();
}
if (process.env.OUT) writeFileSync(process.env.OUT, JSON.stringify(report, null, 2) + '\n');
process.exitCode = worst;
