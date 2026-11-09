<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Filament\Support\WhatsAppActions;
use App\Models\CaptureSession;
use App\Models\ShareLink;
use App\Models\Workspace;
use App\Services\Sharing\WhatsAppMessage;
use App\Services\Views\TranscriptViews;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Admin\Support\AdminTestHelpers;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(AdminTestHelpers::class);

/* Send to WhatsApp on the console (since v1.6, P5.5): Text only, Text and voice, Link (7 days), as on the desktop. */

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = $this->signInAsAdmin();
});

/** A two-person call with its Roman Urdu view made. */
function whatsAppCall(object $test, bool $audio = true): CaptureSession
{
    $session = $test->sessionWithTranscript($test->admin, [
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'آپ کیسے ہیں'],
        ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 1000, 'end_ms' => 2000, 'text' => 'میں ٹھیک ہوں'],
    ], ['title' => 'Laptop call', 'language' => 'ur'], 'final', ['language' => 'ur']);
    $final = $session->finalTranscript;
    $final->forceFill(['meta' => ['views' => ['status' => 'ready', 'fingerprint' => TranscriptViews::fingerprint($final), 'model' => 'claude-sonnet-5-5', 'made_at' => now()->toAtomString(),
        'kept' => [], 'roman' => ['0' => 'aap kaise hain', '1' => 'main theek hoon'], 'urdu' => [], 'english' => []]]])->save();
    if ($audio) {
        $test->readyRecording($session);
    }

    return $session->fresh();
}

it('builds the wa.me address as the desktop does: encoded, whole when it fits, cut at a word with … when not', function () {
    expect(WhatsAppMessage::url("  Me: hello\nClient: hi  "))->toBe(['url' => 'https://wa.me/?text=Me%3A%20hello%0AClient%3A%20hi', 'cut' => false])
        ->and(WhatsAppMessage::url('Kick-off notes_1.~')['url'])->toEndWith('Kick-off%20notes_1.~')
        ->and(WhatsAppMessage::url('a&b=c?d#e+f')['url'])->toEndWith('a%26b%3Dc%3Fd%23e%2Bf')
        ->and(WhatsAppMessage::url('سلام')['url'])->toEndWith('%D8%B3%D9%84%D8%A7%D9%85');

    $long = WhatsAppMessage::url(str_repeat('word ', 1000));
    expect($long['cut'])->toBeTrue()->and(strlen($long['url']))->toBeLessThanOrEqual(WhatsAppMessage::MAX_URL_CHARS)
        ->and($long['url'])->toEndWith('word%E2%80%A6');

    $text = str_repeat('یہ ایک لمبی بات ہے ', 200);
    $urdu = WhatsAppMessage::url($text);
    $decoded = rawurldecode(substr($urdu['url'], strlen(WhatsAppMessage::BASE)));
    $head = mb_substr($decoded, 0, -1);
    // Never inside a letter or a word: what is sent is the text's start up to a space, then "…".
    expect($urdu['cut'])->toBeTrue()->and(strlen($urdu['url']))->toBeLessThanOrEqual(WhatsAppMessage::MAX_URL_CHARS)
        ->and(mb_check_encoding($decoded, 'UTF-8'))->toBeTrue()->and($decoded)->toEndWith('…')
        ->and(str_starts_with($text, $head))->toBeTrue()->and(mb_substr($text, mb_strlen($head), 1))->toBe(' ');
});

it('puts the title above a link', function () {
    expect(WhatsAppMessage::linkMessage('Laptop call', 'https://x.test/s/abc'))->toBe("Laptop call\nhttps://x.test/s/abc")
        ->and(WhatsAppMessage::linkMessage('  ', 'https://x.test/s/abc'))->toBe('https://x.test/s/abc');
});

it('sends the text of the view being read, with the speakers when more than one speaks', function () {
    $session = whatsAppCall($this);

    expect(WhatsAppMessage::transcriptText($session, null))->toBe("Me: آپ کیسے ہیں\nClient: میں ٹھیک ہوں")
        ->and(WhatsAppMessage::transcriptText($session, 'roman'))->toBe("Me: aap kaise hain\nClient: main theek hoon")
        // A view that is not made, or no view at all, is the original words.
        ->and(WhatsAppMessage::transcriptText($session, 'english'))->toBe("Me: آپ کیسے ہیں\nClient: میں ٹھیک ہوں")
        ->and(WhatsAppMessage::transcriptText($session, 'nonsense'))->toBe("Me: آپ کیسے ہیں\nClient: میں ٹھیک ہوں");

    $one = $this->sessionWithTranscript($this->admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Buy milk']]);
    expect(WhatsAppMessage::transcriptText($one, null))->toBe('Buy milk');
});

it('offers the three ways next to Share on the recording page, in the view the player shows', function () {
    $session = whatsAppCall($this);
    $page = $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id]);
    // The recording opens in Roman Urdu (its default view when made), so that is what is read and sent; the player
    // tells the page when the person switches.
    expect($page->get('readingView'))->toBe('roman')->and($page->html())->toContain('tells_view\\u0022:true')
        ->toContain("\$wire.\$set('readingView', v, false)");

    $modal = $this->modalHtml($page->mountAction('whatsapp'));
    expect($modal)->toContain('Text only')->toContain('Text and voice')->toContain('Link (works for 7 days)')
        ->toContain(WhatsAppActions::ATTACH_NOTE)->toContain('Copy text')->toContain('Copy for AI')
        ->toContain(e(WhatsAppMessage::url("Me: aap kaise hain\nClient: main theek hoon")['url']))
        // The audio is downloaded from the console's own address (written into the panel's script).
        ->toContain(str_replace('/', '\\/', route('admin.recordings.stream', ['recording' => $session->primaryRecording->id, 'download' => 1])));

    // The person switched to the original words: that is sent instead.
    $modal = $this->modalHtml($this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])->set('readingView', 'original')->mountAction('whatsapp'));
    expect($modal)->toContain(e(WhatsAppMessage::url("Me: آپ کیسے ہیں\nClient: میں ٹھیک ہوں")['url']));
});

it('offers only the text without the audio, and only the link without a transcript', function () {
    $session = whatsAppCall($this, audio: false);
    expect($this->modalHtml($this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])->mountAction('whatsapp')))
        ->toContain('Text only')->not->toContain('Text and voice');

    $bare = CaptureSession::factory()->create(['user_id' => $this->admin->id]);
    $this->readyRecording($bare);
    expect($this->modalHtml($this->panelComponent(ViewCaptureSession::class, ['record' => $bare->id])->mountAction('whatsapp')))
        ->toContain('There is no transcript yet: only the link can be sent.')->not->toContain('Text only')->toContain('Link (works for 7 days)');
});

it('is in each Library row\'s menu, with the text of the view the recording opens in', function () {
    $session = whatsAppCall($this);
    $list = $this->panelComponent(ListCaptureSessions::class)->assertTableActionVisible('whatsapp', $session);

    expect($this->modalHtml($list->mountTableAction('whatsapp', $session)))->toContain('Text and voice')->not->toContain('Copy for AI')
        ->toContain(e(WhatsAppMessage::url("Me: aap kaise hain\nClient: main theek hoon")['url']));
});

it('makes a 7-day link anyone can open and answers its wa.me address with the title', function () {
    $session = whatsAppCall($this);

    $answer = $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])->instance()->whatsAppLink($session->id, 'roman');

    $link = ShareLink::query()->where('capture_session_id', $session->id)->sole();
    expect($link->audience)->toBe('public')->and($link->include_audio)->toBeTrue()->and($link->allow_download)->toBeFalse()
        ->and($link->view)->toBe('roman')
        ->and($link->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()))->toBeTrue()
        ->and($answer['url'])->toBe(WhatsAppMessage::url("Laptop call\n".$answer['link'])['url'])
        ->and($answer['link'])->toContain('/s/');
});

it('answers 404 when someone asks for a link to another person\'s recording, and makes none', function () {
    $theirs = Fx::session(Fx::user(), null, ['title' => 'Private call']);

    $this->panelComponent(ListCaptureSessions::class)->call('whatsAppLink', $theirs->id)->assertNotFound();
    $this->panelComponent(ListCaptureSessions::class)->call('whatsAppLink', '01JNOSUCHSESSION0000000000')->assertNotFound();

    expect(unscoped(fn () => ShareLink::query()->count()))->toBe(0);
});

it('makes no link in a read-only console', function () {
    $owner = Fx::user();
    $ws = Workspace::query()->findOrFail($owner->current_workspace_id);
    $member = withoutWorkspace(Fx::user());
    app(Workspaces::class)->addMember($ws, $member, WorkspaceRole::Member);
    $this->actingAs($member->fresh());
    $mine = Fx::session($member->fresh());

    $this->panelComponent(ListCaptureSessions::class)->call('whatsAppLink', $mine->id)->assertForbidden();
    expect(unscoped(fn () => ShareLink::query()->count()))->toBe(0);
});

it('limits links to 10 a minute per person', function () {
    $session = whatsAppCall($this);
    RateLimiter::increment('whatsapp-link:'.$this->admin->id, 60, 10);

    $answer = $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])->instance()->whatsAppLink($session->id);

    expect($answer)->toBe(['error' => 'Too many links in a minute. Try again shortly.'])
        ->and(ShareLink::query()->count())->toBe(0);
});
