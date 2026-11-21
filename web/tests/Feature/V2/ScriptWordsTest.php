<?php

declare(strict_types=1);

use App\Filament\Support\ScriptWords;
use App\Filament\Support\SettingsContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\App;

require_once __DIR__.'/Support/parity_world.php';

// W-213: a script's sentence as the JavaScript that writes it. English is the same code the scripts had; Urdu may
// put the values in another order.

it('writes English as the same JavaScript as before', function () {
    expect(ScriptWords::say('Ready to upload'))->toBe("'Ready to upload'")
        ->and(ScriptWords::say('Uploading :sent of :size', ['sent' => 'this.size(f.sent + f.inflight)', 'size' => 'this.size(f.size)']))
        ->toBe("'Uploading ' + this.size(f.sent + f.inflight) + ' of ' + this.size(f.size)")
        ->and(ScriptWords::say('The upload failed (:status).', ['status' => 'res.status']))->toBe("'The upload failed (' + res.status + ').'")
        ->and(ScriptWords::say(':count files', ['count' => 'n']))->toBe("n + ' files'")
        ->and(ScriptWords::name('f.name'))->toBe('f.name');
});

it('writes Urdu with its own order', function () {
    App::setLocale('ur');

    expect(ScriptWords::say('Uploading :sent of :size', ['sent' => 'a', 'size' => 'b']))->toBe("b + ' میں سے ' + a + ' اپ لوڈ ہو گیا'")
        ->and(ScriptWords::say('Upload :files', ['files' => 'plural(n)']))->toBe("plural(n) + ' اپ لوڈ کریں'")
        // A size is drawn left to right (D4 §5): its unit is Latin as KB, MB and GB are ("1 B", as D4's "68.0 B").
        ->and(ScriptWords::say('bytes'))->toBe("'B'");
});

// W-213 review: the strings stand in double-quoted HTML attributes through {!! !!} too: nothing in them may end the
// attribute, open a tag or an entity, or end a JavaScript line.
it('escapes what could end an attribute, open a tag or an entity, or end a line', function () {
    expect(ScriptWords::quote('"'))->toBe("'\\x22'")
        ->and(ScriptWords::quote('&'))->toBe("'\\x26'")
        ->and(ScriptWords::quote('<'))->toBe("'\\x3c'")
        ->and(ScriptWords::quote('>'))->toBe("'\\x3e'")
        ->and(ScriptWords::quote("\u{2028}"))->toBe("'\\u2028'")
        ->and(ScriptWords::quote("\u{2029}"))->toBe("'\\u2029'")
        ->and(ScriptWords::quote("'"))->toBe("'\\''")
        ->and(ScriptWords::quote('\\'))->toBe("'\\\\'")
        ->and(ScriptWords::quote("a\nb\rc"))->toBe("'a\\nb\\rc'")
        ->and(ScriptWords::quote('</script>'))->toBe("'\\x3c/script\\x3e'");
});

// W-213 review: a placeholder written :Name or :NAME takes the value of `name`, as Laravel's own lines may spell it.
it('takes a placeholder in any case from its lowercase value', function () {
    expect(ScriptWords::say('Hello :Name and :NAME and :name', ['name' => 'n']))->toBe("'Hello ' + n + ' and ' + n + ' and ' + n");
});

/** Signed in to the new frame with the console in Urdu (right to left). */
function scriptWordsUrdu(object $test): void
{
    [$owner] = webParityWorld();
    webParityDesign(true);
    $owner->forceFill(['console_preferences' => ['shell' => ['app_language' => 'ur']]])->save();
    app('auth')->forgetGuards();
    SettingsContext::forget();
    $test->actingAs($owner->fresh(), 'web');
    Filament::setCurrentPanel('app');
    App::setLocale('ur');
}

// W-213 visual: a size ("8.0 MB") inside an Urdu sentence is drawn left to right as one piece (D4 §5, D11).
it('isolates a number and its unit left to right in right to left only', function () {
    expect(ScriptWords::ltr('this.size(f.size)'))->toBe('this.size(f.size)');
    scriptWordsUrdu($this);

    expect(ScriptWords::ltr('this.size(f.size)'))->toBe("'\u{2066}' + (this.size(f.size)) + '\u{2069}'");
});

it('writes the upload panel\'s sizes left to right in Urdu', function () {
    scriptWordsUrdu($this);
    $script = view('filament.library.uploader-script')->render();

    expect($script)->toContain("return '\u{2066}' + ((unit === 0 ? value : value.toFixed(value < 10 ? 1 : 0)) + ' ' + units[unit]) + '\u{2069}';");
});
