// Guards for the design values: readable contrast in both themes, no purple accent, outputs in step with the source.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { render } from '../build.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const json = (p) => JSON.parse(readFileSync(resolve(here, '..', p), 'utf8'));
const palette = json('global/color.json').color;
const hex = (ref) => { const [, g, k] = ref.match(/^\{color\.([a-z]+)\.([0-9]+)\}$/); return palette[g][k].$value; };
const lum = (h) => { const c = [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16) / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4)); return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; };
const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((m, n) => n - m); return (x + 0.05) / (y + 0.05); };

for (const theme of ['light', 'dark']) {
  const r = json(`semantic/${theme}.json`).role;
  const v = (g, k) => hex(r[g][k].$value);
  test(`${theme}: every text role reaches 4.5:1 on canvas, surface and subtle`, () => {
    for (const bg of ['canvas', 'surface', 'subtle']) {
      for (const [g, k] of [['text', 'primary'], ['text', 'secondary'], ['accent', 'text'], ['danger', 'default'], ['success', 'default'], ['warning', 'default']]) {
        assert.ok(ratio(v(g, k), v('bg', bg)) >= 4.5, `${g}.${k} on bg.${bg}: ${ratio(v(g, k), v('bg', bg)).toFixed(2)}`);
      }
    }
  });
  test(`${theme}: every label colour's text reaches 4.5:1 on its fill and the backgrounds, its dot 3:1`, () => {
    const names = Object.keys(json('global/label.json').label).filter((n) => !n.startsWith('$'));
    assert.equal(names.length, 8);
    for (const n of names) {
      const l = r.label[n]; const c = (k) => hex(l[k].$value);
      for (const bg of [c('fill'), v('bg', 'canvas'), v('bg', 'surface'), v('bg', 'subtle')]) {
        assert.ok(ratio(c('text'), bg) >= 4.5, `label.${n}.text on ${bg}: ${ratio(c('text'), bg).toFixed(2)}`);
      }
      for (const bg of ['canvas', 'surface', 'subtle']) assert.ok(ratio(c('dot'), v('bg', bg)) >= 3, `label.${n}.dot on bg.${bg}`);
      // On its own chip the dot is drawn in the text colour (v2.css .el-label-chip .el-label-dot), so that pair must hold 3:1 too.
      assert.ok(ratio(c('text'), c('fill')) >= 3, `label.${n}.text as the chip's dot`);
    }
  });
  test(`${theme}: text on the accent and on the record button reaches 4.5:1`, () => {
    assert.ok(ratio(v('text', 'on-accent'), v('accent', 'default')) >= 4.5);
  });
  test(`${theme}: the accent is not purple, indigo or violet`, () => {
    const h = v('accent', 'default'); const [R, G, B] = [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16));
    const max = Math.max(R, G, B), min = Math.min(R, G, B); let hue = 0;
    if (max !== min) hue = max === R ? 60 * (((G - B) / (max - min)) % 6) : max === G ? 60 * ((B - R) / (max - min) + 2) : 60 * ((R - G) / (max - min) + 4);
    hue = (hue + 360) % 360;
    assert.ok(hue < 230 || hue > 300, `accent hue ${hue}`);
  });
}

test('the generated files match the token source (run npm run build in tokens/)', async () => {
  for (const [rel, text] of Object.entries(await render())) {
    assert.equal(readFileSync(resolve(here, '../..', rel), 'utf8'), text, rel);
  }
});

test('the settings icon map gives every page one glyph, unlike its group and its neighbours, on every platform', () => {
  const map = json('icons/settings.json');
  const groups = Object.entries(map.groups);
  const pages = Object.entries(map.pages);
  const on = (e, platform) => !e.platforms || e.platforms.includes(platform);
  for (const [id, p] of pages) {
    assert.ok(p.group === null || map.groups[p.group], `page ${id}: group ${p.group} exists`);
    assert.ok(p.lucide && p.material, `page ${id}: a desktop/web glyph and a phone glyph`);
  }
  for (const platform of ['desktop', 'phone']) {
    for (const [gid, g] of groups) {
      if (!on(g, platform)) continue;
      const mine = pages.filter(([, p]) => p.group === gid && on(p, platform));
      assert.ok(mine.length > 0, `${platform}: group ${gid} has a page`);
      for (const set of ['lucide', 'material']) {
        const glyphs = mine.map(([, p]) => p[set]);
        assert.equal(new Set(glyphs).size, glyphs.length, `${platform} ${set}: two pages of ${gid} share a glyph`);
        if (mine.length > 1) assert.ok(!glyphs.includes(g[set]), `${platform} ${set}: group ${gid} repeats a page's glyph`);
      }
    }
    for (const set of ['lucide', 'material']) {
      const groupGlyphs = groups.filter(([, g]) => on(g, platform)).map(([, g]) => g[set]);
      assert.equal(new Set(groupGlyphs).size, groupGlyphs.length, `${platform} ${set}: two groups share a glyph`);
    }
  }
});
