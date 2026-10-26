// The system accent colour (C2): Windows reports it through the core, macOS through CSS `AccentColor`. Any accent
// is turned into the four accent tokens of styles.css with WCAG AA kept: a fill white text can sit on, a text
// colour readable on the theme's surfaces, the plain accent for indicators and a soft tint for selections.
export type Rgb = [number, number, number];

const SURFACE: Record<"light" | "dark", Rgb> = { light: [240, 241, 244], dark: [34, 35, 40] };
const WHITE: Rgb = [255, 255, 255];
const BLACK: Rgb = [0, 0, 0];

export function parseHex(value: string | null | undefined): Rgb | null {
  const m = value?.trim().match(/^#?([0-9a-f]{6})$/i);
  return m ? ([0, 2, 4].map((i) => parseInt(m[1].slice(i, i + 2), 16)) as Rgb) : null;
}

/** `rgb(r, g, b)` / `rgba(r, g, b, a)` as computed by the browser. */
export function parseRgb(value: string | null | undefined): Rgb | null {
  const m = value?.match(/rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/);
  return m ? [Number(m[1]), Number(m[2]), Number(m[3])] : null;
}

const toHex = (c: Rgb) => `#${c.map((v) => Math.round(v).toString(16).padStart(2, "0")).join("")}`;
const mix = (a: Rgb, b: Rgb, t: number): Rgb => a.map((v, i) => v + (b[i] - v) * t) as Rgb;

function luminance([r, g, b]: Rgb): number {
  const lin = (c: number) => {
    const s = c / 255;
    return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
}

export function contrast(a: Rgb, b: Rgb): number {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
}

/** Moves `colour` towards `target` in 5 % steps until it reaches `ratio` against `against`. */
function untilContrast(colour: Rgb, target: Rgb, against: Rgb, ratio: number): Rgb {
  for (let t = 0; t <= 1; t += 0.05) {
    const c = mix(colour, target, t);
    if (contrast(c, against) >= ratio) return c;
  }
  return target;
}

/** The accent tokens for a theme; every value keeps AA (4.5:1 text, 3:1 indicators). */
export function accentTokens(accent: Rgb, theme: "light" | "dark"): Record<string, string> {
  const surface = SURFACE[theme];
  const away = theme === "dark" ? WHITE : BLACK;
  const strong = untilContrast(accent, BLACK, WHITE, 4.5);
  const text = untilContrast(accent, away, surface, 4.5);
  const indicator = untilContrast(accent, away, surface, 3);
  const [r, g, b] = indicator.map(Math.round);
  return {
    "--accent": toHex(indicator),
    "--accent-strong": toHex(strong),
    "--accent-text": toHex(text),
    "--accent-soft": `rgba(${r}, ${g}, ${b}, ${theme === "dark" ? 0.16 : 0.1})`,
    "--focus": toHex(text),
  };
}

let systemAccent: Rgb | null = null;

/** Remembers the system accent and applies it for the current theme (null = the EchoLedger violet). */
export function setSystemAccent(accent: Rgb | null): void {
  systemAccent = accent;
  applyAccent();
}

/** Re-applies the accent tokens after a theme change. */
export function applyAccent(): void {
  const root = document.documentElement;
  for (const name of ["--accent", "--accent-strong", "--accent-text", "--accent-soft", "--focus"]) root.style.removeProperty(name);
  if (!systemAccent) return;
  const theme = root.dataset.theme === "light" ? "light" : "dark";
  for (const [name, value] of Object.entries(accentTokens(systemAccent, theme))) root.style.setProperty(name, value);
}

/** The accent WebKit exposes as the CSS system colour `AccentColor` (macOS); null where it is not supported. */
export function cssAccent(): Rgb | null {
  if (typeof CSS === "undefined" || !CSS.supports?.("color", "AccentColor")) return null;
  const probe = document.createElement("span");
  probe.style.color = "AccentColor";
  document.body.appendChild(probe);
  const colour = parseRgb(getComputedStyle(probe).color);
  probe.remove();
  return colour;
}
