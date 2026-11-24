<?php

declare(strict_types=1);

use App\Enums\TranscriptErrorCode;
use App\Filament\Support\Notice;
use App\Livewire\ActivityBell;
use App\Models\MissedRecording;
use App\Services\ActivityFeed;
use Filament\Facades\Filament;
use Illuminate\Notifications\DatabaseNotification;

/*
 * W-218: Activity's notifications are stored in English, so in an Urdu console they read in English with their full
 * stops at the wrong end. Since then each keeps its line (the English words as lang/ur.json knows them, and the names
 * and titles beside) and is shown in the reader's language; the apps and the stored words stay English, and a
 * notification made before shows as it was stored.
 */

require_once __DIR__.'/Support/parity_world.php';

const W218_FSI = "\u{2068}";

const W218_PDI = "\u{2069}";

beforeEach(function () {
    [$this->owner] = webParityWorld();
    webParityDesign(true);
    Filament::setCurrentPanel('app');
});

afterEach(fn () => app()->setLocale('en'));

/** The Activity page in [$language], as its words (markup and direction marks out). */
function w218Activity(object $test, string $language): string
{
    $prefs = is_array($test->owner->console_preferences) ? $test->owner->console_preferences : [];
    $prefs['shell'] = ['app_language' => $language];
    $test->owner->forceFill(['console_preferences' => $prefs])->save();
    app('auth')->forgetGuards();
    $html = (string) $test->actingAs($test->owner->fresh(), 'web')->get('/app/activity')->assertOk()->getContent();
    $words = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', '', $html) ?? ''))) ?? '';

    return $words;
}

function w218Post(object $test): void
{
    $feed = app(ActivityFeed::class);
    $feed->post($test->owner, 'transcript_failed', ActivityFeed::line('Transcription failed'), ActivityFeed::failedLine('Call with <b>Omar</b>', 'no_audio'), null, 'heroicon-o-exclamation-triangle');
    $feed->post($test->owner, 'upload_failed', ActivityFeed::line('Upload failed — retry'),
        ActivityFeed::line(':title did not finish uploading. The device that recorded it can send it again.', ['title' => 'Weekly sync']), null, 'heroicon-o-arrow-up-tray');
    $feed->post($test->owner, 'budget_warn', ActivityFeed::line('Transcription at :percent% of the monthly limit', ['percent' => 80]),
        ActivityFeed::plural(':minutes minute used this month.|:minutes minutes used this month.', 412, ['minutes' => 412]), null, 'heroicon-o-chart-bar');
}

it('shows the notifications in Urdu, with the names isolated and the full stop at the end of the sentence', function () {
    w218Post($this);

    $words = w218Activity($this, 'ur');
    expect($words)->toContain('ٹرانسکرپشن نہیں ہو سکی')
        ->toContain(W218_FSI.'Call with <b>Omar</b>'.W218_PDI.': '.W218_FSI.'اس ریکارڈنگ میں کچھ سنائی نہیں دیا۔'.W218_PDI)
        ->toContain(W218_FSI.'Weekly sync'.W218_PDI.' کی اپ لوڈ مکمل نہیں ہوئی۔ جس ڈیوائس نے اسے ریکارڈ کیا وہ اسے دوبارہ بھیج سکتی ہے۔')
        ->toContain('ٹرانسکرپشن ماہانہ حد کے '.W218_FSI.'80'.W218_PDI.'% پر')
        ->toContain('اس مہینے '.W218_FSI.'412'.W218_PDI.' منٹ استعمال ہوئے۔')
        ->not->toContain('did not finish uploading')->not->toContain('minutes used this month')->not->toContain('Nothing could be heard');
});

it('reads as before in English, and the stored words and the apps stay English', function () {
    w218Post($this);

    expect(w218Activity($this, 'en'))->toContain('Transcription failed')->toContain('Call with <b>Omar</b>: Nothing could be heard in this recording.')
        ->toContain('Weekly sync did not finish uploading. The device that recorded it can send it again.')
        ->toContain('Transcription at 80% of the monthly limit')->toContain('412 minutes used this month.');

    // Stored escaped and in English whatever the console's language, as before.
    $failed = $this->owner->notifications()->get()->first(fn (DatabaseNotification $n): bool => ActivityFeed::kindOf($n) === 'transcript_failed' && str_contains((string) $n->data['body'], 'Omar'));
    expect($failed->data['title'])->toBe('Transcription failed')
        ->and($failed->data['body'])->toBe('Call with &lt;b&gt;Omar&lt;/b&gt;: Nothing could be heard in this recording.')
        ->and($failed->data['body'])->toBe(e(ActivityFeed::failedBody('Call with <b>Omar</b>', 'no_audio')));

    app()->setLocale('ur');
    expect(ActivityFeed::payload($failed))->toMatchArray(['title' => 'Transcription failed', 'body' => 'Call with <b>Omar</b>: Nothing could be heard in this recording.']);
});

it('stores English even when the event happens during an Urdu request', function () {
    app()->setLocale('ur');
    w218Post($this);
    app()->setLocale('en');

    expect($this->owner->notifications()->where('created_at', '>=', now()->subMinute())->get()->filter(fn (DatabaseNotification $n): bool => isset($n->data['viewData']['lines']))->pluck('data')->map(fn (array $d): string => $d['title'].' '.$d['body'])->implode(' '))
        ->not->toMatch('/[\x{0600}-\x{06FF}\x{2066}-\x{2069}]/u');
});

it('keeps notifications made before readable: their stored words, in any language', function () {
    $this->owner->notifyNow((Notice::make()->title(e('Old <title>'))->body(e('Made before W-218.'))
        ->viewData(['kind' => 'transcript_ready', 'session_id' => null, 'escaped' => true]))->toDatabase());

    expect(w218Activity($this, 'ur'))->toContain('Old <title>')->toContain('Made before W-218.');
    $old = $this->owner->notifications()->get()->first(fn (DatabaseNotification $n): bool => str_contains((string) $n->data['title'], 'Old'));
    app()->setLocale('ur');
    expect(ActivityFeed::localized($old))->toBe(['title' => 'Old <title>', 'body' => 'Made before W-218.']);
});

it('shows the bell\'s cards in the reader\'s language, escaped', function () {
    w218Post($this);
    $this->actingAs($this->owner, 'web');
    app()->setLocale('ur');

    $failed = $this->owner->notifications()->get()->first(fn (DatabaseNotification $n): bool => ActivityFeed::kindOf($n) === 'transcript_failed' && str_contains((string) $n->data['body'], 'Omar'));
    $card = (new ActivityBell)->getNotification($failed);
    expect($card->getTitle())->toBe('ٹرانسکرپشن نہیں ہو سکی')
        ->and($card->getBody())->toContain('Call with &lt;b&gt;Omar&lt;/b&gt;')->toContain('اس ریکارڈنگ میں کچھ سنائی نہیں دیا۔')->not->toContain('<b>');
});

it('says a missed recording in Urdu, and in English as before', function () {
    $missed = new MissedRecording(['source_app' => 'whatsapp', 'call_duration_s' => 312, 'reason' => 'permission']);
    expect($missed->activityBody())->toBe('WhatsApp call · 5:12 · A permission was off');

    app()->setLocale('ur');
    expect(preg_replace('/[\x{2066}-\x{2069}]/u', '', ActivityFeed::text($missed->activityLine())))->toBe('واٹس ایپ کال · 5:12 · ایک اجازت بند تھی')
        ->and($missed->activityBody())->toBe('WhatsApp call · 5:12 · A permission was off');
    $browser = new MissedRecording(['source_app' => MissedRecording::BROWSER, 'call_duration_s' => null, 'reason' => 'nope']);
    expect(preg_replace('/[\x{2066}-\x{2069}]/u', '', ActivityFeed::text($browser->activityLine())))->toBe('براؤزر میں کال · وجہ معلوم نہیں');
});

it('says one minute and many minutes, in English and in Urdu', function () {
    $one = ActivityFeed::plural(':minutes minute used this month.|:minutes minutes used this month.', 1, ['minutes' => 1]);
    $many = ActivityFeed::plural(':minutes minute used this month.|:minutes minutes used this month.', 412, ['minutes' => 412]);
    expect(ActivityFeed::text($one, 'en'))->toBe('1 minute used this month.')->and(ActivityFeed::text($many, 'en'))->toBe('412 minutes used this month.');

    app()->setLocale('ur');
    $bare = fn (string $t): string => (string) preg_replace('/[\x{2066}-\x{2069}]/u', '', $t);
    expect($bare(ActivityFeed::text($one)))->toBe('اس مہینے 1 منٹ استعمال ہوا۔')->and($bare(ActivityFeed::text($many)))->toBe('اس مہینے 412 منٹ استعمال ہوئے۔')
        // A notification stored before the plural still reads (its key stays in lang/ur.json).
        ->and($bare(ActivityFeed::text(ActivityFeed::line(':minutes minutes used this month.', ['minutes' => 5]))))->toBe('اس مہینے 5 منٹ استعمال ہوئے۔')
        ->and($bare(ActivityFeed::text(ActivityFeed::line(':name sent you :title', ['name' => 'Ana', 'title' => 'Call']))))->toBe('Ana نے آپ کو Call بھیجا');
});

it('has Urdu for every line the feed writes, read from the code itself', function () {
    // Every literal line in app/ (ActivityFeed::line, ::plural and self::line), so a new one cannot be missed.
    $code = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $code .= (string) file_get_contents($file->getPathname());
        }
    }
    preg_match_all("/(?:ActivityFeed|self)::(?:line|plural)\\('((?:[^'\\\\]|\\\\.)+)'/", $code, $m);
    $lines = array_values(array_unique(array_map('stripslashes', $m[1])));
    expect($lines)->toContain(':title: :reason')->toContain(':call · :length · :reason')->toContain('Call')->toContain(':name signed in');

    $lines = [...$lines,
        // Chosen at run time.
        'Storage of your plan used up', 'AI credits of your plan used up', 'Storage at :percent% of your plan', 'AI credits at :percent% of your plan',
        'New recordings stay on the device until there is room or the plan is bigger.', 'Project notes and Ask wait until next month or a bigger plan.',
        'Phone call', 'Voice note',
        ...array_values(MissedRecording::REASONS),
        ...array_map(fn (TranscriptErrorCode $c): string => TranscriptErrorCode::plainWords($c), TranscriptErrorCode::cases()),
    ];
    $urdu = (array) json_decode((string) file_get_contents(base_path('lang/ur.json')), true);
    expect(array_values(array_filter($lines, fn (string $line): bool => ! isset($urdu[$line]))))->toBe([]);
    foreach ($lines as $line) {
        // Urdu ends a sentence with ۔, never with a Latin full stop.
        foreach (explode('|', (string) $urdu[$line]) as $form) {
            expect(str_ends_with($form, '.'))->toBeFalse("{$line} ends with a Latin full stop in Urdu");
        }
    }
});
