<?php

declare(strict_types=1);

/*
 * Echo DS (docs/v2/DESIGN_SYSTEM.md) for the v2.0 console: its stylesheet and views use the --el-* values of
 * resources/css/tokens.css only (no hex, rgb or hsl colour, no raw px, no gradient), and none of the template looks
 * that a scan can find (purple, indigo or violet; emoji; a coloured start border; exclamation marks or placeholder
 * copy). A media query can't read a variable, so an @media line (or a script's matchMedia) may name a breakpoint
 * tokens.css defines.
 */

/** @return array<string, string> the v2.0 console's sources by path */
function consoleV2Sources(): array
{
    $files = [
        ...glob(resource_path('css/v2/*.css')) ?: [],
        ...glob(resource_path('views/filament/v2/*.blade.php')) ?: [],
        ...glob(resource_path('views/components/el/v2/*.blade.php')) ?: [],
    ];
    $sources = [];
    foreach ($files as $file) {
        $sources[str_replace(base_path().'/', '', $file)] = (string) file_get_contents($file);
    }

    return $sources;
}

/** @return list<string> the window breakpoints tokens.css defines, as written there ("720px") */
function tokenBreakpoints(): array
{
    preg_match_all('/--el-breakpoint-[a-z]+:\s*(\d+px)\s*;/', (string) file_get_contents(resource_path('css/tokens.css')), $m);

    return $m[1];
}

/**
 * What [$text] breaks, as "rule (line n)".
 *
 * @return list<string>
 */
function consoleV2Findings(string $text, bool $css): array
{
    $breakpoints = tokenBreakpoints();
    $found = [];
    foreach (explode("\n", $text) as $i => $line) {
        $at = ' (line '.($i + 1).')';
        $checked = preg_match('/^\s*@media\b|matchMedia\(/', $line) === 1 ? preg_replace_callback('/\d+px\b/', fn (array $px): string => in_array($px[0], $breakpoints, true) ? '' : $px[0], $line) : $line;
        if (preg_match('/#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})\b/', (string) $checked) === 1 && ($css || preg_match('/(?:color|fill|stroke|background)\s*[:=]/i', (string) $checked) === 1)) {
            $found[] = 'hex colour'.$at;
        }
        if (preg_match('/\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch)\(/i', (string) $checked) === 1) {
            $found[] = 'rgb/hsl colour'.$at;
        }
        if (preg_match('/(?:^|[^\w-])-?\d*\.?\d+px\b/', (string) $checked) === 1) {
            $found[] = 'raw px'.$at;
        }
        // A stylesheet's lengths, and a view's inline style="…" ones.
        $lengths = $css ? [(string) $checked] : (preg_match_all('/style="([^"]*)"/i', (string) $checked, $styles) > 0 ? $styles[1] : []);
        foreach ($lengths as $part) {
            if (preg_match('/(?:^|[^\w.-])\d*\.?\d+(?:rem|em|vh|vw)(?![\w-])/', $part) === 1 || (! $css && preg_match('/(?:^|[^\w-])\d+(?:\.\d+)?(?:;|$)/', $part) === 1)) {
                $found[] = 'raw length'.$at;
                break;
            }
        }
        if (preg_match('/gradient\(|\b(?:bg-)?(?:gradient|linear|radial|conic)-to-[a-z]+\b|\bbg-(?:linear|radial|conic)-/i', $line) === 1) {
            $found[] = 'gradient'.$at;
        }
        if (preg_match('/\b(purple|violet|indigo|lavender|magenta)\b/i', $line) === 1) {
            $found[] = 'purple'.$at;
        }
        if (preg_match('/\p{Extended_Pictographic}/u', str_replace(['©', '®', '™'], '', $line)) === 1) {
            $found[] = 'emoji'.$at;
        }
        if (preg_match('/border-(?:left|right|inline-start|inline-end)(?:-color)?\s*:[^;]*var\(--el-role-(?:accent|success|warning|danger|record)/i', $line) === 1
            || preg_match('/\bborder-[selr]-(?:\d+|\[)/', $line) === 1) {
            $found[] = 'coloured start border'.$at;
        }
        if (preg_match("/__\\('[^']*!'\\)|\\b(Lorem|John Doe|Welcome back)\\b/", $line) === 1) {
            $found[] = 'copy'.$at;
        }
    }

    return $found;
}

it('finds the v2.0 console\'s stylesheet and views', function () {
    expect(array_keys(consoleV2Sources()))->toContain('resources/css/v2/console.css')
        ->and(count(consoleV2Sources()))->toBeGreaterThan(10)
        ->and(tokenBreakpoints())->toContain('720px', '1024px');
});

it('uses the design values only, and none of the template looks', function () {
    $all = [];
    foreach (consoleV2Sources() as $name => $text) {
        foreach (consoleV2Findings($text, str_ends_with($name, '.css')) as $finding) {
            $all[] = $name.': '.$finding;
        }
    }

    expect($all)->toBe([]);
});

it('catches each planted case', function () {
    foreach ([
        'color: #3E63DD;', 'background: rgb(62, 99, 221);', 'padding: 12px;', 'margin: 1.5rem;', 'background: linear-gradient(red, blue);',
        'color: indigo;', 'content: "🎉";', 'border-inline-start: 3px solid var(--el-role-accent-default);', '@media (min-width: 900px) {',
        'max-width: 40rem;',
    ] as $planted) {
        expect(consoleV2Findings($planted, true))->not->toBe([], $planted);
    }
    foreach (['<div style="width: 1.5rem">', '<div style="opacity: 0.5">', '<div class="bg-gradient-to-r from-teal-500">', '<div class="bg-linear-to-b">',
        '<div class="border-s-4 border-teal-600">', '<div class="border-l-2">'] as $planted) {
        expect(consoleV2Findings($planted, false))->not->toBe([], $planted);
    }
    expect(consoleV2Findings('<div style="inset-inline-start: var(--el-space-8)">', false))->toBe([]);
    expect(consoleV2Findings('@media (min-width: 1024px) {', true))->toBe([])
        ->and(consoleV2Findings("{{ __('Saved!') }}", false))->not->toBe([]);
});

it('loads tokens.css first, so a token\'s change reaches the console, and keeps the font family in one token', function () {
    $css = consoleV2Sources()['resources/css/v2/console.css'];

    preg_match_all('/font-family\s*:\s*([^;]+);/', $css, $families);

    expect($css)->toStartWith('/*')->toContain("@import '../tokens.css';")
        ->and($families[1])->not->toBe([])
        ->and(array_filter($families[1], fn (string $value): bool => ! str_starts_with(trim($value), 'var(--el-font-family-')))->toBe([])
        ->and((string) file_get_contents(resource_path('css/tokens.css')))->toContain('--el-font-family-sans:');
});

it('uses at most three corner radii and the pill, and only the three floating shadows', function () {
    $css = consoleV2Sources()['resources/css/v2/console.css'];
    $tokens = (string) file_get_contents(resource_path('css/tokens.css'));
    // What a radius or a component radius token resolves to ("8px"), so two names of one value count once.
    preg_match_all('/--(el-(?:radius-[a-z]+|component-[a-z-]+-radius)):\s*([^;]+);/', $tokens, $defined);
    $value = array_combine($defined[1], array_map('trim', $defined[2]));

    preg_match_all('/border-radius\s*:\s*([^;]+);/', $css, $radii);
    $used = [];
    foreach ($radii[1] as $radius) {
        expect(trim($radius))->toMatch('/^(?:0|var\(--(el-(?:radius-[a-z]+|component-[a-z-]+-radius))\))$/', 'border-radius: '.$radius);
        if (preg_match('/var\(--([a-z-]+)\)/', $radius, $m) === 1) {
            $used[$value[$m[1]] ?? $m[1]] = true;
        }
    }
    $corners = array_diff(array_keys($used), [$value['el-radius-full'] ?? '999px']);
    expect(count($corners))->toBeLessThanOrEqual(3, 'radii: '.implode(', ', array_keys($used)))
        ->and($corners)->each->toBeIn(['4px', '8px', '12px']);

    preg_match_all('/box-shadow\s*:\s*([^;]+);/', $css, $shadows);
    foreach ($shadows[1] as $shadow) {
        expect(trim($shadow))->toMatch('/^(?:none|var\(--el-shadow-[123]\))$/', 'box-shadow: '.$shadow);
    }
    expect(count(array_unique(array_map('trim', $shadows[1]))))->toBeLessThanOrEqual(4);
});
