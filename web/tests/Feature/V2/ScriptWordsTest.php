<?php

declare(strict_types=1);

use App\Filament\Support\ScriptWords;
use Illuminate\Support\Facades\App;

// W-213: a script's sentence as the JavaScript that writes it. English is the same code the scripts had; Urdu may
// put the values in another order.

it('writes English as the same JavaScript as before', function () {
    expect(ScriptWords::say('Ready to upload'))->toBe("'Ready to upload'")
        ->and(ScriptWords::say('Uploading :sent of :size', ['sent' => 'this.size(f.sent + f.inflight)', 'size' => 'this.size(f.size)']))
        ->toBe("'Uploading ' + this.size(f.sent + f.inflight) + ' of ' + this.size(f.size)")
        ->and(ScriptWords::say('The upload failed (:status).', ['status' => 'res.status']))->toBe("'The upload failed (' + res.status + ').'")
        ->and(ScriptWords::say(':count files', ['count' => 'n']))->toBe("n + ' files'")
        ->and(ScriptWords::name('f.name'))->toBe('f.name')
        ->and(ScriptWords::quote("it's \"a\" </script>"))->toBe("'it\\'s \\x22a\\x22 <\\/script>'");
});

it('writes Urdu with its own order', function () {
    App::setLocale('ur');

    expect(ScriptWords::say('Uploading :sent of :size', ['sent' => 'a', 'size' => 'b']))->toBe("b + ' میں سے ' + a + ' اپ لوڈ ہو گیا'")
        ->and(ScriptWords::say('Upload :files', ['files' => 'plural(n)']))->toBe("plural(n) + ' اپ لوڈ کریں'");
});
