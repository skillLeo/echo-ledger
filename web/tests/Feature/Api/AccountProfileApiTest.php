<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Mail\EmailCodeMail;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\SentItem;
use App\Services\Accounts\AccountEraser;
use App\Services\Accounts\Avatars;
use App\Services\Storage\RecordingFiles;
use App\Services\Workspaces\Workspaces;
use Filament\Auth\Notifications\NoticeOfEmailChangeRequest;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(InteractsWithDeviceApi::class);

require_once __DIR__.'/../Accounts/Support/account_helpers.php';

/* Account and security from the apps (2.0.0-l): the name, the profile picture, the email and the password. */

beforeEach(function () {
    Filament::setCurrentPanel('app');
    Mail::fake();
    Notification::fake();
});

/** A picture of [$w] by [$h] in [$format] (png, jpeg, webp), as bytes. */
function profPicture(int $w, int $h, string $format = 'png'): string
{
    $image = imagecreatetruecolor($w, $h);
    imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, (int) imagecolorallocate($image, 200, 40, 40));
    // A blue square in the middle, so the centred crop can be checked.
    imagefilledrectangle($image, intdiv($w, 2) - 10, intdiv($h, 2) - 10, intdiv($w, 2) + 10, intdiv($h, 2) + 10, (int) imagecolorallocate($image, 20, 20, 220));
    ob_start();
    match ($format) {
        'jpeg' => imagejpeg($image, null, 90),
        'webp' => imagewebp($image, null, 90),
        default => imagepng($image),
    };

    return (string) ob_get_clean();
}

/** A JPEG with an EXIF block holding a GPS-like marker, to see that no metadata survives. */
function profJpegWithExif(string $marker): string
{
    $jpeg = profPicture(400, 300, 'jpeg');
    $tiff = "MM\x00\x2A\x00\x00\x00\x08\x00\x00".$marker;
    $app1 = "Exif\x00\x00".$tiff;

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
}

/** A PNG whose header claims [$w] by [$h] and whose data is not there (nothing is decoded before the checks). */
function profPngHeader(int $w, int $h): string
{
    $ihdr = 'IHDR'.pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0);

    return "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr)).str_repeat("\0", 64);
}

function profUpload(object $test, string $token, string $bytes, string $name = 'me.png'): TestResponse
{
    return $test->asToken($token)->post('/api/account/avatar', ['image' => UploadedFile::fake()->createWithContent($name, $bytes)], ['Accept' => 'application/json']);
}

/** The picture an avatar_url serves, decoded. */
function profServed(object $test, string $url, int $size = 128): GdImage
{
    app('auth')->forgetGuards();
    $response = $test->get($url.($size === 128 ? '' : '&size='.$size))->assertOk()->assertHeader('Content-Type', 'image/webp');
    $image = imagecreatefromstring((string) $response->getContent());
    expect($image)->toBeInstanceOf(GdImage::class);

    return $image;
}

function profCode(): string
{
    return (string) Mail::queued(EmailCodeMail::class)->last()->code;
}

describe('GET /api/account', function () {
    it('gains the picture, the password, a waiting change of address and phone passkeys', function () {
        $user = Fx::user();
        [, $token] = $this->enroll($user);

        $this->asToken($token)->getJson('/api/account')->assertOk()->assertJson([
            'avatar_url' => null, 'avatar_version' => null, 'has_password' => true, 'pending_email' => null, 'pending_email_expires_at' => null, 'passkeys_available' => true,
        ]);
        $user->forceFill(['has_password' => false])->save();
        config(['echoledger.android_app.fingerprints' => []]);
        $this->asToken($token)->getJson('/api/account')->assertJsonPath('has_password', false)->assertJsonPath('passkeys_available', false);
    });

    it('lets a viewer change their own account, and needs sessions:write for it', function () {
        $user = Fx::user();
        [, $readOnly] = $this->enroll($user, ['config:read']);
        $this->asToken($readOnly)->patchJson('/api/account', ['name' => 'X'])->assertForbidden()->assertJsonPath('code', 'missing_ability');
        foreach ([['deleteJson', '/api/account/avatar'], ['postJson', '/api/account/email'], ['postJson', '/api/account/email/confirm'], ['deleteJson', '/api/account/email'],
            ['putJson', '/api/account/password'], ['postJson', '/api/account/avatar'], ['postJson', '/api/account/proof-code']] as [$method, $uri]) {
            $this->asToken($readOnly)->{$method}($uri, [])->assertForbidden()->assertJsonPath('code', 'missing_ability');
        }

        $owner = Fx::user();
        $team = $owner->currentWorkspace;
        app(Workspaces::class)->addMember($team, $user, WorkspaceRole::Viewer);
        app(Workspaces::class)->switchTo($user, $team);
        [, $viewer] = $this->enroll($user->fresh());
        $this->asToken($viewer)->patchJson('/api/account', ['name' => 'Viewer Name'])->assertOk()->assertJsonPath('name', 'Viewer Name');
    });
});

describe('PATCH /api/account (name)', function () {
    it('trims the name, answers the account and audits the change', function () {
        $user = Fx::user(['name' => 'Old Name']);
        [, $token] = $this->enroll($user);

        $this->asToken($token)->patchJson('/api/account', ['name' => "  Hassam Ali  \u{200C}"])->assertOk()
            ->assertJsonPath('name', 'Hassam Ali')->assertJsonPath('email', $user->email)->assertJsonStructure(['workspaces', 'avatar_url', 'has_password']);
        expect($user->fresh()->name)->toBe('Hassam Ali')
            ->and(AuditEvent::query()->where('event', 'name_changed')->where('user_id', $user->id)->count())->toBe(1);

        // The same name again changes nothing and audits nothing.
        $this->asToken($token)->patchJson('/api/account', ['name' => 'Hassam Ali'])->assertOk();
        expect(AuditEvent::query()->where('event', 'name_changed')->count())->toBe(1);
    });

    it('takes letters and marks of any script, with ZWNJ, ZWJ, LRM and RLM inside a name', function () {
        [, $token] = $this->enroll(Fx::user());
        foreach (['حسام علی', "می\u{200C}خواهم", "O'Brien-Smith (Jr.), 2nd", 'क्षत्रिय', "Ali\u{200F} Khan", 'José Ñúñez'] as $name) {
            $this->asToken($token)->patchJson('/api/account', ['name' => $name])->assertOk()->assertJsonPath('name', $name);
        }
    });

    it('refuses bidi overrides, isolates and control characters rather than strip them', function () {
        [, $token] = $this->enroll($user = Fx::user(['name' => 'Kept']));
        foreach (["Ali\u{202E}nahK", "Ali\u{2066}Khan\u{2069}", "Ali\u{202A}Khan", "Ali\tKhan", "Ali\u{0007}", "Ali\u{FEFF}Khan", 'Ali <b>', 'Ali@Khan'] as $name) {
            $response = $this->asToken($token)->patchJson('/api/account', ['name' => $name])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
            expect($response->json('error_codes.name'))->toBe([['code' => 'invalid_characters']])
                ->and($response->json('errors.name.0'))->toBeString();
        }
        expect($user->fresh()->name)->toBe('Kept');
    });

    it('answers required and too_long with their codes', function () {
        [, $token] = $this->enroll(Fx::user());

        $this->asToken($token)->patchJson('/api/account', ['name' => "   \u{200C} "])->assertStatus(422)->assertJsonPath('error_codes.name', [['code' => 'required']]);
        $this->asToken($token)->patchJson('/api/account', [])->assertStatus(422)->assertJsonPath('error_codes.name', [['code' => 'required']]);
        $this->asToken($token)->patchJson('/api/account', ['name' => str_repeat('a', 121)])->assertStatus(422)->assertJsonPath('error_codes.name', [['code' => 'too_long', 'max' => 120]]);
        $this->asToken($token)->patchJson('/api/account', ['name' => str_repeat('ب', 120)])->assertOk();
    });

    it('allows 10 an hour per person, whichever device asks (429 rate_limited)', function () {
        $user = Fx::user();
        [, $phone] = $this->enroll($user);
        [, $laptop] = $this->enroll($user, null, 'windows');
        foreach (range(1, 10) as $i) {
            $this->asToken($i % 2 === 0 ? $phone : $laptop)->patchJson('/api/account', ['name' => "Name {$i}"])->assertOk();
        }

        $this->asToken($phone)->patchJson('/api/account', ['name' => 'Eleven'])->assertStatus(429)->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After');
        $this->asToken($laptop)->patchJson('/api/account', ['name' => 'Eleven'])->assertStatus(429);
        [, $other] = $this->enroll(Fx::user());
        $this->asToken($other)->patchJson('/api/account', ['name' => 'Someone else'])->assertOk();
    });
});

describe('profile picture', function () {
    it('keeps a centred square WebP at 128 and 512 px and shows it on the account', function () {
        $user = Fx::user();
        [, $token] = $this->enroll($user);

        $answer = profUpload($this, $token, profPicture(900, 600))->assertOk()->json();
        expect($answer['avatar_version'])->toBeInt()->and($answer['avatar_url'])->toContain('/api/avatars/'.$user->id.'/'.$answer['avatar_version'])
            ->and($answer['avatar_url'])->toContain('signature=')
            ->and(AuditEvent::query()->where('event', 'avatar_changed')->where('user_id', $user->id)->exists())->toBeTrue();
        $this->asToken($token)->getJson('/api/account')->assertJsonPath('avatar_version', $answer['avatar_version'])->assertJsonPath('avatar_url', $answer['avatar_url']);

        $small = profServed($this, $answer['avatar_url']);
        $large = profServed($this, $answer['avatar_url'], 512);
        expect([imagesx($small), imagesy($small)])->toBe([128, 128])->and([imagesx($large), imagesy($large)])->toBe([512, 512]);
        // The blue square of the middle is in the middle of the square.
        $centre = imagecolorsforindex($large, imagecolorat($large, 256, 256));
        expect($centre['blue'])->toBeGreaterThan(150)->and($centre['red'])->toBeLessThan(100);

        // Encrypted at rest, under the version.
        foreach (Avatars::paths($user->id, $answer['avatar_version']) as $path) {
            expect(app(RecordingFiles::class)->exists($path))->toBeTrue();
        }
    });

    it('takes JPEG, PNG and WebP by their bytes, whatever the name, and drops EXIF and GPS', function () {
        [, $token] = $this->enroll(Fx::user());
        profUpload($this, $token, profPicture(300, 300, 'webp'), 'photo.jpg')->assertOk();
        profUpload($this, $token, profPicture(300, 300, 'png'), 'photo.heic')->assertOk();

        $url = profUpload($this, $token, profJpegWithExif('SECRETGPS-33.6844N-73.0479E'), 'photo.png')->assertOk()->json('avatar_url');
        $this->app['auth']->forgetGuards();
        foreach (['', '&size=512'] as $size) {
            $bytes = (string) $this->get($url.$size)->assertOk()->getContent();
            expect($bytes)->toStartWith('RIFF')->not->toContain('SECRETGPS')->not->toContain('Exif');
        }
    });

    it('refuses an unknown format, a broken file, a small, long or huge picture with its reason', function () {
        $user = Fx::user();
        [, $token] = $this->enroll($user);
        ob_start();
        imagegif(imagecreatetruecolor(300, 300));
        $gif = (string) ob_get_clean();

        foreach ([
            'format' => [$gif, 'a.gif'], 'format ' => ['just some text, not a picture', 'a.png'],
            'broken' => [profPngHeader(0, 0), 'a.png'],
            'broken  ' => ["\xFF\xD8\xFF".str_repeat("\0", 200), 'a.jpg'],
            // A header that reads as 300 by 300, and no picture behind it: refused when decoding.
            'broken ' => [profPngHeader(300, 300), 'a.png'],
            'too_small' => [profPicture(127, 300), 'a.png'],
            'ratio' => [profPicture(1000, 200), 'a.png'],
            'too_many_pixels' => [profPngHeader(6000, 5000), 'a.png'],
        ] as $reason => [$bytes, $name]) {
            profUpload($this, $token, $bytes, $name)->assertStatus(422)->assertJsonPath('code', 'invalid_image')->assertJsonPath('reason', trim($reason));
        }
        // Refused pictures count toward the 10 an hour too (each one is read).
        $this->travel(61)->minutes();
        profUpload($this, $token, profPicture(128, 512))->assertOk();
        profUpload($this, $token, '', 'empty.png')->assertStatus(422);
        $this->asToken($token)->postJson('/api/account/avatar', [])->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonPath('error_codes.image.0.code', 'required');
    });

    it('answers 413 too_large over 5 MB', function () {
        [, $token] = $this->enroll($user = Fx::user());
        $big = profPicture(300, 300).random_bytes(Avatars::MAX_BYTES);

        profUpload($this, $token, $big)->assertStatus(413)->assertJsonPath('code', 'too_large');
        expect($user->fresh()->avatar_version)->toBeNull();
    });

    it('answers 503 server_busy with Retry-After 5 from the same picture limit as screens', function () {
        [, $token] = $this->enroll($user = Fx::user());
        config(['echoledger.picture_decodes.wait_seconds' => 0]);
        $held = Cache::lock('pictures:decode:user:'.$user->id, 60);
        $held->get();

        profUpload($this, $token, profPicture(300, 300))->assertStatus(503)->assertJsonPath('code', 'server_busy')->assertHeader('Retry-After', '5');
        $held->release();
        profUpload($this, $token, profPicture(300, 300))->assertOk();
    });

    it('answers 403 for an expired or wrong signature and 404 for a replaced or removed version, with empty bodies', function () {
        [, $token] = $this->enroll($user = Fx::user());
        $first = profUpload($this, $token, profPicture(300, 300))->assertOk()->json();
        $this->app['auth']->forgetGuards();

        $wrong = preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $first['avatar_url']);
        expect($this->get($wrong)->assertForbidden()->getContent())->toBe('');
        $other = str_replace('/avatars/'.$user->id.'/', '/avatars/'.($user->id + 1).'/', $first['avatar_url']);
        expect($this->get($other)->assertForbidden()->getContent())->toBe('');
        expect($this->get($first['avatar_url'].'&size=64')->assertNotFound()->getContent())->toBe('');

        $this->travel(1)->seconds();
        $second = profUpload($this, $token, profPicture(400, 400))->assertOk()->json();
        expect($second['avatar_version'])->toBeGreaterThan($first['avatar_version']);
        $this->app['auth']->forgetGuards();
        expect($this->get($first['avatar_url'])->assertNotFound()->getContent())->toBe('');
        foreach (Avatars::paths($user->id, $first['avatar_version']) as $path) {
            expect(app(RecordingFiles::class)->exists($path))->toBeFalse();
        }
        $this->get($second['avatar_url'])->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=604800, private');

        $this->travel(8)->days();
        expect($this->get($second['avatar_url'])->assertForbidden()->getContent())->toBe('');
    });

    it('removes the picture and its stored copies, and never reuses a version', function () {
        [, $token] = $this->enroll($user = Fx::user());
        $first = profUpload($this, $token, profPicture(300, 300))->assertOk()->json();

        $this->asToken($token)->deleteJson('/api/account/avatar')->assertNoContent();
        expect($user->fresh()->avatar_version)->toBeNull()
            ->and(AuditEvent::query()->where('event', 'avatar_removed')->where('user_id', $user->id)->count())->toBe(1);
        foreach (Avatars::paths($user->id, $first['avatar_version']) as $path) {
            expect(app(RecordingFiles::class)->exists($path))->toBeFalse();
        }
        $this->app['auth']->forgetGuards();
        expect($this->get($first['avatar_url'])->assertNotFound()->getContent())->toBe('');
        $this->asToken($token)->getJson('/api/account')->assertJsonPath('avatar_url', null)->assertJsonPath('avatar_version', null);
        // Nothing to remove is not an error, and is not audited again.
        $this->asToken($token)->deleteJson('/api/account/avatar')->assertNoContent();
        expect(AuditEvent::query()->where('event', 'avatar_removed')->count())->toBe(1);

        $again = profUpload($this, $token, profPicture(300, 300))->assertOk()->json();
        expect($again['avatar_version'])->toBeGreaterThan($first['avatar_version']);
    });

    it('allows 10 changes an hour, adding and removing together', function () {
        [, $token] = $this->enroll(Fx::user());
        foreach (range(1, 9) as $_) {
            $this->asToken($token)->deleteJson('/api/account/avatar')->assertNoContent();
        }
        profUpload($this, $token, profPicture(300, 300))->assertOk();

        profUpload($this, $token, profPicture(300, 300))->assertStatus(429)->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After');
        $this->asToken($token)->deleteJson('/api/account/avatar')->assertStatus(429);
    });

    it('shows the picture beside the sender in Shared with me and the recipient in Sent', function () {
        $ana = Fx::user(['name' => 'Ana']);
        $omar = Fx::user(['name' => 'Omar']);
        [$anaDevice, $anaToken] = $this->enroll($ana);
        [, $omarToken] = $this->enroll($omar);
        $session = Fx::session($ana, $anaDevice, ['title' => 'Call']);
        SentItem::query()->create(['workspace_id' => $session->workspace_id, 'from_user_id' => $ana->id, 'to_user_id' => $omar->id, 'kind' => 'session',
            'capture_session_id' => $session->id, 'title' => 'Call', 'sent_at' => now()]);
        $anaPic = profUpload($this, $anaToken, profPicture(300, 300))->assertOk()->json();
        $omarPic = profUpload($this, $omarToken, profPicture(300, 300))->assertOk()->json();

        $from = $this->asToken($omarToken)->getJson('/api/shared-with-me')->assertOk()->json('data.0.from');
        expect($from)->toMatchArray(['name' => 'Ana', 'avatar_version' => $anaPic['avatar_version']])->and($from['avatar_url'])->toContain('/api/avatars/'.$ana->id.'/');
        $to = $this->asToken($anaToken)->getJson("/api/sent?session_id={$session->id}")->assertOk()->json('data.0.to');
        expect($to)->toMatchArray(['name' => 'Omar', 'avatar_version' => $omarPic['avatar_version']]);
    });

    it('erases the stored copies with the account', function () {
        [, $token] = $this->enroll($user = Fx::user());
        $version = profUpload($this, $token, profPicture(300, 300))->assertOk()->json('avatar_version');

        app(AccountEraser::class)->erase($user->fresh());
        foreach (Avatars::paths($user->id, $version) as $path) {
            expect(app(RecordingFiles::class)->exists($path))->toBeFalse();
        }
    });
});

describe('email change', function () {
    it('sends a code and the console\'s link to the new address and the notice to the old, then the code confirms', function () {
        config(['session.driver' => 'database']);
        $user = Fx::user(['email' => 'farah@example.test']);
        [$device, $token] = $this->enroll($user);
        [$other] = $this->enroll($user, null, 'windows');
        DB::table('sessions')->insert(['id' => 'browser-1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);

        $pending = $this->asToken($token)->postJson('/api/account/email', ['email' => ' New.Farah@Example.TEST ', 'password' => 'password'])->assertStatus(202)->json();
        expect($pending['pending_email'])->toBe('new.farah@example.test')
            ->and($pending['pending_email_expires_at'])->toBe(now()->addMinutes(60)->toAtomString());
        $mail = Mail::queued(EmailCodeMail::class)->last();
        expect($mail->hasTo('new.farah@example.test'))->toBeTrue()->and($mail->purpose)->toBe('email_change')->and($mail->code)->toMatch('/^\d{6}$/')
            ->and($mail->url)->toContain('/email-change-verification/')->and($mail->render())->toContain($mail->code)->toContain('Confirm this address');
        Notification::assertSentTo($user, NoticeOfEmailChangeRequest::class, fn (NoticeOfEmailChangeRequest $n): bool => $n->newEmail === 'new.farah@example.test');
        $this->asToken($token)->getJson('/api/account')->assertJsonPath('pending_email', 'new.farah@example.test')->assertJsonPath('email', 'farah@example.test');
        expect(AuditEvent::query()->where('event', 'email_change_requested')->value('summary'))->toBe('Email change asked for: n…@example.test');

        $this->asToken($token)->postJson('/api/account/email/confirm', ['code' => $mail->code])->assertOk()
            ->assertJsonPath('email', 'new.farah@example.test')->assertJsonPath('pending_email', null)->assertJsonPath('email_verified', true);
        expect($user->fresh()->email)->toBe('new.farah@example.test')->and($user->fresh()->hasVerifiedEmail())->toBeTrue()
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
            ->and(Device::query()->find($other->id)->signed_out_at)->toBeNull()
            ->and(AuditEvent::query()->where('event', 'email_changed')->value('summary'))->toBe('Email changed to n…@example.test');
        $this->asToken($token)->getJson('/api/account')->assertOk();
        // Used once.
        $this->asToken($token)->postJson('/api/account/email/confirm', ['code' => $mail->code])->assertStatus(410)->assertJsonPath('code', 'expired');
    });

    it('needs proof, and answers email_taken, same_as_now and invalid_email', function () {
        Fx::user(['email' => 'taken@example.test']);
        $user = Fx::user(['email' => 'farah@example.test']);
        [, $token] = $this->enroll($user);

        $this->asToken($token)->postJson('/api/account/email', ['email' => 'new@example.test'])->assertStatus(422)->assertJsonPath('code', 'proof_required');
        $this->asToken($token)->postJson('/api/account/email', ['email' => 'new@example.test', 'password' => 'wrong-password'])->assertStatus(422)->assertJsonPath('code', 'wrong_password');
        $this->asToken($token)->postJson('/api/account/email', ['email' => 'TAKEN@example.test', 'password' => 'password'])->assertStatus(422)->assertJsonPath('code', 'email_taken');
        $this->travel(61)->minutes(); // past the 3 an hour
        $this->asToken($token)->postJson('/api/account/email', ['email' => 'Farah@Example.test', 'password' => 'password'])->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')->assertJsonPath('error_codes.email', [['code' => 'same_as_now']]);
        $this->asToken($token)->postJson('/api/account/email', ['email' => 'not an address', 'password' => 'password'])->assertStatus(422)
            ->assertJsonPath('error_codes.email', [['code' => 'invalid_email']]);
        $this->travel(61)->minutes();
        $this->asToken($token)->postJson('/api/account/email', ['password' => 'password'])->assertStatus(422)->assertJsonPath('error_codes.email', [['code' => 'required']]);
        Mail::assertNotQueued(EmailCodeMail::class);
    });

    it('ends the change at the fifth wrong code, then answers 410 expired', function () {
        [, $token] = $this->enroll(Fx::user());
        $this->asToken($token)->postJson('/api/account/email', ['email' => 'new@example.test', 'password' => 'password'])->assertStatus(202);
        $code = profCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        foreach (range(1, 5) as $_) {
            $this->asToken($token)->postJson('/api/account/email/confirm', ['code' => $wrong])->assertStatus(422)->assertJsonPath('code', 'invalid_code');
        }
        $this->asToken($token)->postJson('/api/account/email/confirm', ['code' => $code])->assertStatus(429);
        $this->travel(61)->minutes();
        $this->asToken($token)->postJson('/api/account/email', ['email' => 'new@example.test', 'password' => 'password'])->assertStatus(202);
        $this->asToken($token)->postJson('/api/account/email/confirm', ['code' => $code === profCode() ? $wrong : $code])->assertStatus(422);
    });

    it('answers 410 expired once the hour is over, and DELETE cancels', function () {
        [, $token] = $this->enroll($user = Fx::user());
        $this->asToken($token)->postJson('/api/account/email', ['email' => 'new@example.test', 'password' => 'password'])->assertStatus(202);
        $this->travel(61)->minutes();
        $this->asToken($token)->postJson('/api/account/email/confirm', ['code' => profCode()])->assertStatus(410)->assertJsonPath('code', 'expired');
        $this->asToken($token)->getJson('/api/account')->assertJsonPath('pending_email', null);

        $this->asToken($token)->postJson('/api/account/email', ['email' => 'other@example.test', 'password' => 'password'])->assertStatus(202);
        $this->asToken($token)->deleteJson('/api/account/email')->assertNoContent();
        expect(AuditEvent::query()->where('event', 'email_change_cancelled')->where('user_id', $user->id)->count())->toBe(1);
        $this->asToken($token)->postJson('/api/account/email/confirm', ['code' => profCode()])->assertStatus(410);
        $this->asToken($token)->deleteJson('/api/account/email')->assertNoContent();
        expect($user->fresh()->email)->not->toBe('other@example.test');
    });

    it('notices a confirmation made by the link in a browser', function () {
        [, $token] = $this->enroll($user = Fx::user());
        $this->asToken($token)->postJson('/api/account/email', ['email' => 'linked@example.test', 'password' => 'password'])->assertStatus(202);
        $url = Mail::queued(EmailCodeMail::class)->last()->url;

        $this->actingAs($user, 'web')->get($url)->assertRedirect();
        expect($user->fresh()->email)->toBe('linked@example.test');
        $this->asToken($token)->getJson('/api/account')->assertJsonPath('pending_email', null)->assertJsonPath('email', 'linked@example.test');
        $this->asToken($token)->postJson('/api/account/email/confirm', ['code' => profCode()])->assertStatus(410);
    });

    it('ends the change and signs out everywhere on "This wasn\'t me" in the old address\'s notice', function () {
        [, $token] = $this->enroll($user = Fx::user(['email' => 'farah@example.test']));
        $this->asToken($token)->postJson('/api/account/email', ['email' => 'attacker@example.test', 'password' => 'password'])->assertStatus(202);
        $block = '';
        Notification::assertSentTo($user, NoticeOfEmailChangeRequest::class, function (NoticeOfEmailChangeRequest $n) use (&$block): bool {
            $block = $n->blockVerificationUrl;

            return true;
        });
        expect((string) Notification::sent($user, NoticeOfEmailChangeRequest::class)->first()->toMail($user)->render())->toContain('change your password');

        $this->actingAs($user, 'web')->get($block)->assertRedirect();
        expect(AuditEvent::query()->where('event', 'email_change_blocked')->value('summary'))->toBe('Email change blocked: a…@example.test')
            ->and($user->fresh()->email)->toBe('farah@example.test');
        $this->asToken($token)->getJson('/api/account')->assertUnauthorized();
        [, $again] = $this->enroll($user->fresh());
        $this->asToken($again)->postJson('/api/account/email/confirm', ['code' => profCode()])->assertStatus(410);
    });

    it('allows 3 requests and 5 code attempts an hour per person', function () {
        [, $token] = $this->enroll(Fx::user());
        foreach (range(1, 3) as $i) {
            $this->asToken($token)->postJson('/api/account/email', ['email' => "new{$i}@example.test", 'password' => 'password'])->assertStatus(202);
        }
        $this->asToken($token)->postJson('/api/account/email', ['email' => 'new4@example.test', 'password' => 'password'])->assertStatus(429)->assertJsonPath('code', 'rate_limited');

        foreach (range(1, 5) as $_) {
            $this->asToken($token)->postJson('/api/account/email/confirm', ['code' => 'abc'])->assertStatus(422);
        }
        $this->asToken($token)->postJson('/api/account/email/confirm', ['code' => profCode()])->assertStatus(429)->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After');
    });
});

describe('PUT /api/account/password', function () {
    it('changes it with the current one and signs out every other device and browser, this one staying', function () {
        config(['session.driver' => 'database']);
        $user = Fx::user();
        [, $token] = $this->enroll($user);
        [$other, $otherToken] = $this->enroll($user, null, 'windows');
        DB::table('sessions')->insert(['id' => 'browser-1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);

        $this->asToken($token)->putJson('/api/account/password', ['current' => 'password', 'new' => 'a-new-password-42'])->assertNoContent();
        expect(Hash::check('a-new-password-42', $user->fresh()->password))->toBeTrue()
            ->and($other->fresh()->signed_out_at)->not->toBeNull()
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
            ->and(AuditEvent::query()->where('event', 'password_changed')->where('user_id', $user->id)->exists())->toBeTrue();
        $this->asToken($token)->getJson('/api/account')->assertOk();
        $this->asToken($otherToken)->getJson('/api/account')->assertUnauthorized();
    });

    it('keeps the other devices when sign_out_others is false', function () {
        $user = Fx::user();
        [, $token] = $this->enroll($user);
        [$other, $otherToken] = $this->enroll($user, null, 'windows');

        $this->asToken($token)->putJson('/api/account/password', ['current' => 'password', 'new' => 'a-new-password-42', 'sign_out_others' => false])->assertNoContent();
        expect($other->fresh()->signed_out_at)->toBeNull();
        $this->asToken($otherToken)->getJson('/api/account')->assertOk();
    });

    it('answers wrong_password, counted by SignInGuard, and each rule of a new password with its code', function () {
        $user = Fx::user();
        [, $token] = $this->enroll($user);

        $this->asToken($token)->putJson('/api/account/password', ['new' => 'a-new-password-42'])->assertStatus(422)->assertJsonPath('error_codes.current', [['code' => 'required']]);
        $this->asToken($token)->putJson('/api/account/password', ['current' => 'nope', 'new' => 'a-new-password-42'])->assertStatus(422)->assertJsonPath('code', 'wrong_password');
        $this->asToken($token)->putJson('/api/account/password', ['current' => 'password', 'new' => 'short1'])->assertStatus(422)
            ->assertJsonPath('error_codes.new', [['code' => 'too_short', 'min' => 12]]);
        $this->asToken($token)->putJson('/api/account/password', ['current' => 'password', 'new' => '123456789012'])->assertStatus(422)
            ->assertJsonPath('error_codes.new', [['code' => 'needs_letters']]);
        $this->asToken($token)->putJson('/api/account/password', ['current' => 'password', 'new' => 'abcdefghijkl'])->assertStatus(422)
            ->assertJsonPath('error_codes.new', [['code' => 'needs_numbers']]);
        $this->travel(61)->minutes(); // past the 5 an hour
        $this->asToken($token)->putJson('/api/account/password', ['current' => 'password', 'new' => 'abc'])->assertStatus(422)
            ->assertJsonPath('error_codes.new', [['code' => 'too_short', 'min' => 12], ['code' => 'needs_numbers']])->assertJsonPath('errors.new.0', 'Use at least 12 characters.');
        $this->asToken($token)->putJson('/api/account/password', ['current' => 'password', 'new' => ''])->assertStatus(422)->assertJsonPath('error_codes.new', [['code' => 'required']]);

        $user->forceFill(['password' => 'same-password-42'])->save();
        $this->asToken($token)->putJson('/api/account/password', ['current' => 'same-password-42', 'new' => 'same-password-42'])->assertStatus(422)
            ->assertJsonPath('error_codes.new', [['code' => 'same_as_current']]);
        // A wrong current password never says whether the new one is the current one.
        $this->asToken($token)->putJson('/api/account/password', ['current' => 'wrong-guess-1', 'new' => 'same-password-42'])->assertStatus(422)->assertJsonPath('code', 'wrong_password');
    });

    it('locks after 5 wrong current passwords in 15 minutes (429 too_many_attempts), like a sign-in', function () {
        $user = Fx::user();
        [, $token] = $this->enroll($user);
        foreach (range(1, 4) as $i) {
            $this->asToken($token)->putJson('/api/account/password', ['current' => "wrong-{$i}", 'new' => 'a-new-password-42'])->assertStatus(422);
        }
        // The console's sign-in shares the count.
        $this->postJson('/api/auth/password', ['email' => $user->email, 'password' => 'wrong-5', 'name' => 'x', 'platform' => 'android'])->assertStatus(422);

        $this->asToken($token)->putJson('/api/account/password', ['current' => 'password', 'new' => 'a-new-password-42'])->assertStatus(429)
            ->assertJsonPath('code', 'too_many_attempts')->assertHeader('Retry-After');
    });

    it('lets an account made with Google set its first password with an email code', function () {
        $user = Fx::user();
        $user->forceFill(['has_password' => false, 'google_id' => 'google-sub-1'])->save();
        [, $token] = $this->enroll($user);

        $this->asToken($token)->putJson('/api/account/password', ['new' => 'a-first-password-42'])->assertStatus(422)->assertJsonPath('code', 'proof_required')
            ->assertJsonPath('accepts', ['email_code']);
        $this->asToken($token)->putJson('/api/account/password', ['current' => 'password', 'new' => 'a-first-password-42'])->assertStatus(422)->assertJsonPath('code', 'proof_required');
        $this->asToken($token)->postJson('/api/account/proof-code')->assertStatus(202);
        Mail::assertQueued(EmailCodeMail::class, fn (EmailCodeMail $m): bool => $m->purpose === 'confirm' && $m->hasTo($user->email));
        $this->asToken($token)->putJson('/api/account/password', ['email_code' => profCode() === '000000' ? '111111' : '000000', 'new' => 'a-first-password-42'])->assertStatus(422)->assertJsonPath('code', 'invalid_code');
        $this->asToken($token)->putJson('/api/account/password', ['email_code' => profCode(), 'new' => 'a-first-password-42'])->assertNoContent();

        expect($user->fresh()->has_password)->toBeTrue()->and(Hash::check('a-first-password-42', $user->fresh()->password))->toBeTrue();
        $this->asToken($token)->getJson('/api/account')->assertJsonPath('has_password', true);
    });

    it('allows 5 an hour per person', function () {
        [, $token] = $this->enroll(Fx::user());
        foreach (range(1, 5) as $_) {
            $this->asToken($token)->putJson('/api/account/password', ['current' => 'password', 'new' => 'short'])->assertStatus(422);
        }
        $this->asToken($token)->putJson('/api/account/password', ['current' => 'password', 'new' => 'a-new-password-42'])->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    });

    it('offers the rules in GET /api/auth/options', function () {
        $this->getJson('/api/auth/options')->assertOk()->assertJsonPath('password_rules', ['min' => 12, 'letters' => true, 'numbers' => true]);
    });
});

it('never writes a password, a code or picture bytes to the log or the audit log, nor a new address in full', function () {
    $lines = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$lines): void {
        $lines[] = $e->message.' '.json_encode($e->context);
    });
    $user = Fx::user(['email' => 'farah@example.test']);
    [, $token] = $this->enroll($user);
    $picture = profJpegWithExif('SECRETGPS-1');

    profUpload($this, $token, $picture)->assertOk();
    profUpload($this, $token, profPngHeader(9000, 9000))->assertStatus(422);
    $this->asToken($token)->postJson('/api/account/email', ['email' => 'hidden.address@example.test', 'password' => 'password'])->assertStatus(202);
    $code = profCode();
    $this->asToken($token)->postJson('/api/account/email/confirm', ['code' => $code === '999999' ? '888888' : '999999'])->assertStatus(422);
    $this->asToken($token)->postJson('/api/account/email/confirm', ['code' => $code])->assertOk();
    $this->asToken($token)->putJson('/api/account/password', ['current' => 'wrong-secret-pass', 'new' => 'brand-new-secret-77'])->assertStatus(422);
    $this->asToken($token)->putJson('/api/account/password', ['current' => 'password', 'new' => 'brand-new-secret-77'])->assertNoContent();

    $audit = AuditEvent::query()->get()->map(fn (AuditEvent $e): string => $e->summary.' '.json_encode($e->meta))->all();
    foreach ([...$lines, ...$audit] as $line) {
        foreach (['brand-new-secret-77', 'wrong-secret-pass', $code, 'SECRETGPS', 'hidden.address@example.test', 'RIFF', 'JFIF'] as $secret) {
            expect(str_contains($line, $secret))->toBeFalse("Found {$secret} in: {$line}");
        }
    }
});
