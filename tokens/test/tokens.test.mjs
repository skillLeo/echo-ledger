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
