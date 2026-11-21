<?php

declare(strict_types=1);

use App\Enums\SourceApp;
use App\Models\CallNote;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\SentItem;
use App\Models\Transcript;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ShareLinks;
use App\Services\Sharing\Teammates;
use App\Services\Titles\AutoTitles;
use App\Services\Titles\GenericTitles;
use App\Services\Titles\TitleSql;
use App\Services\TranscriptExporter;
use App\Services\Workspaces\Workspaces;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(InteractsWithDeviceApi::class);

/*
 * docs/API.md "Title from the person" (2.0.0-k, B10): a person's own title, then the person called ("Call with Ahmed",
 * or the number), then the automatic title, then the app's name. The same title everywhere the API shows one.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll();
    $this->asToken($this->token);
});

function titled(object $test, array $state): CaptureSession
{
    return CaptureSession::factory()->create(['user_id' => $test->device->user_id, 'source_app' => SourceApp::WhatsApp, ...$state]);
}

it('puts the person called before an automatic or an app\'s generic title, and a person\'s own title first', function () {
    $cases = [
        'person typed' => [['title' => 'Rent for March', 'title_auto' => false, 'caller_name' => 'Ahmed'], 'Rent for March'],
        'automatic' => [['title' => 'Laptop — 16 vs 32 GB', 'title_auto' => true, 'caller_name' => 'Ahmed'], 'Call with Ahmed'],
        'app label' => [['title' => 'WhatsApp call', 'title_auto' => false, 'caller_name' => 'Ahmed'], 'Call with Ahmed'],
        'no title' => [['title' => null, 'caller_name' => 'Ahmed'], 'Call with Ahmed'],
        'meeting' => [['title' => null, 'source_app' => SourceApp::GoogleMeet, 'caller_name' => 'Weekly sync', 'caller_source' => 'calendar'], 'Weekly sync'],
        'number only' => [['title' => null, 'caller_number' => '+92 300 1234567'], 'Call with +92 300 1234567'],
        'generic name' => [['title' => null, 'caller_name' => 'WhatsApp', 'caller_number' => '+92 321 7654321'], 'Call with +92 321 7654321'],
        'unknown name' => [['title' => 'Budget talk', 'title_auto' => true, 'caller_name' => 'Unknown'], 'Budget talk'],
        'nobody' => [['title' => null, 'caller_name' => 'WhatsApp call'], 'WhatsApp call'],
        'voice note' => [['title' => 'Groceries', 'title_auto' => true, 'source_app' => SourceApp::VoiceNote, 'caller_name' => 'Ahmed'], 'Groceries'],
    ];
    $ids = [];
    foreach ($cases as $name => [$state, $expected]) {
        $ids[$name] = [titled($this, $state)->id, $expected];
    }

    $items = collect($this->getJson('/api/library?limit=100')->assertOk()->json('data'))->keyBy('id');
    foreach ($ids as $name => [$id, $expected]) {
        expect($items[$id]['title'])->toBe($expected, $name)
            ->and($this->getJson("/api/sessions/{$id}")->assertOk()->json('title'))->toBe($expected, $name);
    }
});

it('makes no automatic title where a person is known, a name or a number, and one where nobody is', function () {
    expect(AutoTitles::wanted(titled($this, ['title' => null, 'caller_name' => 'Ahmed'])))->toBeFalse()
        ->and(AutoTitles::wanted(titled($this, ['title' => null, 'caller_number' => '+92 300 1234567'])))->toBeFalse()
        ->and(AutoTitles::wanted(titled($this, ['title' => 'WhatsApp call', 'caller_name' => 'WhatsApp'])))->toBeTrue()
        ->and(AutoTitles::wanted(titled($this, ['title' => null, 'source_app' => SourceApp::VoiceNote, 'caller_name' => 'Ahmed'])))->toBeTrue()
        ->and(AutoTitles::wanted(titled($this, ['title' => 'Rent for March', 'title_auto' => false])))->toBeFalse();
});

it('never lets a transcript change a title a person typed', function () {
    $session = titled($this, ['title' => 'Rent for March', 'title_auto' => false, 'caller_name' => 'Ahmed']);
    app(AutoTitles::class)->make($session);

    expect($session->fresh()->title)->toBe('Rent for March')->and($session->fresh()->title_auto)->toBeFalse()
        ->and($session->fresh()->displayTitle())->toBe('Rent for March');
});

it('switches an older recording with an automatic title once its caller is known, changing nothing stored', function () {
    $session = titled($this, ['title' => 'Laptop — 16 vs 32 GB', 'title_auto' => true]);
    expect($session->displayTitle())->toBe('Laptop — 16 vs 32 GB');

    $session->forceFill(['caller_name' => 'Ahmed'])->save();

    expect($session->fresh()->displayTitle())->toBe('Call with Ahmed')
        ->and($session->fresh()->title)->toBe('Laptop — 16 vs 32 GB');
});

it('names the person in what a teammate is sent and in search', function () {
    $mate = User::factory()->create();
    app(Workspaces::class)->addMember(Workspace::query()->findOrFail($this->device->workspace_id), $mate);
    $session = titled($this, ['title' => 'Laptop — 16 vs 32 GB', 'title_auto' => true, 'caller_name' => 'Ahmed']);
    $session->transcripts()->create(['kind' => 'final', 'provider' => 'assemblyai', 'status' => 'completed', 'text' => 'the retainer fee is due']);

    app(Teammates::class)->send(User::query()->findOrFail($this->device->user_id), $session, [$mate->email], null);
    app('auth')->forgetGuards();

    expect(SentItem::query()->withoutGlobalScopes()->latest('id')->value('title'))->toBe('Call with Ahmed')
        ->and(collect($this->getJson('/api/search?q=retainer')->assertOk()->json('data'))->first()['session']['title'])->toBe('Call with Ahmed');
});

it('reads an app\'s label or an unknown caller as nobody, and a real name as someone', function () {
    foreach (['', 'WhatsApp', 'whatsapp call', 'WhatsApp Business', 'Zoom', 'Unknown', 'Private number', 'Phone call'] as $nobody) {
        expect(GenericTitles::namesNobody($nobody))->toBeTrue($nobody);
    }
    foreach (['Ahmed', 'Farah Khan', 'Acme Holdings', 'احمد'] as $someone) {
        expect(GenericTitles::namesNobody($someone))->toBeFalse($someone);
    }
});

it('gives the same title with a recording\'s call notes', function () {
    $project = Client::factory()->create(['workspace_id' => $this->device->workspace_id, 'user_id' => $this->device->user_id]);
    $call = titled($this, ['title' => 'WhatsApp call', 'caller_name' => 'Ahmed', 'client_id' => $project->id, 'workspace_id' => $this->device->workspace_id]);
    CallNote::query()->create(['workspace_id' => $call->workspace_id, 'client_id' => $project->id, 'capture_session_id' => $call->id, 'status' => 'ready', 'language' => 'en']);

    expect($this->getJson("/api/sessions/{$call->id}/notes")->assertOk()->json('data.session.title'))->toBe('Call with Ahmed');
});

describe('the review of bb2bfd13', function () {
    it('lets a rename win, even to a generic word, and makes no automatic title over it', function () {
        $call = titled($this, ['title' => 'Budget', 'title_auto' => true, 'caller_name' => 'Ahmed']);

        $this->patchJson("/api/sessions/{$call->id}", ['title' => 'WhatsApp call'])->assertOk();

        $item = collect($this->getJson('/api/library')->json('data'))->firstWhere('id', $call->id);
        expect($item['title'])->toBe('WhatsApp call')->and($item['title_auto'])->toBeFalse()
            ->and(AutoTitles::wanted($call->fresh()))->toBeFalse();
        // Clearing it gives the person called again.
        $this->patchJson("/api/sessions/{$call->id}", ['title' => ''])->assertOk();
        expect(collect($this->getJson('/api/library')->json('data'))->firstWhere('id', $call->id))
            ->toMatchArray(['title' => 'Call with Ahmed', 'title_auto' => true]);
    });

    it('says title_auto for every title the person did not choose', function () {
        $own = titled($this, ['title' => 'Rent for March', 'title_auto' => false, 'caller_name' => 'Ahmed']);
        $person = titled($this, ['title' => 'WhatsApp call', 'title_auto' => false, 'caller_number' => '+92 300 1234567']);
        $automatic = titled($this, ['title' => 'Laptop prices', 'title_auto' => true]);
        $untitled = titled($this, ['title' => null]);

        $items = collect($this->getJson('/api/library')->json('data'))->keyBy('id');
        expect($items[$own->id]['title_auto'])->toBeFalse()
            ->and($items[$person->id])->toMatchArray(['title' => 'Call with +92 300 1234567', 'title_auto' => true])
            ->and($items[$automatic->id]['title_auto'])->toBeTrue()
            ->and($items[$untitled->id]['title_auto'])->toBeTrue();
    });

    it('never shows the person called outside the account: share links, exports, WhatsApp text', function () {
        Storage::fake('recordings');
        $call = titled($this, ['title' => null, 'caller_name' => 'Ahmed Raza', 'caller_number' => '+92 300 7654321']);
        $transcript = Fx::transcript($call, 'The invoice is due.');
        ['url' => $url] = app(ShareLinks::class)->create($call, 3600, null, false, 'Ana');

        $page = (string) $this->get($url)->assertOk()->getContent();
        expect($page)->toContain('WhatsApp call')->not->toContain('7654321')->not->toContain('Ahmed Raza');
        foreach (TranscriptExporter::FORMATS as $format) {
            if ($format === 'docx') {
                continue;
            }
            $file = app(TranscriptExporter::class)->export(Transcript::query()->findOrFail($transcript->id), $format);
            expect($file['body'].$file['filename'])->not->toContain('7654321', $format)->not->toContain('Ahmed Raza', $format);
        }
        expect($call->fresh()->outsideTitle())->toBe('WhatsApp call')->and($call->fresh()->displayTitle())->toBe('Call with Ahmed Raza');
    });

    it('finds and sorts recordings by the title shown', function () {
        $ahmed = titled($this, ['title' => 'WhatsApp call', 'caller_name' => 'Ahmed']);
        $number = titled($this, ['title' => null, 'caller_number' => '+92 300 1112223']);
        $own = titled($this, ['title' => 'Zeb rent', 'title_auto' => false, 'caller_name' => 'Bilal']);
        $note = titled($this, ['title' => 'Groceries', 'title_auto' => true, 'source_app' => SourceApp::VoiceNote, 'caller_name' => 'Daud']);
        $auto = titled($this, ['title' => 'Budget talk', 'title_auto' => true]);
        $ids = [$ahmed->id, $number->id, $own->id, $note->id, $auto->id];

        $shown = fn (string $needle): array => CaptureSession::query()->whereKey($ids)
            ->where(fn ($q) => TitleSql::whereShownLike($q, '%'.$needle.'%'))->pluck('id')->sort()->values()->all();
        expect($shown('ahmed'))->toBe([$ahmed->id])
            ->and($shown('1112223'))->toBe([$number->id])
            // Bilal is not shown (the person's own title wins), nor Daud (a voice note).
            ->and($shown('bilal'))->toBe([])->and($shown('daud'))->toBe([])
            ->and($shown('zeb'))->toBe([$own->id]);
        // The apps' search finds the person called too.
        expect(collect($this->getJson('/api/search?q=Ahmed')->assertOk()->json('data'))->pluck('session.id')->all())->toBe([$ahmed->id]);

        $sorted = TitleSql::orderByShown(CaptureSession::query()->whereKey($ids))->pluck('id')->all();
        $expected = CaptureSession::query()->whereKey($ids)->get()
            ->sortBy(fn (CaptureSession $s): string => mb_strtolower($s->displayTitle()))->pluck('id')->values()->all();
        expect($sorted)->toBe($expected);
    });

    it('keeps the stored title in a teammate\'s copy, so it follows the copy\'s own rule', function () {
        Storage::fake('recordings');
        $mate = User::factory()->create();
        app(Workspaces::class)->addMember(Workspace::query()->findOrFail($this->device->workspace_id), $mate);
        $sent = CaptureSession::factory()->create(['user_id' => $mate->id, 'workspace_id' => $this->device->workspace_id,
            'source_app' => SourceApp::WhatsApp, 'title' => 'WhatsApp call', 'title_auto' => false, 'caller_name' => 'Ahmed']);
        app(Teammates::class)->send($mate, $sent, [$this->device->user->email], null);
        app('auth')->forgetGuards();
        $item = SentItem::query()->withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->postJson("/api/shared-with-me/{$item->id}/save")->assertSuccessful();

        $copy = CaptureSession::query()->where('user_id', $this->device->user_id)->where('origin', CaptureSession::ORIGIN_SAVED)->sole();
        expect($copy->title)->toBe('WhatsApp call')->and($copy->title_auto)->toBeFalse()->and($copy->title_by_person)->toBeFalse()
            ->and($copy->displayTitle())->toBe('Call with Ahmed');
    });

    it('says "Call with" in the console\'s language, and in English in the API', function () {
        $call = titled($this, ['title' => null, 'caller_name' => 'Ahmed']);
        app()->setLocale('ur');
        try {
            expect($call->displayTitle())->toContain('Ahmed')->toContain('کے ساتھ کال')->not->toContain('Call with');
        } finally {
            app()->setLocale('en');
        }
        expect($this->getJson("/api/sessions/{$call->id}")->json('title'))->toBe('Call with Ahmed');
    });
});
