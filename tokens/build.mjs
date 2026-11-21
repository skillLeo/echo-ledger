// Builds EchoLedger's design values from the W3C design-token JSON in this folder into each app's format.
// `node build.mjs` writes the outputs; `node build.mjs --check` fails when an output differs from the source (drift).
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import StyleDictionary from 'style-dictionary';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '..');
const SHARED = ['global/**/*.json', 'semantic/layout.json', 'component/**/*.json'].map((p) => `${here}/${p}`);
const HEADER = 'Generated from tokens/ by `npm run build` in tokens/. Do not edit: change the JSON there instead.';

export const OUTPUTS = {
  web: 'web/resources/css/tokens.css',
  desktop: 'desktop/src/tokens.css',
  android: 'mobile/app/src/main/java/com/echoledger/mobile/ui/v2/tokens/EchoTokens.kt',
};

async function tokensFor(theme) {
  const sd = new StyleDictionary({
    source: [...SHARED, `${here}/semantic/${theme}.json`],
    usesDtcg: true,
    log: { verbosity: 'silent', warnings: 'disabled' },
    platforms: { x: { transforms: ['name/kebab'] } },
  });
  const { allTokens } = await sd.getPlatformTokens('x');
  return allTokens.filter((t) => !t.path[0].startsWith('color')); // raw palette stays private to the semantic roles
}

const family = (v) => (Array.isArray(v) ? v : [v]).map((f) => (/[\s]/.test(f) ? `"${f}"` : f)).join(', ');

function cssValue(t) {
  const v = t.$value;
  switch (t.$type) {
    case 'cubicBezier': return `cubic-bezier(${v.join(', ')})`;
    case 'fontFamily': return family(v);
    case 'shadow': return `${v.offsetX} ${v.offsetY} ${v.blur} ${v.spread} ${v.color}`;
    default: return String(v);
  }
}

function cssLines(tokens) {
  const out = [];
  for (const t of tokens) {
    if (t.$type === 'typography') {
      const v = t.$value;
      out.push(`--el-${t.name}-font: ${family(v.fontFamily)};`, `--el-${t.name}-size: ${v.fontSize};`,
        `--el-${t.name}-line: ${v.lineHeight};`, `--el-${t.name}-weight: ${v.fontWeight};`);
    } else out.push(`--el-${t.name}: ${cssValue(t)};`);
  }
  return out;
}

function css(light, dark, { tailwind }) {
  const isRole = (t) => t.path[0] === 'role';
  const ind = (lines, n) => lines.map((l) => ' '.repeat(n) + l).join('\n');
  const shared = cssLines(light.filter((t) => !isRole(t)));
  const lightRoles = cssLines(light.filter(isRole));
  const darkRoles = cssLines(dark.filter(isRole));
  let s = `/* ${HEADER} */\n\n:root {\n  color-scheme: light dark;\n${ind(shared, 2)}\n${ind(lightRoles, 2)}\n}\n\n`;
  s += `@media (prefers-color-scheme: dark) {\n  :root:not([data-theme="light"]) {\n${ind(darkRoles, 4)}\n  }\n}\n\n`;
  s += `:root[data-theme="dark"] {\n${ind(darkRoles, 2)}\n}\n`;
  if (tailwind) {
    // Tailwind v4: utilities such as bg-el-bg-canvas or text-el-text-secondary read the variables above.
    const roles = light.filter(isRole).map((t) => `--color-el-${t.name.replace(/^role-/, '')}: var(--el-${t.name});`);
    const radii = light.filter((t) => t.path[0] === 'radius').map((t) => `--radius-el-${t.path[1]}: var(--el-${t.name});`);
    s += `\n@theme inline {\n${ind([...roles, ...radii], 2)}\n}\n`;
  }
  return s;
}

const camel = (path) => path.map((p, i) => {
  const s = p.replace(/-([a-z0-9])/g, (_, c) => c.toUpperCase());
  return i === 0 ? s : s.charAt(0).toUpperCase() + s.slice(1);
}).join('');
const ktColor = (hex) => `Color(0x${(hex.length === 9 ? hex.slice(7) : 'FF')}${hex.slice(1, 7)}.toInt())`.replace('.toInt()', '');
const px = (v) => Number(String(v).replace('px', ''));
const ms = (v) => Number(String(v).replace('ms', ''));

function kotlin(light, dark) {
  const role = (list) => list.filter((t) => t.path[0] === 'role');
  const props = (list, f) => list.map((t) => `        ${f(t)}`).join('\n');
  const colors = (list) => props(role(list), (t) => `val ${camel(t.path.slice(1))} = ${ktColor(t.$value)}`);
  const group = (name) => light.filter((t) => t.path[0] === name);
  const dims = (name, strip = 1) => props(group(name), (t) => `val ${(/^\d/.test(t.path[strip]) ? 's' : '') + camel(t.path.slice(strip))} = ${px(t.$value)}.dp`);
  const durations = props(light.filter((t) => t.$type === 'duration' && t.path[0] === 'motion'), (t) => `const val ${camel(t.path.slice(1))}Ms = ${ms(t.$value)}`);
  const type = props(group('text'), (t) => {
    const v = t.$value;
    return `val ${camel(t.path.slice(1))} = TextStyle(fontSize = ${px(v.fontSize)}.sp, lineHeight = ${px(v.lineHeight)}.sp, fontWeight = FontWeight(${v.fontWeight}))`;
  });
  const comp = props(light.filter((t) => t.path[0] === 'component' && (t.$type === 'dimension' || t.$type === 'duration')), (t) => (t.$type === 'duration' ? `const val ${camel(t.path.slice(1))}Ms = ${ms(t.$value)}` : `val ${camel(t.path.slice(1))} = ${px(t.$value)}.dp`));
  const labels = props(group('label'), (t) => `"${t.path[1]}" to "${t.$value.toUpperCase()}"`).replace(/\n/g, ',\n');
  return `// ${HEADER}
package com.echoledger.mobile.ui.v2.tokens

import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/** EchoLedger's design values for Compose. Brand and status colours stay fixed under dynamic colour. */
object EchoTokens {
    object Light {
${colors(light)}
    }

    object Dark {
${colors(dark)}
    }

    object Space {
${dims('space')}
    }

    object Radius {
${dims('radius')}
    }

    object Layout {
${dims('layout')}
    }

    object Border {
${dims('border')}
    }

    object Focus {
${dims('focus')}
    }

    object Icon {
${dims('icon')}
    }

    /** Shadow depth of the floating layers (DESIGN_SYSTEM: shadows only on floating layers). */
    object Elevation {
${dims('elevation')}
    }

    object Component {
${comp}
    }

    object Motion {
${durations}
    }

    object Type {
${type}
    }

    /** Label colour names and the #RRGGBB the server stores for each (contract 2.0.0-i); Light/Dark.label* draw them. */
    val labelColours: Map<String, String> = linkedMapOf(
${labels},
    )
}
`;
}

export async function render() {
  const [light, dark] = await Promise.all([tokensFor('light'), tokensFor('dark')]);
  return {
    [OUTPUTS.web]: css(light, dark, { tailwind: true }),
    [OUTPUTS.desktop]: css(light, dark, { tailwind: false }),
    [OUTPUTS.android]: kotlin(light, dark),
  };
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const check = process.argv.includes('--check');
  const files = await render();
  let drift = 0;
  for (const [rel, text] of Object.entries(files)) {
    const file = resolve(root, rel);
    if (check) {
      let now = '';
      try { now = readFileSync(file, 'utf8'); } catch { /* missing counts as drift */ }
      if (now !== text) { drift++; console.error(`out of date: ${rel} (run npm run build in tokens/)`); }
    } else {
      mkdirSync(dirname(file), { recursive: true });
      writeFileSync(file, text);
      console.log(`wrote ${rel}`);
    }
  }
  if (drift) process.exit(1);
}
