<?php

declare(strict_types=1);

/*
 * The team's rule for words whose reading depends on where they stand: lang/ur.json keeps a short word's general
 * meaning, and the reading for one place goes in a group file named <area>_context.php, used as
 * __('<area>_context.key') with the same English in lang/en.
 *
 * Why the name matters: English has no JSON file, so __('Settings') is read as the group "Settings", and on a
 * case-insensitive disk (the Mac) lang/en/settings.php answers it with the whole array. A group file named after a key
 * breaks that key in English.
 */

/** Laravel's own group files, which keep their names. */
const LANG_STANDARD_GROUPS = ['auth', 'pagination', 'passwords', 'validation'];

/** @return array<string, list<string>> locale => its group names (lang/{locale}/*.php, not lang/vendor) */
function langGroups(): array
{
    $out = [];
    foreach (['en', 'ur'] as $locale) {
        $out[$locale] = array_map(fn (string $file): string => basename($file, '.php'), glob(__DIR__.'/../../lang/'.$locale.'/*.php') ?: []);
    }

    return $out;
}

/** @return list<string> the one-word keys written as __('Word') or trans_choice('Word') in app/ and resources/views */
function langOneWordKeys(): array
{
    $keys = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../app', FilesystemIterator::SKIP_DOTS));
    $views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../resources/views', FilesystemIterator::SKIP_DOTS));
    foreach ([$files, $views] as $tree) {
        foreach ($tree as $file) {
            if (! str_ends_with((string) $file, '.php')) {
                continue;
            }
            preg_match_all('/(?:__|trans_choice|trans)\(\s*([\'"])([^\'".\s]+)\1/u', (string) file_get_contents((string) $file), $m);
            array_push($keys, ...$m[2]);
        }
    }

    return array_values(array_unique($keys));
}

it('names every context group file <area>_context.php', function () {
    foreach (langGroups() as $locale => $groups) {
        $odd = array_values(array_filter($groups, fn (string $g): bool => ! str_ends_with($g, '_context') && ! in_array($g, LANG_STANDARD_GROUPS, true)));
        expect($odd)->toBe([], "lang/{$locale}: name it <area>_context.php");
    }
});

it('never names a group file after a key, in any case', function () {
    $keys = array_map(mb_strtolower(...), [...array_keys((array) json_decode((string) file_get_contents(__DIR__.'/../../lang/ur.json'), true)), ...langOneWordKeys()]);
    foreach (langGroups() as $locale => $groups) {
        $clash = array_values(array_filter($groups, fn (string $g): bool => in_array(mb_strtolower($g), $keys, true)));
        expect($clash)->toBe([], "lang/{$locale}: a group file named after a key turns __('Key') into an array in English");
    }
});

it('gives every context key its English and its Urdu', function () {
    foreach (array_filter(langGroups()['ur'], fn (string $g): bool => str_ends_with($g, '_context')) as $group) {
        $en = __DIR__.'/../../lang/en/'.$group.'.php';
        expect(file_exists($en))->toBeTrue("lang/en/{$group}.php is missing");
        $english = require $en;
        $urdu = require __DIR__.'/../../lang/ur/'.$group.'.php';
        expect(array_keys($urdu))->toEqualCanonicalizing(array_keys($english), $group)
            ->and(array_filter($english, fn (mixed $v): bool => ! is_string($v) || $v === ''))->toBe([], $group)
            ->and(array_filter($urdu, fn (mixed $v): bool => ! is_string($v) || $v === ''))->toBe([], $group);
    }
});
