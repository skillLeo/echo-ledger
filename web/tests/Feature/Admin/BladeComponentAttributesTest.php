<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
| Blade does not compile a directive inside a component tag's attribute: <x-filament::button wire:click="go(@js($id))">
| reaches the browser as the literal text, Livewire cannot parse it, and the button does nothing. A test that calls the
| method directly stays green, so this was found only in a real browser (Revoke, Sign out and Remove passkey).
*/

/** The opening tags of every Blade component in a view: up to the first > that does not belong to -> or =>. */
function componentTags(string $view): array
{
    preg_match_all('/<x-[\w:.\-]+(?:[^>]|(?<=-)>|(?<==)>)*>/s', $view, $tags);

    return $tags[0];
}

it('finds a directive in a component tag, and leaves plain tags and echoes alone', function () {
    expect(componentTags('<x-filament::button wire:click="go(@js($a->id))">Go</x-filament::button>')[0])->toContain('@js(')
        ->and(componentTags('<button wire:click="go(@js($a))">Go</button>'))->toBe([])
        ->and(componentTags('<x-filament::button wire:click="go(\'{{ $a->id }}\')">Go</x-filament::button>')[0])->not->toContain('@js(');
});

it('has no directive inside a component tag in any view', function () {
    $found = [];
    foreach (File::allFiles(resource_path('views')) as $file) {
        foreach (componentTags($file->getContents()) as $tag) {
            if (preg_match('/@(js|json)\(/', $tag) === 1) {
                $found[] = $file->getRelativePathname().': '.mb_substr((string) preg_replace('/\s+/', ' ', $tag), 0, 120);
            }
        }
    }

    expect($found)->toBe([]);
});
