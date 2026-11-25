<?php

declare(strict_types=1);

use App\Filament\Pages\Activity;
use App\Models\User;
use App\Services\ActivityFeed;
use Filament\Facades\Filament;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| P-043 (web, as D-066 on the desktop): on Activity only a notification that opens something is a button. One with
| nowhere to go (a kind with no page, a request only staff can answer) is a plain row with no hover; while it is unread it
| ends with a quiet Mark as read that marks just that one. Marking it read on view would clear a problem nobody acted on.
*/

beforeEach(function () {
    $this->person = Fx::user();
    $this->actingAs($this->person, 'web');
    Filament::setCurrentPanel('app');
});

/** The row of the item with this id: its <li>, as the page renders it. */
function activityRow(string $html, string $id): string
{
    foreach (explode('<li class="el-row-item">', $html) as $chunk) {
        if (str_contains($chunk, 'activity-'.$id.'"')) {
            return trim(explode('</li>', $chunk)[0]);
        }
    }

    return 'missing';
}

function activityNote(User $user, string $kind): DatabaseNotification
{
    return $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test',
        'data' => ['title' => "Title {$kind}", 'body' => 'Body', 'viewData' => ['kind' => $kind]]]);
}

it('makes only a notification with somewhere to go a button; one with nowhere to go has Mark as read while unread', function () {
    app(ActivityFeed::class)->post($this->person, 'transcript_ready', 'Transcript ready', 'Budget review', Fx::session($this->person), 'heroicon-o-check-circle');
    $ready = (string) $this->person->notifications()->latest()->value('id');
    $nowhere = activityNote($this->person, 'some_old_kind');
    $request = activityNote($this->person, 'upgrade_requested');
    $request->markAsRead();

    $html = Livewire::test(Activity::class)->html();
    expect(activityRow($html, $ready))->toStartWith('<button type="button" class="el-row el-row-btn" wire:click="open(\''.$ready.'\')"')
        ->not->toContain('el-text-btn');
    // Nowhere to go: a plain row, never open(); unread it has Mark as read, read nothing at all.
    expect(activityRow($html, $nowhere->id))->toStartWith('<div class="el-row el-row-btn el-row-static" wire:key="activity-'.$nowhere->id.'"')
        ->not->toContain('wire:click="open(')
        ->toContain('<button type="button" class="el-text-btn" wire:click="markRead(\''.$nowhere->id.'\')">Mark as read</button>');
    expect(activityRow($html, $request->id))->toStartWith('<div class="el-row el-row-btn el-row-static"')->not->toContain('<button');
});

it('marks just that one read, and never another person\'s', function () {
    $nowhere = activityNote($this->person, 'some_old_kind');
    $other = activityNote($this->person, 'some_old_kind');
    $theirs = activityNote(Fx::user(), 'some_old_kind');

    $page = Livewire::test(Activity::class)->call('markRead', $nowhere->id)->call('markRead', $theirs->id);
    expect($nowhere->fresh()->read_at)->not->toBeNull()
        ->and($other->fresh()->read_at)->toBeNull()
        ->and($theirs->fresh()->read_at)->toBeNull();
    expect(activityRow($page->html(), $nowhere->id))->not->toContain('<button')
        ->and(activityRow($page->html(), $other->id))->toContain('markRead(\''.$other->id.'\')');
});

it('draws the row with nowhere to go without the pointer or the hover', function () {
    $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));
    expect($css)->toMatch('/\.el-row-btn\.el-row-static\s*\{[^}]*cursor:\s*default/')
        ->toMatch('/\.el-row-btn\.el-row-static:hover\s*\{[^}]*background-color:\s*transparent/');
});
