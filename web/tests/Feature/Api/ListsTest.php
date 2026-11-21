<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\CaptureSession;
use App\Models\LabelList;
use App\Models\SessionLabel;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Lists\LabelLists;
use App\Services\Workspaces\Workspaces;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pdo\Sqlite;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
 * docs/API.md "Lists (since 2.0.0-m)": a person's lists are their labels with a name, a colour and a place in the row,
 * kept by the server so every device shows the same ones. Rename and delete change every recording of the person in
 * one transaction; another person's lists are never seen or changed.
 */

const LIST_GREY = '#8e8a80';
const LIST_RED = '#e15f56';
const LIST_ORANGE = '#dc6718';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-21 10:00:00', 'UTC'));
    $this->me = Fx::user();
    $this->token = Fx::token(Fx::device($this->me));
    $this->workspace = Workspace::query()->findOrFail($this->me->current_workspace_id);
});

/** A teammate in my workspace with a device there: [user, token]. */
function listMate(object $test, WorkspaceRole $role = WorkspaceRole::Member): array
{
    $mate = Fx::user();
    app(Workspaces::class)->addMember($test->workspace, $mate, $role);
    $mate->forceFill(['current_workspace_id' => $test->workspace->id])->save();

    return [$mate, Fx::token(Fx::device($mate, ['workspace_id' => $test->workspace->id]))];
}

/** A recording of [$owner] (in their current workspace unless given) with these labels, written straight in. */
function labelled(User $owner, array $labels, array $state = []): CaptureSession
{
    $session = CaptureSession::factory()->create(['user_id' => $owner->id, ...$state]);
    foreach ($labels as $label) {
        SessionLabel::query()->create(['capture_session_id' => $session->id, 'label' => $label]);
    }

    return $session;
}

/** @return list<string> the recording's labels as stored, whoever asks */
function labelsOf(string $id): array
{
    return SessionLabel::query()->where('capture_session_id', $id)->orderBy('id')->pluck('label')->all();
}

function listsOf(object $test, string $token): array
{
    app('auth')->forgetGuards();

    return $test->flushHeaders()->withHeaders(['Accept' => 'application/json'])->withToken($token)->getJson('/api/lists')->assertOk()->json('data');
}

describe('GET /api/lists', function () {
    it('lists mine in their order with counts, making a list for a label carried without one', function () {
        LabelList::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->me->id, 'label_key' => 'home', 'name' => 'Home', 'color' => LIST_RED, 'position' => 0]);
        LabelList::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->me->id, 'label_key' => 'clients', 'name' => 'Clients', 'color' => null, 'position' => 1]);
        labelled($this->me, ['Clients', 'Older label']);
        labelled($this->me, ['clients']);
        labelled($this->me, ['Clients'])->delete();

        expect(listsOf($this, $this->token))->toBe([
            ['key' => 'home', 'name' => 'Home', 'color' => LIST_RED, 'position' => 0, 'count' => 0],
            ['key' => 'clients', 'name' => 'Clients', 'color' => null, 'position' => 1, 'count' => 2],
            ['key' => 'older label', 'name' => 'Older label', 'color' => null, 'position' => 2, 'count' => 1],
        ]);
    });

    it('shows a person only their own lists, in this workspace', function () {
        [$mate, $mateToken] = listMate($this);
        labelled($mate, ['Their list'], ['workspace_id' => $this->workspace->id]);
        labelled(Fx::user(), ['Elsewhere']);
        labelled($this->me, ['Mine']);

        expect(array_column(listsOf($this, $this->token), 'name'))->toBe(['Mine'])
            ->and(array_column(listsOf($this, $mateToken), 'name'))->toBe(['Their list']);
    });
});

describe('POST /api/lists', function () {
    it('makes an empty list at the end, with the colour fewest lists have when none is given', function () {
        $this->api($this->token)->postJson('/api/lists', ['name' => '  Clients  ', 'color' => '#E15F56'])->assertCreated()
            ->assertExactJson(['data' => ['key' => 'clients', 'name' => 'Clients', 'color' => LIST_RED, 'position' => 0, 'count' => 0]]);
        $home = $this->api($this->token)->postJson('/api/lists', ['name' => 'Home  Life'])->assertCreated()->json('data');
        // Grey first: it is the earliest colour no list has.
        expect($home)->toBe(['key' => 'home life', 'name' => 'Home Life', 'color' => LIST_GREY, 'position' => 1, 'count' => 0]);
        // Red and grey taken: orange is next.
        expect($this->api($this->token)->postJson('/api/lists', ['name' => 'Family'])->json('data.color'))->toBe(LIST_ORANGE);

        expect(array_column(listsOf($this, $this->token), 'name'))->toBe(['Clients', 'Home Life', 'Family'])
            ->and(AuditEvent::query()->where('event', 'list_created')->where('user_id', $this->me->id)->count())->toBe(3);
    });

    it('refuses the fixed chips\' names in English and Urdu', function () {
        foreach (['All', 'unread', ' IMPORTANT ', 'سب', 'نہ پڑھی گئی', 'اہم'] as $name) {
            $this->api($this->token)->postJson('/api/lists', ['name' => $name])->assertStatus(422)->assertJson(['code' => 'name_reserved']);
        }
        expect(LabelList::query()->count())->toBe(0);
    });

    it('refuses a name another list of mine has, ignoring capitals and spaces, but not a teammate\'s', function () {
        $this->api($this->token)->postJson('/api/lists', ['name' => 'Clients'])->assertCreated();
        $this->api($this->token)->postJson('/api/lists', ['name' => '  CLIENTS '])->assertStatus(409)
            ->assertJson(['code' => 'list_exists', 'message' => 'You already have a list called Clients.', 'name' => 'Clients']);
        // A label of mine without a list yet counts too.
        labelled($this->me, ['Home Ground']);
        $this->api($this->token)->postJson('/api/lists', ['name' => ' home   ground '])->assertStatus(409)->assertJson(['name' => 'Home Ground']);

        [, $mateToken] = listMate($this);
        $this->api($mateToken)->postJson('/api/lists', ['name' => 'Clients'])->assertCreated();
    });

    it('refuses an empty name, one over 40 characters and a colour outside the eight', function () {
        $this->api($this->token)->postJson('/api/lists', ['name' => '   '])->assertStatus(422);
        $this->api($this->token)->postJson('/api/lists', [])->assertStatus(422);
        $this->api($this->token)->postJson('/api/lists', ['name' => str_repeat('x', 41)])->assertStatus(422)->assertJson(['code' => 'validation_failed']);
        $this->api($this->token)->postJson('/api/lists', ['name' => str_repeat('ب', 40)])->assertCreated();
        foreach (['#000000', 'red', '#12345'] as $bad) {
            $this->api($this->token)->postJson('/api/lists', ['name' => 'Coloured', 'color' => $bad])->assertStatus(422);
        }
        expect(LabelList::query()->count())->toBe(1);
    });
});

describe('PATCH /api/lists/{key}', function () {
    it('renames the label on every recording of mine in it, Trash too, and nobody else\'s', function () {
        [$mate] = listMate($this);
        $a = labelled($this->me, ['Clients', 'Other']);
        $b = labelled($this->me, ['clients']);
        $binned = labelled($this->me, ['CLIENTS']);
        $binned->delete();
        $theirs = labelled($mate, ['Clients'], ['workspace_id' => $this->workspace->id]);
        $before = $b->updated_at;
        $this->travel(5)->seconds();
        $this->api($this->token)->getJson('/api/lists')->assertOk();

        $this->api($this->token)->patchJson('/api/lists/clients', ['name' => 'Customers', 'color' => '#D7609C'])->assertOk()
            ->assertJson(['data' => ['key' => 'customers', 'name' => 'Customers', 'color' => '#d7609c', 'count' => 2]]);

        expect(labelsOf($a->id))->toBe(['Customers', 'Other'])
            ->and(labelsOf($b->id))->toBe(['Customers'])
            ->and(labelsOf($binned->id))->toBe(['Customers'])
            ->and(labelsOf($theirs->id))->toBe(['Clients'])
            ->and(CaptureSession::query()->withoutGlobalScopes()->findOrFail($b->id)->updated_at->gt($before))->toBeTrue()
            ->and(array_column(listsOf($this, $this->token), 'key'))->toBe(['customers', 'other'])
            ->and(AuditEvent::query()->where('event', 'list_renamed')->value('summary'))->toBe('List renamed: Clients → Customers');
        // The recordings carry its colour now.
        $item = collect($this->api($this->token)->getJson('/api/library')->json('data'))->firstWhere('id', $a->id);
        expect($item['label_colors'])->toBe(['Customers' => '#d7609c']);
    });

    it('allows a change of capitals alone, and refuses another list\'s name or a fixed chip\'s', function () {
        $session = labelled($this->me, ['clients', 'Home']);
        listsOf($this, $this->token);

        $this->api($this->token)->patchJson('/api/lists/clients', ['name' => 'Clients'])->assertOk()->assertJson(['data' => ['key' => 'clients', 'name' => 'Clients']]);
        $this->api($this->token)->patchJson('/api/lists/clients', ['name' => ' HOME '])->assertStatus(409)->assertJson(['code' => 'list_exists']);
        $this->api($this->token)->patchJson('/api/lists/clients', ['name' => 'Important'])->assertStatus(422)->assertJson(['code' => 'name_reserved']);
        $this->api($this->token)->patchJson('/api/lists/clients', ['name' => 'اہم'])->assertStatus(422)->assertJson(['code' => 'name_reserved']);
        $this->api($this->token)->patchJson('/api/lists/clients', ['color' => '#000000'])->assertStatus(422);

        expect(labelsOf($session->id))->toBe(['Clients', 'Home']);
    });

    it('changes nothing when any part of the rename fails', function () {
        $a = labelled($this->me, ['Clients']);
        $b = labelled($this->me, ['Clients']);
        listsOf($this, $this->token);
        LabelList::saving(function (): void {
            throw new RuntimeException('the disk is full');
        });
        try {
            $this->api($this->token)->patchJson('/api/lists/clients', ['name' => 'Customers'])->assertServerError();
        } finally {
            LabelList::flushEventListeners();
        }

        expect(labelsOf($a->id))->toBe(['Clients'])->and(labelsOf($b->id))->toBe(['Clients'])
            ->and(LabelList::query()->pluck('name')->all())->toBe(['Clients']);
    });

    it('finds a list by its key in any spelling, in any script, and 404s for anyone else\'s', function () {
        [$mate, $mateToken] = listMate($this);
        labelled($this->me, ['بل', 'Été']);
        labelled($mate, ['Secret'], ['workspace_id' => $this->workspace->id]);
        listsOf($this, $mateToken);
        labelled(Fx::user(), ['Elsewhere']);

        $this->api($this->token)->patchJson('/api/lists/'.rawurlencode('بل'), ['color' => LIST_RED])->assertOk()->assertJson(['data' => ['name' => 'بل', 'color' => LIST_RED]]);
        $this->api($this->token)->patchJson('/api/lists/'.rawurlencode('ÉTÉ'), ['color' => LIST_RED])->assertOk()->assertJson(['data' => ['key' => 'été']]);
        $this->api($this->token)->patchJson('/api/lists/secret', ['name' => 'Mine now'])->assertNotFound()->assertJson(['code' => 'list_not_found']);
        $this->api($this->token)->patchJson('/api/lists/elsewhere', ['name' => 'Mine now'])->assertNotFound();
        $this->api($mateToken)->deleteJson('/api/lists/'.rawurlencode('بل'))->assertNotFound();
        $this->api($this->token)->patchJson('/api/lists/nobody', ['name' => 'X'])->assertNotFound();

        expect(LabelList::query()->where('user_id', $mate->id)->pluck('name')->all())->toBe(['Secret']);
    });
});

describe('PATCH /api/lists/{key}, at scale and under contention', function () {
    it('renames in the same number of statements for a few recordings or many', function () {
        $n = null;
        DB::listen(function () use (&$n): void {
            if ($n !== null) {
                $n++;
            }
        });
        $queries = function (string $key, string $name) use (&$n): int {
            $n = 0;
            $this->api($this->token)->patchJson('/api/lists/'.$key, ['name' => $name])->assertOk();
            [$counted, $n] = [$n, null];

            return $counted;
        };
        $few = collect(range(1, 3))->map(fn () => labelled($this->me, ['Few']));
        $many = collect(range(1, 60))->map(fn () => labelled($this->me, ['Many']));
        // One of each carries the label twice in other capitals (written before labels were compared that way): it
        // keeps one.
        $fewTwin = labelled($this->me, ['Few', 'FEW']);
        $manyTwin = labelled($this->me, ['many', 'MANY']);
        listsOf($this, $this->token);
        // A first change, so what a request reads once (the person's role) is read before counting.
        $this->api($this->token)->patchJson('/api/lists/few', ['color' => LIST_RED])->assertOk();

        $forFew = $queries('few', 'Few now');
        $forMany = $queries('many', 'Many now');

        expect($forMany)->toBe($forFew)
            ->and($many->every(fn (CaptureSession $s): bool => labelsOf($s->id) === ['Many now']))->toBeTrue()
            ->and($few->every(fn (CaptureSession $s): bool => labelsOf($s->id) === ['Few now']))->toBeTrue()
            ->and(labelsOf($fewTwin->id))->toBe(['Few now'])
            ->and(labelsOf($manyTwin->id))->toBe(['Many now']);
    });

    it('answers 409 list_exists when another device takes the name in the same moment, changing nothing', function () {
        $session = labelled($this->me, ['Clients']);
        listsOf($this, $this->token);
        // The other device's list lands between the name check and the save.
        LabelList::saving(function (LabelList $list): void {
            DB::table('label_lists')->insert(['workspace_id' => $list->workspace_id, 'user_id' => $list->user_id, 'label_key' => 'customers',
                'name' => 'Customers', 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);
        });
        try {
            // Here the other device's list goes back with this transaction, so the answer names the name asked for;
            // in production it names the list the other device made.
            $this->api($this->token)->patchJson('/api/lists/clients', ['name' => 'customers'])->assertStatus(409)
                ->assertJson(['code' => 'list_exists', 'name' => 'customers', 'message' => 'You already have a list called customers.']);
        } finally {
            LabelList::flushEventListeners();
        }

        expect(labelsOf($session->id))->toBe(['Clients'])
            ->and(LabelList::query()->where('user_id', $this->me->id)->pluck('name')->all())->toBe(['Clients']);
    });

    it('answers 409, not 500, when the database counts the new name as a label the recording has ("Résumé", "Resume")', function () {
        // MySQL's utf8mb4_unicode_ci, which session_labels is under in production, ignores accents: so does this index.
        $fold = fn (string $s): string => mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($s, Normalizer::FORM_D)));
        $pdo = DB::connection()->getPdo();
        $compare = fn (string $a, string $b): int => strcmp($fold($a), $fold($b));
        $pdo instanceof Sqlite ? $pdo->createCollation('accent_ci', $compare) : $pdo->sqliteCreateCollation('accent_ci', $compare);
        DB::statement('CREATE UNIQUE INDEX session_labels_accent_ci ON session_labels (capture_session_id, label COLLATE accent_ci)');
        try {
            $session = labelled($this->me, ['Notes', 'Résumé']);
            listsOf($this, $this->token);

            $this->api($this->token)->patchJson('/api/lists/notes', ['name' => 'Resume'])->assertStatus(409)
                ->assertJson(['code' => 'list_exists', 'name' => 'Résumé']);
        } finally {
            DB::statement('DROP INDEX IF EXISTS session_labels_accent_ci');
        }

        expect(labelsOf($session->id))->toBe(['Notes', 'Résumé'])
            ->and(array_column(listsOf($this, $this->token), 'key'))->toBe(['notes', 'résumé']);
    });
});

describe('the fixed chips\' words as labels', function () {
    it('leaves a label spelt All, Unread or Important on its recording, but makes no list of it', function () {
        $old = labelled($this->me, ['Important', 'Clients', 'سب']);
        $new = CaptureSession::factory()->create(['user_id' => $this->me->id]);

        $this->api($this->token)->patchJson("/api/sessions/{$new->id}", ['labels' => ['Unread', 'اہم']])->assertOk();
        $this->api($this->token)->postJson('/api/sessions/bulk', ['ids' => [$new->id], 'action' => 'label', 'label' => 'ALL'])->assertOk();

        expect(listsOf($this, $this->token))->toBe([['key' => 'clients', 'name' => 'Clients', 'color' => null, 'position' => 0, 'count' => 1]])
            ->and(labelsOf($old->id))->toBe(['Important', 'Clients', 'سب'])
            ->and(labelsOf($new->id))->toBe(['Unread', 'اہم', 'ALL']);
        $this->api($this->token)->patchJson('/api/lists/important', ['name' => 'Priority'])->assertNotFound()->assertJson(['code' => 'list_not_found']);
        $this->api($this->token)->putJson('/api/lists/order', ['keys' => ['clients']])->assertOk();
        $this->api($this->token)->postJson('/api/lists', ['name' => 'Important'])->assertStatus(422)->assertJson(['code' => 'name_reserved']);
        expect(LabelList::query()->pluck('label_key')->all())->toBe(['clients']);
    });

    it('keeps such a label\'s colour as 2.0.0-i did', function () {
        $session = labelled($this->me, ['Important']);

        $this->api($this->token)->putJson('/api/labels/color', ['label' => 'important', 'color' => LIST_RED])->assertOk();

        $item = collect($this->api($this->token)->getJson('/api/library')->json('data'))->firstWhere('id', $session->id);
        expect($item['label_colors'])->toBe(['Important' => LIST_RED])
            ->and(collect($this->api($this->token)->getJson('/api/library/views')->json('labels'))->firstWhere('label', 'Important')['color'])->toBe(LIST_RED)
            ->and(LabelList::query()->count())->toBe(0);
        $this->api($this->token)->putJson('/api/labels/color', ['label' => 'Important', 'color' => null])->assertOk();
        expect(DB::table('label_colors')->count())->toBe(0);
    });
});

describe('DELETE /api/lists/{key}', function () {
    it('takes the label off my recordings, which stay, and closes the gap in the row', function () {
        [$mate] = listMate($this);
        $a = labelled($this->me, ['Home', 'Clients', 'Family']);
        $binned = labelled($this->me, ['clients']);
        $binned->delete();
        $theirs = labelled($mate, ['Clients'], ['workspace_id' => $this->workspace->id]);
        listsOf($this, $this->token);

        $this->api($this->token)->deleteJson('/api/lists/clients')->assertOk()->assertExactJson(['key' => 'clients', 'changed' => 2]);

        expect(labelsOf($a->id))->toBe(['Home', 'Family'])
            ->and(labelsOf($binned->id))->toBe([])
            ->and(CaptureSession::query()->withoutGlobalScopes()->withTrashed()->whereKey([$a->id, $binned->id])->count())->toBe(2)
            ->and(labelsOf($theirs->id))->toBe(['Clients'])
            ->and(listsOf($this, $this->token))->toBe([
                ['key' => 'home', 'name' => 'Home', 'color' => null, 'position' => 0, 'count' => 1],
                ['key' => 'family', 'name' => 'Family', 'color' => null, 'position' => 1, 'count' => 1],
            ])
            ->and(AuditEvent::query()->where('event', 'list_deleted')->value('summary'))->toBe('List deleted: Clients');
        $this->api($this->token)->deleteJson('/api/lists/clients')->assertNotFound();
    });

    it('deletes an empty list too', function () {
        $this->api($this->token)->postJson('/api/lists', ['name' => 'Someday'])->assertCreated();
        $this->api($this->token)->deleteJson('/api/lists/someday')->assertOk()->assertExactJson(['key' => 'someday', 'changed' => 0]);
        expect(LabelList::query()->count())->toBe(0);
    });
});

describe('PUT /api/lists/order', function () {
    it('puts the lists in the order given when it names each of mine once', function () {
        foreach (['Clients', 'Home', 'Family'] as $name) {
            $this->api($this->token)->postJson('/api/lists', ['name' => $name])->assertCreated();
        }

        $this->api($this->token)->putJson('/api/lists/order', ['keys' => ['family', 'Clients', 'home']])->assertOk();
        expect(array_column(listsOf($this, $this->token), 'key'))->toBe(['family', 'clients', 'home'])
            ->and(array_column(listsOf($this, $this->token), 'position'))->toBe([0, 1, 2]);

        // The views' labels follow the lists.
        labelled($this->me, ['Home', 'Clients', 'Family']);
        expect(array_column($this->api($this->token)->getJson('/api/library/views')->json('labels'), 'label'))->toBe(['Family', 'Clients', 'Home']);
    });

    it('moves nothing when a list is missing, named twice, or not mine', function () {
        [$mate, $mateToken] = listMate($this);
        foreach (['Clients', 'Home'] as $name) {
            $this->api($this->token)->postJson('/api/lists', ['name' => $name])->assertCreated();
        }
        $this->api($mateToken)->postJson('/api/lists', ['name' => 'Theirs'])->assertCreated();

        foreach ([['home'], ['home', 'home'], ['home', 'clients', 'theirs'], ['home', 'theirs'], []] as $keys) {
            $this->api($this->token)->putJson('/api/lists/order', ['keys' => $keys])->assertStatus(422)->assertJson(['code' => 'validation_failed']);
        }
        expect(array_column(listsOf($this, $this->token), 'key'))->toBe(['clients', 'home'])
            ->and(array_column(listsOf($this, $mateToken), 'key'))->toBe(['theirs']);
    });
});

describe('labelling a recording', function () {
    it('makes the list at the end of the row through PATCH and bulk, once', function () {
        $this->api($this->token)->postJson('/api/lists', ['name' => 'Clients', 'color' => LIST_RED])->assertCreated();
        $a = CaptureSession::factory()->create(['user_id' => $this->me->id]);
        $b = CaptureSession::factory()->create(['user_id' => $this->me->id]);

        $this->api($this->token)->patchJson("/api/sessions/{$a->id}", ['labels' => ['clients', 'Urgent']])->assertOk();
        $this->api($this->token)->postJson('/api/sessions/bulk', ['ids' => [$a->id, $b->id], 'action' => 'label', 'label' => 'Follow up'])->assertOk();
        $this->api($this->token)->postJson('/api/sessions/bulk', ['ids' => [$b->id], 'action' => 'label', 'label' => 'URGENT'])->assertOk();

        expect(listsOf($this, $this->token))->toBe([
            ['key' => 'clients', 'name' => 'Clients', 'color' => LIST_RED, 'position' => 0, 'count' => 1],
            ['key' => 'urgent', 'name' => 'Urgent', 'color' => null, 'position' => 1, 'count' => 2],
            ['key' => 'follow up', 'name' => 'Follow up', 'color' => null, 'position' => 2, 'count' => 2],
        ]);
        // Taking the label off every recording keeps the list (empty).
        $this->api($this->token)->postJson('/api/sessions/bulk', ['ids' => [$a->id, $b->id], 'action' => 'unlabel', 'label' => 'follow up'])->assertOk();
        expect(collect(listsOf($this, $this->token))->firstWhere('key', 'follow up')['count'])->toBe(0);
    });
});

describe('the row\'s places', function () {
    it('gives every new list a place of its own, putting back in order two that took the same one', function () {
        // Two devices made the person's first lists at the same moment, both at the start of the row.
        foreach (['home', 'clients'] as $key) {
            LabelList::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->me->id, 'label_key' => $key, 'name' => ucfirst($key), 'position' => 0]);
        }
        $session = CaptureSession::factory()->create(['user_id' => $this->me->id]);

        $this->api($this->token)->patchJson("/api/sessions/{$session->id}", ['labels' => ['Family', 'Work']])->assertOk();

        expect(array_map(fn (array $l): array => [$l['key'], $l['position']], listsOf($this, $this->token)))
            ->toBe([['home', 0], ['clients', 1], ['family', 2], ['work', 3]]);
    });

    it('reads the end of the row inside a transaction, with the person\'s lists locked', function () {
        LabelList::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->me->id, 'label_key' => 'home', 'name' => 'Home', 'position' => 0]);
        $locked = false;
        // SQLite has no FOR UPDATE: what is checked is that the read of the row's places is made inside a transaction.
        DB::listen(function ($q) use (&$locked): void {
            $locked = $locked || (str_contains($q->sql, 'select "label_key", "position" from "label_lists"') && DB::transactionLevel() > 0);
        });

        app(LabelLists::class)->ensure($this->workspace->id, $this->me->id, ['Work', 'home']);

        expect($locked)->toBeTrue()->and(LabelList::query()->orderBy('position')->pluck('position', 'label_key')->all())->toBe(['home' => 0, 'work' => 1]);
    });
});

describe('who may', function () {
    it('lets a viewer read their lists but change none', function () {
        [, $viewerToken] = listMate($this, WorkspaceRole::Viewer);

        $this->api($viewerToken)->getJson('/api/lists')->assertOk()->assertExactJson(['data' => []]);
        $this->api($viewerToken)->postJson('/api/lists', ['name' => 'Mine'])->assertForbidden()->assertJson(['code' => 'not_allowed']);
        $this->api($viewerToken)->putJson('/api/lists/order', ['keys' => []])->assertForbidden();
        $this->api($viewerToken)->patchJson('/api/lists/mine', ['name' => 'X'])->assertForbidden();
        $this->api($viewerToken)->deleteJson('/api/lists/mine')->assertForbidden();
        $this->api()->getJson('/api/lists')->assertUnauthorized();
        expect(LabelList::query()->count())->toBe(0);
    });
});

it('rehearses the migration: up makes a list of each label in name order with its colour, down gives the colours back', function () {
    $migration = 'database/migrations/2026_12_25_000001_create_label_lists.php';
    [$mate] = listMate($this);
    labelled($this->me, ['zebra', 'Apple', 'Important']);
    labelled($this->me, ['apple', 'Mango', 'unread'])->delete();
    labelled($mate, ['Apple'], ['workspace_id' => $this->workspace->id]);

    Artisan::call('migrate:rollback', ['--path' => $migration, '--force' => true]);
    expect(Schema::hasTable('label_lists'))->toBeFalse();
    DB::table('label_colors')->insert(['workspace_id' => $this->workspace->id, 'user_id' => $this->me->id, 'label_key' => 'apple', 'color' => '#2D9A63', 'created_at' => now(), 'updated_at' => now()]);

    Artisan::call('migrate', ['--path' => $migration, '--force' => true]);
    (require base_path($migration))->up();
    $rows = fn (User $user): array => DB::table('label_lists')->where('user_id', $user->id)->orderBy('position')->get(['label_key', 'name', 'color', 'position'])
        ->map(fn ($r): array => (array) $r)->all();
    expect($rows($this->me))->toBe([
        ['label_key' => 'apple', 'name' => 'Apple', 'color' => '#2d9a63', 'position' => 0],
        ['label_key' => 'mango', 'name' => 'Mango', 'color' => null, 'position' => 1],
        ['label_key' => 'zebra', 'name' => 'zebra', 'color' => null, 'position' => 2],
    ])->and($rows($mate))->toBe([['label_key' => 'apple', 'name' => 'Apple', 'color' => null, 'position' => 0]]);
    // A label spelt as a fixed chip is no list, and stays on its recording.
    expect(SessionLabel::query()->where('label', 'Important')->count())->toBe(1);

    // A colour chosen since, and one taken away, reach the release before.
    $this->api($this->token)->patchJson('/api/lists/zebra', ['color' => LIST_RED])->assertOk();
    DB::table('label_lists')->where('label_key', 'apple')->where('user_id', $this->me->id)->update(['color' => null]);
    Artisan::call('migrate:rollback', ['--path' => $migration, '--force' => true]);
    expect(Schema::hasTable('label_lists'))->toBeFalse()
        ->and(DB::table('label_colors')->where('user_id', $this->me->id)->pluck('color', 'label_key')->all())->toBe(['zebra' => LIST_RED]);
    Artisan::call('migrate', ['--path' => $migration, '--force' => true]);
});
