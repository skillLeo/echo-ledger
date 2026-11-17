<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;

require_once __DIR__.'/Support/golden.php';

/*
 * The public website stays exactly as it is while ui_v2.website is off: Home and Download, for a visitor at a fixed
 * time, are compared with the HTML saved in tests/Feature/V2/golden/ before the site was rebuilt. /pricing does not
 * exist then. UPDATE_GOLDEN=1 saves them again on purpose.
 */

dataset('classic site pages', [
    'home' => ['home', '/'],
    'downloads' => ['downloads', '/downloads'],
]);

it('renders the classic site page exactly as before the rebuild while ui_v2.website is off', function (string $name, string $path) {
    Carbon::setTestNow(Carbon::parse('2026-11-17 11:42:00', 'UTC'));
    $html = normaliseClassic((string) $this->get($path)->assertOk()->getContent());
    $file = __DIR__."/golden/site-{$name}.html";
    if (getenv('UPDATE_GOLDEN') === '1') {
        file_put_contents($file, $html);
    }
    expect(file_exists($file))->toBeTrue("no saved copy of {$name}: run with UPDATE_GOLDEN=1 once")
        ->and($html)->toBe(file_get_contents($file), "the classic site {$name} page changed");
})->with('classic site pages');

it('has no pricing page while ui_v2.website is off', function () {
    $this->get('/pricing')->assertNotFound();
});
