<?php

declare(strict_types=1);

// The saved-HTML checks of the classic pages (ClassicGoldenTest, AdminClassicGoldenTest) share this normaliser.

if (function_exists('normaliseClassic')) {
    return;
}

/** The page's HTML with what changes on every request replaced by a placeholder. */
function normaliseClassic(string $html, string $csrf = ''): string
{
    if ($csrf !== '') {
        $html = str_replace($csrf, 'CSRF', $html);
    }
    // The self-hosted Inter faces are inlined only where `filament:assets` has published them (not in every checkout).
    $html = (string) preg_replace("/<style>(@font-face\\{font-family:'Inter';[^}]*\\})+<\\/style>/", '', $html);
    // Lazy components carry their (random) id and a checksum, base64-encoded.
    $html = (string) preg_replace("/__lazyLoad\\(&#039;[A-Za-z0-9+\/=]+&#039;\\)/", '__lazyLoad(LAZY)', $html);
    // Livewire's component ids (20 random letters and digits), wherever a page repeats them.
    preg_match_all('/wire:id="([A-Za-z0-9]{20})"/', $html, $ids);
    $html = str_replace($ids[1], 'WIREID', $html);
    $html = preg_replace('/(name="csrf-token" content=")[^"]+"/', '$1CSRF"', $html);
    $html = preg_replace('/(name="_token"(?: [a-z-]+="[^"]*")* value=")[^"]+"/', '$1CSRF"', (string) $html);
    $html = preg_replace('/(data-csrf=")[^"]+"/', '$1CSRF"', (string) $html);
    $html = preg_replace('/("csrf(?:Token)?"\s*:\s*")[^"]+"/', '$1CSRF"', (string) $html);
    $html = preg_replace('/wire:snapshot="[^"]*"/', 'wire:snapshot="SNAPSHOT"', (string) $html);
    $html = preg_replace('/wire:id="[^"]*"/', 'wire:id="WIREID"', (string) $html);
    // Schema tabs' ids are a hash that holds the component's random id: numbered in the order they appear.
    preg_match_all('/fi-sc-tab-[0-9a-f]{32}/', (string) $html, $tabs);
    foreach (array_values(array_unique($tabs[0])) as $n => $tab) {
        $html = str_replace($tab, 'fi-sc-tab-'.$n, (string) $html);
    }
    // Livewire names a component's scripts by a hash of its view's path: the same page in another checkout differs.
    $html = preg_replace('/(&quot;)\d+-\d+(&quot;:&quot;)/', '$1SCRIPT$2', (string) $html);
    // Livewire's keys hold counters, random letters and hashes of record ids.
    $html = preg_replace('/wire:key="[^"]*"/', 'wire:key="KEY"', (string) $html);
    $html = preg_replace("/(livewireId: ')[A-Za-z0-9]+'/", "$1WIREID'", (string) $html);
    $html = preg_replace('/0[0-7][0-9A-HJKMNP-TV-Z]{24}(?![0-9A-Za-z])/i', 'ULID', (string) $html);
    $html = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', 'UUID', (string) $html);
    $html = preg_replace('/(signature(?:=|%3D))[0-9a-f]{64}/i', '$1SIG', (string) $html);
    $html = preg_replace('/(expires(?:=|%3D))\d+/i', '$1EXPIRES', (string) $html);

    return (string) $html;
}
